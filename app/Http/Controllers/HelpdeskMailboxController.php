<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Models\HelpdeskInboundEmail;
use App\Models\HelpdeskMailbox;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketEmailMessage;
use App\Models\TicketReply;
use App\Models\Workspace;
use App\Support\Helpdesks\Inbound\InboundMail;
use App\Support\Helpdesks\Inbound\MimeParser;
use App\Support\Helpdesks\Mail\MailboxConnector;
use App\Support\Helpdesks\Mail\MailboxException;
use App\Support\Helpdesks\Mail\MailHost;
use App\Support\Helpdesks\Mail\MailTransports;
use App\Support\Helpdesks\Mail\TicketMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Helpdesk settings → Email → "Connected mailbox" (v1.40.0, BRAI-79): JSON endpoints for
 * ImapMailboxSection. Only people who may edit the helpdesk (`helpdesks.update`). Passwords are
 * write-only: the payload says whether one is saved, never what it is.
 */
class HelpdeskMailboxController extends Controller
{
    public function show(Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        return $this->json($helpdesk);
    }

    public function update(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        $mailbox = $helpdesk->mailbox()->first();
        $data = $this->validated($request, $mailbox);

        $attributes = collect($data)->except(['imap_password', 'smtp_password'])->all();
        if (! empty($data['imap_password'])) {
            $attributes['imap_password'] = $data['imap_password'];
        }
        if (! empty($data['smtp_password'])) {
            $attributes['smtp_password'] = $data['smtp_password'];
        }
        if (($data['smtp_mode'] ?? 'app') === 'app') {
            $attributes = array_merge($attributes, ['smtp_host' => null, 'smtp_port' => null, 'smtp_encryption' => null, 'smtp_username' => null, 'smtp_password' => null]);
        }

        if ($mailbox) {
            // new settings: start polling again at once
            $mailbox->fill($attributes + ['consecutive_failures' => 0])->save();
        } else {
            $helpdesk->mailbox()->create($attributes + ['created_by' => $request->user()->id]);
        }

        return $this->json($helpdesk->fresh());
    }

    public function destroy(Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        $helpdesk->mailbox()->delete();

        return $this->json($helpdesk->fresh());
    }

    /** Log in to IMAP (and SMTP) with the form's values; saved passwords fill empty password fields. */
    public function test(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk, MailboxConnector $connector, MailTransports $transports): JsonResponse
    {
        $this->authorize('update', $helpdesk);

        $saved = $helpdesk->mailbox()->first();
        $data = $this->validated($request, $saved);
        $mailbox = new HelpdeskMailbox($data + ['helpdesk_id' => $helpdesk->id]);
        $mailbox->imap_password = $data['imap_password'] ?: $saved?->imap_password;
        $mailbox->smtp_password = ($data['smtp_password'] ?? null) ?: $saved?->smtp_password;

        try {
            $connection = $connector->open($mailbox);
            $unseen = count($connection->unseenUids(1000));
            $connection->close();
            $transports->test($mailbox);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => MailboxException::from($e)->getMessage()], 200, ['Cache-Control' => 'no-store']);
        }

        $smtp = $mailbox->smtp_mode === 'smtp' ? ' SMTP login works too.' : '';

        return response()->json([
            'ok' => true,
            'message' => "Connected to {$mailbox->imap_folder}: {$unseen} unread ".($unseen === 1 ? 'message' : 'messages').'.'.$smtp,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /** "Retry" on a reply whose email failed. */
    public function resendReply(Workspace $workspace, Project $project, Helpdesk $helpdesk, Ticket $ticket, TicketReply $reply): RedirectResponse
    {
        $this->authorize('update', $ticket);

        if ($reply->email_status !== 'failed' || ! TicketMailer::retryReply($ticket, $reply)) {
            return back()->with('error', 'This reply can\'t be sent by email.');
        }

        return back()->with($reply->fresh()->email_status === 'failed' ? 'error' : 'success', $reply->fresh()->email_status === 'failed' ? 'Sending failed again.' : 'Reply sent by email.');
    }

    /**
     * e2e only (the route exists only with APP_ENV=e2e): feed a fixture from tests/Fixtures/mail to
     * the inbound pipeline, as if the poller had fetched it.
     */
    public function e2eReceive(Request $request, Workspace $workspace, Project $project, Helpdesk $helpdesk): JsonResponse
    {
        abort_unless(app()->environment('e2e'), 404);
        $this->authorize('update', $helpdesk);

        $data = $request->validate([
            'fixture' => ['required', 'string', 'regex:/^[a-z0-9-]+$/'],
            'subject' => 'nullable|string|max:255',
            'from' => 'nullable|email|max:255',
            'message_id' => 'nullable|string|max:255|regex:/^[A-Za-z0-9._@-]+$/',
            'reply_to_ticket' => ['nullable', 'integer', Rule::exists('tickets', 'id')->where('helpdesk_id', $helpdesk->id)],
        ]);
        $path = base_path('tests/Fixtures/mail/'.$data['fixture'].'.eml');
        abort_unless(is_file($path), 404);

        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $header = function (string $name, string $value) use (&$raw): void {
            $line = $name.': '.str_replace(["\r", "\n"], '', $value);
            $raw = preg_match("/^{$name}:.*$/mi", $raw)
                ? (string) preg_replace_callback("/^{$name}:.*$/mi", fn () => $line, $raw, 1)
                : $line."\n".$raw;
        };
        if (! empty($data['subject'])) {
            $header('Subject', $data['subject']);
        }
        if (! empty($data['from'])) {
            $header('From', $data['from']);
        }
        if (! empty($data['message_id'])) {
            $header('Message-ID', '<'.$data['message_id'].'>');
        }
        if (! empty($data['reply_to_ticket'])) {
            $last = TicketEmailMessage::where('ticket_id', $data['reply_to_ticket'])->where('direction', 'out')->latest('id')->value('message_id');
            abort_unless($last, 422, 'The ticket has no outgoing email yet.');
            $header('In-Reply-To', '<'.$last.'>');
        }

        $message = MimeParser::parse($raw, 'imap');
        $result = InboundMail::receive($helpdesk, $message);
        $row = HelpdeskInboundEmail::where('helpdesk_id', $helpdesk->id)->where('message_hash', HelpdeskInboundEmail::hashOf($message->messageId))->first();

        return response()->json(['result' => $result, 'ticket_id' => $row?->ticket_id, 'ticket_key' => $row?->ticket?->key]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?HelpdeskMailbox $mailbox): array
    {
        $encryptions = MailHost::allowPrivate() ? HelpdeskMailbox::ENCRYPTIONS : ['ssl', 'starttls'];
        $folder = ['string', 'max:255', 'regex:/^[^\x00-\x1F\x7F*%]+$/u'];
        $smtp = $request->input('smtp_mode') === 'smtp';

        $data = $request->validate([
            'enabled' => 'boolean',
            'email_address' => 'required|email:rfc|max:255',
            'from_name' => 'nullable|string|max:100',
            'imap_host' => ['required', 'string', 'max:255', 'regex:'.MailHost::HOSTNAME],
            'imap_port' => ['required', 'integer', Rule::in(MailHost::ports('imap'))],
            'imap_encryption' => ['required', Rule::in($encryptions)],
            'imap_username' => 'required|string|max:255',
            'imap_password' => [$mailbox?->imap_password ? 'nullable' : 'required', 'string', 'max:1000'],
            'imap_folder' => ['required', ...$folder],
            'processed_folder' => ['nullable', ...$folder, 'different:imap_folder'],
            'smtp_mode' => ['required', Rule::in(HelpdeskMailbox::SMTP_MODES)],
            'smtp_host' => [$smtp ? 'required' : 'nullable', 'string', 'max:255', 'regex:'.MailHost::HOSTNAME],
            'smtp_port' => [$smtp ? 'required' : 'nullable', 'integer', Rule::in(MailHost::ports('smtp'))],
            'smtp_encryption' => [$smtp ? 'required' : 'nullable', Rule::in($encryptions)],
            'smtp_username' => 'nullable|string|max:255',
            'smtp_password' => 'nullable|string|max:1000',
        ], [], [
            'imap_host' => 'IMAP server', 'imap_port' => 'IMAP port', 'imap_username' => 'IMAP user name', 'imap_password' => 'IMAP password',
            'smtp_host' => 'SMTP server', 'smtp_port' => 'SMTP port', 'email_address' => 'email address',
        ]);

        // the host must resolve to public addresses (checked again before every connection)
        $problems = [];
        if ($problem = MailHost::problem($data['imap_host'], (int) $data['imap_port'], 'imap')) {
            $problems['imap_host'] = $problem;
        }
        if ($smtp && ($problem = MailHost::problem((string) $data['smtp_host'], (int) $data['smtp_port'], 'smtp'))) {
            $problems['smtp_host'] = $problem;
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $data['enabled'] = (bool) ($data['enabled'] ?? false);
        $data['email_address'] = strtolower(trim($data['email_address']));
        $data['imap_host'] = strtolower(trim($data['imap_host']));
        $data['smtp_host'] = $smtp ? strtolower(trim((string) $data['smtp_host'])) : null;
        $data['imap_password'] ??= null;

        return $data;
    }

    private function json(Helpdesk $helpdesk): JsonResponse
    {
        $mailbox = $helpdesk->mailbox()->first();
        $recent = HelpdeskInboundEmail::with('ticket:id,key')
            ->where('helpdesk_id', $helpdesk->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (HelpdeskInboundEmail $row) => [
                'id' => $row->id,
                'transport' => $row->transport,
                'from_email' => $row->from_email,
                'subject' => $row->subject,
                'status' => $row->status,
                'reason' => $row->reason,
                'ticket_key' => $row->ticket?->key,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'mailbox' => $mailbox ? [
                'enabled' => $mailbox->enabled,
                'email_address' => $mailbox->email_address,
                'from_name' => $mailbox->from_name,
                'imap_host' => $mailbox->imap_host,
                'imap_port' => $mailbox->imap_port,
                'imap_encryption' => $mailbox->imap_encryption,
                'imap_username' => $mailbox->imap_username,
                'has_imap_password' => (string) $mailbox->imap_password !== '',
                'imap_folder' => $mailbox->imap_folder,
                'processed_folder' => $mailbox->processed_folder,
                'smtp_mode' => $mailbox->smtp_mode,
                'smtp_host' => $mailbox->smtp_host,
                'smtp_port' => $mailbox->smtp_port,
                'smtp_encryption' => $mailbox->smtp_encryption,
                'smtp_username' => $mailbox->smtp_username,
                'has_smtp_password' => (string) $mailbox->smtp_password !== '',
                'last_checked_at' => $mailbox->last_checked_at?->toIso8601String(),
                'last_success_at' => $mailbox->last_success_at?->toIso8601String(),
                'last_error' => $mailbox->last_error,
            ] : null,
            'recent' => $recent,
            'options' => [
                'imap_ports' => MailHost::ports('imap'),
                'smtp_ports' => MailHost::ports('smtp'),
                'allow_unencrypted' => MailHost::allowPrivate(),
                'app_from_address' => (string) config('mail.from.address'),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}

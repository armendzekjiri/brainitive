<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Mail\BillingReportMail;
use App\Models\BillingReport;
use App\Models\Workspace;
use App\Support\Billing\BillingDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing documents (v1.38.0, BRAI-101): PDF and Excel of a billing report, and sending the PDF to
 * the client. Renders the report's `snapshot` (finalized / sent / paid / void) or the live draft
 * (App\Contracts\Billing\BuildsReportData; 409 while no builder is bound) — same shape, see
 * docs/ai/tasks/release-v1.38.0-billing.md. Workspace admins only.
 */
class BillingDocumentController extends Controller
{
    /** Statuses whose document can be mailed to the client. */
    public const SENDABLE = ['finalized', 'sent', 'paid'];

    private const MAX_CC = 5;

    public function pdf(Workspace $workspace, int $billingReport): Response
    {
        $document = BillingDocument::fromReport($this->report($workspace, $billingReport));

        return $this->download($document->pdf(), $document->filename('pdf'), 'application/pdf');
    }

    public function xlsx(Workspace $workspace, int $billingReport): Response
    {
        $document = BillingDocument::fromReport($this->report($workspace, $billingReport));

        return $this->download($document->xlsx(), $document->filename('xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function send(Request $request, Workspace $workspace, int $billingReport): RedirectResponse
    {
        $report = $this->report($workspace, $billingReport);

        if (! in_array($report->status, self::SENDABLE, true) || ! is_array($report->snapshot) || $report->snapshot === []) {
            throw ValidationException::withMessages(['status' => 'Only finalized reports can be sent. Finalize the report first.']);
        }

        // a line break in an address or the subject would be a header injection
        $data = $request->validate([
            'to' => ['nullable', 'string', 'max:254', 'email:rfc,strict', 'not_regex:/[\r\n,;<>]/'],
            'cc' => ['nullable', 'string', 'max:1000', 'not_regex:/[\r\n<>]/'],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $to = trim((string) ($data['to'] ?? '')) ?: (string) (data_get($report->snapshot, 'client.email') ?: $report->client?->email);
        if ($to === '' || Validator::make(['to' => $to], ['to' => 'email:rfc,strict'])->fails()) {
            throw ValidationException::withMessages(['to' => 'Enter the email address to send the report to.']);
        }
        $cc = $this->ccList((string) ($data['cc'] ?? ''), $to);

        $user = $request->user();
        Mail::to($to)
            ->cc($cc)
            ->queue(new BillingReportMail($report, $data['subject'], $data['message'] ?? null, $user->email, $user->name));

        $report->forceFill([
            'sent_at' => now(),
            'sent_to' => mb_substr($to, 0, 255),
            'status' => $report->status === 'finalized' ? 'sent' : $report->status,
        ])->save();

        return back()->with('success', 'The report is on its way to '.$to.'.');
    }

    /** @return list<string> */
    private function ccList(string $raw, string $to): array
    {
        $addresses = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $address) {
            if ($address !== '' && strcasecmp($address, $to) !== 0) {
                $addresses[mb_strtolower($address)] ??= $address; // case-insensitive dedupe
            }
        }
        $addresses = array_values($addresses);

        if (count($addresses) > self::MAX_CC) {
            throw ValidationException::withMessages(['cc' => 'Add at most '.self::MAX_CC.' addresses in copy.']);
        }
        foreach ($addresses as $address) {
            if (mb_strlen($address) > 254 || Validator::make(['cc' => $address], ['cc' => 'email:rfc,strict'])->fails()) {
                throw ValidationException::withMessages(['cc' => 'Every address in copy must be a valid email address.']);
            }
        }

        return $addresses;
    }

    private function download(string $bytes, string $filename, string $type): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            // the name is a slug (ASCII), so it is its own fallback
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, Str::ascii($filename)),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function report(Workspace $workspace, int $id): BillingReport
    {
        Gate::authorize('manageBilling', $workspace);

        return BillingReport::where('workspace_id', $workspace->id)->with('client')->findOrFail($id);
    }
}

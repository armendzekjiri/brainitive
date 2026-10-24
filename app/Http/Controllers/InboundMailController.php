<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundMail;
use App\Models\Helpdesk;
use App\Support\Helpdesks\Inbound\ForwardingAddress;
use App\Support\Helpdesks\Inbound\InboundMessage;
use App\Support\Helpdesks\Inbound\InboundPayloads;
use App\Support\Helpdesks\Inbound\InboundTempFiles;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Inbound email webhooks for helpdesk forwarding addresses (BRAI-124): `POST /api/inbound-mail/postmark`
 * and `/api/inbound-mail/mailgun`. Stateless API routes (no session / CSRF), throttled, 404 until
 * the domain and that provider's secret are configured (ForwardingAddress::providerEnabled).
 *
 * Verify → map the recipient to a helpdesk → build an InboundMessage (attachments to temp files)
 * → queue ProcessInboundMail, which calls InboundMail::receive(). Answers:
 * - 200 accepted;
 * - 401 bad credentials / signature (the provider retries: a misconfigured secret loses nothing);
 * - "don't retry" for permanent refusals (unknown recipient, no sender, stale or replayed
 *   signature, too large): Mailgun stops on 406, Postmark on 403.
 */
class InboundMailController extends Controller
{
    private const MAX_BYTES = 40 * 1024 * 1024;

    public function postmark(Request $request): JsonResponse
    {
        if (! ForwardingAddress::providerEnabled('postmark')) {
            return $this->disabled();
        }

        // basic auth in the webhook URL (https://user:<token>@host/…) or ?token=<token>
        $given = (string) ($request->getPassword() ?? '');
        if ($given === '') {
            $given = (string) $request->query('token', '');
        }
        $token = (string) config('services.inbound_mail.postmark_token');
        if ($given === '' || ! hash_equals($token, $given)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return $this->refuse('postmark', 'Payload too large.');
        }
        $data = $request->json()->all();
        if (! is_array($data) || $data === []) {
            return $this->refuse('postmark', 'Send the Postmark inbound JSON.');
        }

        return $this->accept(
            'postmark',
            InboundPayloads::postmarkRecipients($data),
            fn (string $dir) => InboundPayloads::postmark($data, $dir),
        );
    }

    public function mailgun(Request $request): JsonResponse
    {
        if (! ForwardingAddress::providerEnabled('mailgun')) {
            return $this->disabled();
        }

        // routes post form fields; the signature may also come nested (webhook JSON style)
        $signed = is_array($request->input('signature')) ? $request->input('signature') : $request->all();
        $timestamp = is_scalar($signed['timestamp'] ?? null) ? (string) $signed['timestamp'] : '';
        $token = is_scalar($signed['token'] ?? null) ? (string) $signed['token'] : '';
        $signature = is_scalar($signed['signature'] ?? null) ? (string) $signed['signature'] : '';

        $expected = hash_hmac('sha256', $timestamp.$token, (string) config('services.inbound_mail.mailgun_signing_key'));
        if ($timestamp === '' || $token === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $window = max(60, (int) config('services.inbound_mail.replay_window', 900));
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $window) {
            return $this->refuse('mailgun', 'Signature expired.');
        }
        // one use per token inside the window (+ margin): a captured request can't be replayed
        $replayKey = 'inbound-mail:mailgun:'.hash('sha256', $token);
        if (! Cache::add($replayKey, true, $window * 2)) {
            return $this->refuse('mailgun', 'Already received.');
        }

        try {
            $fields = array_filter($request->post(), 'is_string');
            $files = array_filter($request->allFiles(), fn ($file) => $file instanceof UploadedFile);

            return $this->accept(
                'mailgun',
                InboundPayloads::mailgunRecipients($fields),
                fn (string $dir) => InboundPayloads::mailgun($fields, $files, $dir),
                onError: fn () => Cache::forget($replayKey),
            );
        } catch (Throwable $e) {
            // let Mailgun's retry through: the token wasn't used up by a message we didn't take
            Cache::forget($replayKey);
            throw $e;
        }
    }

    /**
     * @param  list<string>  $recipients
     * @param  Closure(string): InboundMessage  $build
     */
    private function accept(string $provider, array $recipients, Closure $build, ?Closure $onError = null): JsonResponse
    {
        $helpdesk = $this->helpdeskFor($recipients);
        if (! $helpdesk) {
            return $this->refuse($provider, 'Unknown recipient.');
        }

        $dir = InboundTempFiles::newDir();
        try {
            $message = $build($dir);
        } catch (InvalidArgumentException $e) {
            InboundTempFiles::delete($dir);

            return $this->refuse($provider, $e->getMessage());
        }

        try {
            ProcessInboundMail::dispatch($helpdesk->id, $message, $message->attachments === [] ? null : $dir);
        } catch (Throwable $e) {
            // sync queue: the pipeline failed inline; answer 500 so the provider retries
            InboundTempFiles::delete($dir);
            if ($onError) {
                $onError();
            }
            report($e);

            return response()->json(['message' => 'Could not process the message, try again.'], 500);
        }
        if ($message->attachments === []) {
            InboundTempFiles::delete($dir);
        }

        return response()->json(['message' => 'Accepted.']);
    }

    /** @param  list<string>  $recipients */
    private function helpdeskFor(array $recipients): ?Helpdesk
    {
        foreach (array_slice($recipients, 0, 20) as $recipient) {
            if ($helpdesk = ForwardingAddress::resolve($recipient)) {
                return $helpdesk;
            }
        }

        return null;
    }

    /** Not configured: the endpoint doesn't exist (plain JSON, no error page). */
    private function disabled(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], 404);
    }

    /** A permanent refusal the provider must not retry. */
    private function refuse(string $provider, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $provider === 'mailgun' ? 406 : 403);
    }
}

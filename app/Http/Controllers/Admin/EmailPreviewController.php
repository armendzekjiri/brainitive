<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\Admin\InstanceMailConfig;
use App\Support\Mail\EmailPreviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Instance admin → Emails (BRAI-155): every email the app sends, rendered from made-up sample data
 * (App\Support\Mail\EmailPreviews — never real users or content), and a test send to the admin's own
 * address. Behind the admin group's auth + verified + instance.admin middleware.
 */
class EmailPreviewController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Admin/Emails', [
            'emails' => EmailPreviews::list(),
            'groups' => EmailPreviews::GROUPS,
            'adminEmail' => $request->user()->email,
        ]);
    }

    /** The rendered email for the preview iframe, or (?format=json) its subject and text part. */
    public function show(Request $request, string $email): HttpResponse
    {
        abort_unless(EmailPreviews::exists($email), 404);
        $rendered = EmailPreviews::render($email);

        if ($request->query('format') === 'json') {
            return response()->json(['subject' => $rendered['subject'], 'text' => $rendered['text']]);
        }

        // the page's Light / Dark toggle decides, not the admin's OS (not every browser passes the frame's
        // color-scheme on to prefers-color-scheme): the dark-mode rules apply always / never
        $html = match ($request->query('scheme')) {
            'dark' => str_replace('@media (prefers-color-scheme: dark)', '@media all', $rendered['html']),
            'light' => str_replace('@media (prefers-color-scheme: dark)', '@media not all', $rendered['html']),
            default => $rendered['html'],
        };

        // only the app may frame it; no scripts, forms or plugins — styles inline, images from anywhere (mail clients do too)
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; font-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** Sends the preview to the signed-in admin's own address (never anyone else), right away. */
    public function send(Request $request, string $email): RedirectResponse
    {
        abort_unless(EmailPreviews::exists($email), 404);
        $rendered = EmailPreviews::render($email);
        $to = (string) $request->user()->email;

        InstanceMailConfig::apply();

        try {
            Mail::send(
                ['html' => new HtmlString($rendered['html']), 'text' => new HtmlString($rendered['text'])],
                [],
                fn ($message) => $message->to($to)->subject('[Test] '.str_replace(["\r", "\n"], ' ', $rendered['subject'])),
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Sending failed: '.$e->getMessage());
        }

        AdminAuditLog::record('emails.test_sent', null, ['email' => $email, 'to' => $to]);

        return back()->with('success', "Test email sent to {$to}.");
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DataExport;
use App\Support\Profile\DataExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile → Your data → "Download my data" (GDPR Art. 15 + 20, BRAI-156 follow-up): a queued ZIP of
 * the signed-in person's own data, downloaded through a signed link that works for 24 h and only in
 * their own session. One request per hour (named limiter `data-export:<user id>`).
 */
class DataExportController extends Controller
{
    /** The person's recent exports (the profile card polls it while one is being prepared). */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        // shared with the App API (BRAI-394): busy / hourly limit → `export` validation error
        DataExports::request($request->user());

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($this->state($request), 202);
        }

        return back()->with('success', 'Your data is being prepared. You’ll get a notification and an email when it’s ready.');
    }

    /**
     * The ZIP. Only the person it is about, with a valid signature (the link expires with the export)
     * and a file that's still there. An Inertia visit (the notification bell) becomes a full page
     * load so the browser downloads the file.
     */
    public function download(Request $request, DataExport $dataExport): Response
    {
        abort_unless($dataExport->user_id === $request->user()->id, 403);
        abort_unless(URL::hasCorrectSignature($request, absolute: false), 403, 'Invalid download link.');

        if (! URL::signatureHasNotExpired($request) || ! $dataExport->isDownloadable() || ! Storage::disk('local')->exists((string) $dataExport->file_path)) {
            return redirect()->to(route('profile.edit').'#your-data')
                ->with('error', 'This download link has expired. Request a new copy of your data.');
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        return Storage::disk('local')->download((string) $dataExport->file_path, basename((string) $dataExport->file_path), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{exports: list<array<string, mixed>>, available_in: int} */
    private function state(Request $request): array
    {
        $user = $request->user();

        return [
            'exports' => array_map(fn (DataExport $e) => $e->present(), DataExports::recent($user)),
            // seconds until a new request is allowed (0 = now)
            'available_in' => DataExports::availableIn($user),
        ];
    }
}

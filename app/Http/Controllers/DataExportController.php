<?php

namespace App\Http\Controllers;

use App\Jobs\ExportPersonalData;
use App\Models\DataExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
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
        $user = $request->user();

        // one at a time per person: the checks, the limiter hit and the new row are atomic
        Cache::lock('data-export-store:'.$user->id, 10)->block(5, function () use ($user) {
            if (DataExport::where('user_id', $user->id)->whereIn('status', [DataExport::QUEUED, DataExport::RUNNING])->where('created_at', '>', now()->subHour())->exists()) {
                throw ValidationException::withMessages(['export' => 'Your data is already being prepared.']);
            }
            $key = DataExport::limiterKey($user);
            if (RateLimiter::tooManyAttempts($key, 1)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages(['export' => "You can request a new copy in {$minutes} ".str('minute')->plural($minutes).'.']);
            }
            RateLimiter::hit($key, DataExport::COOLDOWN_MINUTES * 60);

            // only the newest copy is kept: earlier files (expired or not) go now
            DataExport::where('user_id', $user->id)->whereNotNull('file_path')->get()->each->expire();

            $export = DataExport::create(['user_id' => $user->id, 'status' => DataExport::QUEUED]);
            $job = ExportPersonalData::dispatch($export);
            if (config('queue.default') === 'sync') {
                $job->afterResponse(); // dev / small installs: don't hold the request open
            }
        });

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
        $key = DataExport::limiterKey($user);

        return [
            'exports' => DataExport::where('user_id', $user->id)->latest('id')->limit(5)->get()
                ->map(fn (DataExport $e) => $e->present())->values()->all(),
            // seconds until a new request is allowed (0 = now)
            'available_in' => RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0,
        ];
    }
}

<?php

namespace App\Support\Profile;

use App\Jobs\ExportPersonalData;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Download my data" (GDPR Art. 15 + 20, BRAI-156): request a queued ZIP of the person's own data
 * and read the state of their recent exports. One request per hour (named limiter
 * `data-export:<user id>`), only the newest file is kept. Profile → Your data on the web and the App
 * API `POST|GET /me/data-export` (BRAI-394).
 */
final class DataExports
{
    /**
     * @throws ValidationException one is being prepared, or the hourly limit (`export`)
     */
    public static function request(User $user): DataExport
    {
        // one at a time per person: the checks, the limiter hit and the new row are atomic
        return Cache::lock('data-export-store:'.$user->id, 10)->block(5, function () use ($user) {
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

            return $export;
        });
    }

    /** @return list<DataExport> the person's recent exports, newest first */
    public static function recent(User $user): array
    {
        return DataExport::where('user_id', $user->id)->latest('id')->limit(5)->get()->all();
    }

    /** Seconds until a new request is allowed (0 = now). */
    public static function availableIn(User $user): int
    {
        $key = DataExport::limiterKey($user);

        return RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0;
    }
}

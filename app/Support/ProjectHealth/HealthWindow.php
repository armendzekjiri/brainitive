<?php

namespace App\Support\ProjectHealth;

use App\Http\Controllers\ProjectHealthController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The period of the project health metrics (BRAI-64): time zone + local dates from `tz` / `from` / `to`, default
 * the viewer's zone and the last 14 days, at most a year. Shared by the web (ProjectHealthController) and the App
 * API (BRAI-423). Strict: bad input → 422; otherwise bad parts fall back to the defaults.
 */
final class HealthWindow
{
    /**
     * @param  array<string, mixed>  $input  tz, from, to
     * @return array{0: string, 1: string, 2: string} [time zone, from, to]
     *
     * @throws ValidationException
     */
    public static function resolve(array $input, User $user, bool $strict = true): array
    {
        $rules = [
            'tz' => 'nullable|string|timezone:all_with_bc',
            'from' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01',
            'to' => 'nullable|date_format:Y-m-d|before_or_equal:2100-12-31',
        ];
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            if ($strict) {
                throw new ValidationException($validator);
            }
            $input = array_diff_key($input, $validator->errors()->messages());
        }

        $timezone = $input['tz'] ?? $user->localTimezone();
        $today = CarbonImmutable::now($timezone)->toDateString();
        $to = $input['to'] ?? $today;
        $from = $input['from'] ?? CarbonImmutable::parse($to)->subDays(ProjectHealthController::DEFAULT_PERIOD_DAYS - 1)->toDateString();

        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= ProjectHealthController::MAX_PERIOD_DAYS) {
            if ($strict) {
                throw ValidationException::withMessages(['from' => 'Pick a period of up to a year that starts before it ends.']);
            }
            $from = CarbonImmutable::parse($to)->subDays(ProjectHealthController::DEFAULT_PERIOD_DAYS - 1)->toDateString();
        }

        return [$timezone, $from, $to];
    }
}

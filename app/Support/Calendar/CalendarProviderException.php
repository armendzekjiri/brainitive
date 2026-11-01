<?php

namespace App\Support\Calendar;

use App\Support\AppApi\AppApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base of the calendar provider errors. Its message is shown to the user, so keep it short
 * and free of internals. Uncaught, it renders as a 422: JSON {message, errors.calendar} for
 * fetch / JSON requests (+ `code` on the App API), or a redirect back with errors.calendar for Inertia requests.
 */
abstract class CalendarProviderException extends RuntimeException
{
    /** An expected outcome (read-only calendar, provider said no), not a bug: nothing to log. */
    public function report(): void {}

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        // App API v1: the same 422, in its error shape (code validation_failed)
        if (AppApiErrors::applies($request)) {
            return AppApiErrors::json(422, $this->getMessage(), ['calendar' => [$this->getMessage()]]);
        }
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['calendar' => [$this->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['calendar' => $this->getMessage()]);
    }
}

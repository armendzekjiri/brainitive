<?php

namespace App\Support\AppApi;

use Illuminate\Validation\ValidationException;

/**
 * A 422 that also tells the app what it may send instead (e.g. the activities to pick from): the
 * extras are added to the App API error body next to `errors` (AppApiErrors::render).
 */
class ValidationWithExtras extends ValidationException
{
    /** @var array<string, mixed> */
    public array $extras = [];

    /**
     * @param  array<string, array<int, string>|string>  $messages
     * @param  array<string, mixed>  $extras
     */
    public static function with(array $messages, array $extras): static
    {
        $e = static::withMessages($messages);
        $e->extras = $extras;

        return $e;
    }
}

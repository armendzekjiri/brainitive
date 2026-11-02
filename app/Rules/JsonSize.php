<?php

namespace App\Rules;

use App\Support\Validation\ContentLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A value (array, or a JSON string) whose serialized size stays within a byte budget (ContentLimits).
 */
class JsonSize implements ValidationRule
{
    public function __construct(private readonly int $maxBytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (ContentLimits::size($value) > $this->maxBytes) {
            $fail('The :attribute is too large (at most '.number_format($this->maxBytes / 1_000_000, 0).' MB).');
        }
    }
}

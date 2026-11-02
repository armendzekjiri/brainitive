<?php

namespace App\Http\Requests\AppApi\Concerns;

use App\Support\Validation\ContentLimits;

/** The fields a doc shares between create and update (BRAI-411). */
trait PageFields
{
    /** An emoji; uploaded image icons are set on the web. */
    protected static function iconRules(): array
    {
        return ['nullable', 'string', 'max:32', 'not_regex:/^(data:|https?:|\/)/i'];
    }

    /** The body as Markdown (docs only), mentions `@[Name](user:<id>)`. */
    protected static function markdownRules(): array
    {
        return ['nullable', 'string', 'max:'.ContentLimits::PAGE_BODY];
    }
}

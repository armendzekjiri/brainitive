<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `POST /pages/{uuid}/reviews/{review}/decision`: an active reviewer of an open review decides (403 otherwise). */
class ReviewDecisionRequest extends ReviewRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->review());
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approved,changes_requested'],
            // a note for the author (plain text)
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

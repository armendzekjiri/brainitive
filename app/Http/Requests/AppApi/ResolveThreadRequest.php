<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesPageReviews;
use App\Models\PageComment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** `PATCH /pages/{uuid}/review-threads/{thread}`: resolve / reopen a thread (anyone who may see its review, as on the web). */
class ResolveThreadRequest extends FormRequest
{
    use ResolvesPageReviews;

    private ?PageComment $resolvedThread = null;

    public function authorize(): bool
    {
        $this->thread();

        return true;
    }

    public function thread(): PageComment
    {
        return $this->resolvedThread ??= $this->visibleThread($this, $this->reviewedPage($this, (string) $this->route('uuid')), (string) $this->route('thread'));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['resolved' => ['required', 'boolean']];
    }
}

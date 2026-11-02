<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesPageReviews;
use App\Models\Page;
use App\Models\PageReview;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A write on a doc review (BRAI-413). Authorized before validation: a page / review the user can't see is a
 * 404, so nothing leaks through a 422.
 */
abstract class ReviewRequest extends FormRequest
{
    use ResolvesPageReviews;

    private ?Page $resolvedPage = null;

    private ?PageReview $resolvedReview = null;

    public function page(): Page
    {
        return $this->resolvedPage ??= $this->reviewedPage($this, (string) $this->route('uuid'));
    }

    public function review(): PageReview
    {
        return $this->resolvedReview ??= $this->visibleReview($this, $this->page(), (string) $this->route('review'));
    }
}

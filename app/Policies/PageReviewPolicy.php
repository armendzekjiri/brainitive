<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\PageReview;
use App\Models\User;
use App\Support\PageReviews\PageReviews;

/**
 * Page reviews (BRAI-80). People who can edit the page see all its reviews; a reviewer sees the
 * review's snapshot (and nothing else of an unpublished draft) as long as they can open the project.
 */
class PageReviewPolicy
{
    public function view(User $user, PageReview $review): bool
    {
        return $user->can('update', $review->page) || PageReviews::isActiveReviewer($review, $user);
    }

    /** Approve / request changes: an active reviewer of an open review. */
    public function decide(User $user, PageReview $review): bool
    {
        return $review->isOpen() && PageReviews::isActiveReviewer($review, $user);
    }

    /** New threads on an open review. */
    public function comment(User $user, PageReview $review): bool
    {
        return $review->isOpen() && $this->view($user, $review);
    }

    public function cancel(User $user, PageReview $review): bool
    {
        return $review->isOpen() && $user->can('update', $review->page);
    }

    /** Ask for a review (`can('request', [PageReview::class, $page])`) of a document page's draft. */
    public function request(User $user, Page $page): bool
    {
        return $page->isPage() && ! $page->is_whiteboard && $page->status !== 'archived' && $user->can('update', $page);
    }

    /** Turn "Review required before publish" on / off: project or workspace admins (documents only — whiteboards can't be reviewed). */
    public function manage(User $user, Page $page): bool
    {
        return $page->isPage() && ! $page->is_whiteboard && in_array($page->project_id, $user->adminProjectIds(), true);
    }
}

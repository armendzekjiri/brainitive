<?php

namespace App\Notifications;

use App\Models\PageComment;
use App\Models\PageReview;
use App\Models\User;
use App\Notifications\Concerns\DescribesPageReview;

/** A review comment or reply on your page / in your thread (category: reviews, BRAI-80). */
class ReviewCommentedNotification extends CatalogNotification
{
    use DescribesPageReview;

    public function __construct(
        public readonly PageReview $review,
        public readonly PageComment $comment,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'review_commented';
    }

    protected function message(object $notifiable): array
    {
        $verb = $this->comment->parent_id ? 'replied in a review of' : 'commented in a review of';

        return [
            'title' => "{$this->actor->name} {$verb} {$this->review->snapshot_name}",
            'body' => $this->comment->body,
            'url' => $this->reviewUrl($this->review),
            'actor' => $this->actor,
            'project_name' => $this->reviewContext($this->review),
        ];
    }
}

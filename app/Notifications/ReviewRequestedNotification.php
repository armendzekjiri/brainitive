<?php

namespace App\Notifications;

use App\Models\PageReview;
use App\Models\User;
use App\Notifications\Concerns\DescribesPageReview;

/** You were asked to review a page draft (category: reviews, BRAI-80). Sent once per review and reviewer. */
class ReviewRequestedNotification extends CatalogNotification
{
    use DescribesPageReview;

    public function __construct(
        public readonly PageReview $review,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'review_requested';
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => "{$this->actor->name} asked you to review {$this->review->snapshot_name}",
            'body' => $this->review->message,
            'url' => $this->reviewUrl($this->review),
            'actor' => $this->actor,
            'project_name' => $this->reviewContext($this->review),
        ];
    }
}

<?php

namespace App\Notifications;

use App\Models\PageReview;
use App\Models\User;
use App\Notifications\Concerns\DescribesPageReview;

/** A reviewer approved or requested changes on your review request (category: reviews, BRAI-80). */
class ReviewDecidedNotification extends CatalogNotification
{
    use DescribesPageReview;

    public function __construct(
        public readonly PageReview $review,
        // the decision as it was made (the queued job must not re-read a reviewer who changed their mind)
        public readonly string $decision,
        public readonly ?string $comment,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'review_decided';
    }

    protected function message(object $notifiable): array
    {
        $verb = $this->decision === 'approved' ? 'approved' : 'requested changes on';

        return [
            'title' => "{$this->actor->name} {$verb} {$this->review->snapshot_name}",
            'body' => $this->comment,
            'url' => $this->reviewUrl($this->review),
            'actor' => $this->actor,
            'project_name' => $this->reviewContext($this->review),
        ];
    }
}

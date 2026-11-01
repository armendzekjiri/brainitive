<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Item;
use App\Models\User;
use App\Notifications\Concerns\DescribesItem;
use Illuminate\Database\Eloquent\Model;

/** Someone commented on an issue you watch, are assigned to or reported (category: comments). */
class ItemCommentedNotification extends CatalogNotification
{
    use DescribesItem;

    public function __construct(
        public readonly Item $item,
        public readonly Comment $comment,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'item_commented';
    }

    protected function digestSubject(): ?Model
    {
        return $this->item;
    }

    protected function digestSubjectTitle(): ?string
    {
        return (string) $this->item->title;
    }

    protected function message(object $notifiable): array
    {
        $verb = $this->comment->parent_id ? 'replied on' : 'commented on';

        return [
            'title' => "{$this->actor->name} {$verb} {$this->item->key}",
            'body' => $this->comment->body,
            'url' => $this->itemUrl($this->item),
            'actor' => $this->actor,
            'project_name' => $this->itemContext($this->item),
            'subject_key' => $this->item->key,
        ];
    }
}

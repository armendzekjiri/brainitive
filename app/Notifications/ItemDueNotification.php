<?php

namespace App\Notifications;

use App\Models\Item;
use App\Notifications\Concerns\DescribesItem;

/**
 * An issue assigned to you is due tomorrow, or overdue (category: reminders). Sent once per issue,
 * kind and due date by `app:send-item-reminders`; the label is worded in the assignee's time zone.
 */
class ItemDueNotification extends CatalogNotification
{
    use DescribesItem;

    public const DUE_TOMORROW = 'due_tomorrow';

    public const OVERDUE = 'overdue';

    public function __construct(
        public readonly Item $item,
        public readonly string $kind,
        public readonly string $whenLabel,
    ) {}

    public function catalogType(): string
    {
        return 'item_due';
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => $this->kind === self::OVERDUE
                ? "{$this->item->key} is overdue"
                : "{$this->item->key} is due tomorrow",
            'body' => "{$this->item->title} · {$this->whenLabel}",
            'url' => $this->itemUrl($this->item),
            'project_name' => $this->itemContext($this->item),
            'subject_key' => $this->item->key,
        ];
    }
}

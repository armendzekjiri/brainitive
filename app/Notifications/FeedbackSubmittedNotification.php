<?php

namespace App\Notifications;

use App\Models\FeedbackPost;
use Illuminate\Support\Str;

/**
 * A new idea on a public feedback portal waits for moderation (v1.42.0, BRAI-60; category: feedback).
 * To the project admins, from FeedbackNotifier::submitted(). No actor: the author is a portal guest.
 */
class FeedbackSubmittedNotification extends CatalogNotification
{
    public const TYPE = 'feedback_submitted';

    public function __construct(public readonly FeedbackPost $post) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $portal = $this->post->portal;
        $project = $portal->project;

        return [
            'title' => 'New idea waiting for review: “'.Str::limit($this->post->title, 80).'”',
            'body' => $this->post->body,
            'url' => route('projects.feedback.index', [$project->workspace->slug, $project->slug, 'tab' => 'pending']),
            'actor' => null,
            'project_name' => $project->name.' · '.$portal->title,
            'subject_key' => '#'.$this->post->number,
        ];
    }
}

<?php

namespace App\Http\Resources\AppApi;

use App\Models\FeedbackPortal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's feedback portal for its admins: the public link and how many ideas wait in each moderation tab.
 * Settings (title, intro, address, on / off): web only. Expects `counts` set by the controller.
 *
 * @mixin FeedbackPortal
 */
class FeedbackPortalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $counts = (array) $this->getAttribute('moderation_counts');

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            // false: the public portal is switched off
            'enabled' => (bool) $this->enabled,
            'slug' => (string) $this->slug,
            'title' => (string) $this->title,
            'public_url' => $this->publicUrl(),
            // ideas per moderation tab
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'published' => (int) ($counts['published'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'merged' => (int) ($counts['merged'] ?? 0),
            ],
            // the moderation page on the web (settings, merging, cards, audit log)
            'url' => route('projects.feedback.index', [$this->project->workspace->slug, $this->project->slug]),
        ];
    }
}

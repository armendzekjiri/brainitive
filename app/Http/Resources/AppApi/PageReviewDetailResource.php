<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;

/**
 * One review (`GET /pages/{uuid}/reviews/{review}`): the summary, the reviewed snapshot (Markdown + sanitized
 * HTML), the user's own decision, every thread they may see and what they may do.
 *
 * @property array<string, mixed> $resource
 */
class PageReviewDetailResource extends PageReviewResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...parent::toArray($request),
            'snapshot_markdown' => (string) $r['snapshot_markdown'],
            'snapshot_html' => (string) $r['snapshot_html'],
            'my_decision' => $r['my_decision'] === null ? null : [
                /** @var 'pending'|'approved'|'changes_requested' */
                'decision' => (string) $r['my_decision']['decision'],
                'comment' => $r['my_decision']['comment'] === null ? null : (string) $r['my_decision']['comment'],
            ],
            // the threads of every review of the page the user may see, oldest first
            'threads' => PageThreadResource::collection($r['threads']),
            'can' => [
                'decide' => (bool) $r['can']['decide'],
                // start a thread (replies: anyone who sees the review)
                'comment' => (bool) $r['can']['comment'],
                // the live doc (an unpublished draft is not shown to reviewers)
                'open_page' => (bool) $r['can']['open_page'],
            ],
        ];
    }
}

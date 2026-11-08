<?php

namespace App\Http\Resources\AppApi;

use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\Boards\IssueMarkdown;
use Illuminate\Http\Request;

/**
 * One deliverable for the team (Deliverables::detail; the summary fields listed again for the OpenAPI schema):
 * the snapshot (a doc as Markdown + sanitized HTML), the client link, the clients' decisions (addresses only for
 * project admins), the conversation (internal notes included) and the other versions.
 *
 * @property-read array<string, mixed> $resource
 */
class ClientDeliverableDetailResource extends ClientDeliverableResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => (int) $d['id'],
            'uuid' => (string) $d['uuid'],
            'kind' => (string) $d['kind'],
            // a doc's / plan's name: empty for an API key without Docs / Plans read
            'title' => ApiKeyAccess::reads($d['kind'] === 'page' ? 'docs' : 'plans') ? (string) $d['title'] : '',
            'version' => (int) $d['version'],
            'status' => (string) $d['status'],
            'decision' => (string) $d['decision'],
            'published_at' => self::zulu($d['published_at']),
            'published_by' => $d['published_by'],
            'decisions_count' => (int) ($d['decisions_count'] ?? 0),
            'comments_count' => (int) ($d['comments_count'] ?? 0),
            'last_decision_at' => self::zulu($d['last_decision_at']),
            // the team's message to the client
            'message' => $d['message'],
            // kind page: the published snapshot
            // (a doc's content: null for an API key without Docs read)
            'markdown' => $d['kind'] === 'page' && ApiKeyAccess::reads('docs') ? IssueMarkdown::toMarkdown((string) $d['html']) : null,
            'html' => $d['kind'] === 'page' && ApiKeyAccess::reads('docs') ? (string) $d['html'] : null,
            // what the client opens (null once withdrawn)
            'client_url' => $d['client_url'],
            // the clients' approvals / change requests, oldest first
            'decisions' => collect($d['decisions'])->map(fn (array $x) => [
                'id' => (int) $x['id'],
                'name' => $x['name'],
                // project admins only
                'email' => $x['email'],
                // approved | changes_requested
                'decision' => (string) $x['decision'],
                'note' => $x['note'],
                'version' => (int) $x['version'],
                'created_at' => self::zulu($x['created_at']),
            ])->values()->all(),
            // the conversation; visibility internal = a team note the client never sees
            'comments' => collect($d['comments'])->map(fn (array $c) => [
                'id' => (int) $c['id'],
                'author' => $c['author'],
                'from_team' => (bool) $c['from_team'],
                // client | internal
                'visibility' => (string) $c['visibility'],
                'body' => (string) $c['body'],
                'created_at' => self::zulu($c['created_at']),
            ])->values()->all(),
            // every version of this doc / plan, newest first
            'versions' => collect($d['versions'] ?? [])->map(fn (array $v) => [
                'id' => (int) $v['id'],
                'version' => (int) $v['version'],
                'status' => (string) $v['status'],
                'published_at' => self::zulu($v['published_at']),
            ])->values()->all(),
        ];
    }
}

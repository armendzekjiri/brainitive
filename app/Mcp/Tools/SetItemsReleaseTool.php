<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithReleases;
use App\Models\Item;
use App\Models\Release;
use App\Support\Mcp\ToolError;
use App\Support\Releases\ReleaseLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Set the release (fix version) of issues by key (e.g. BRAI-12; up to 200): they move out of their old release. release_id 0 / null clears it. Every key must be an issue of this project — otherwise nothing changes and the unknown keys are listed. Archived releases take no issues. Safe to repeat.')]
class SetItemsReleaseTool extends Tool
{
    use InteractsWithReleases;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->projectRules() + [
            'release_id' => 'nullable|integer|min:0',
            'item_keys' => 'required|array|min:1|max:200',
            'item_keys.*' => 'required|string|max:50',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $release = ! empty($v['release_id']) ? $this->releaseOf($project, (int) $v['release_id']) : null;
            $release
                ? $this->authorizeFor($user, 'update', $release)
                : $this->authorizeFor($user, 'create', [Release::class, $project]);

            $keys = array_values(array_unique(array_map(fn ($k) => mb_strtoupper(trim((string) $k)), $v['item_keys'])));
            $ids = $project->items()->whereIn('key', $keys)->pluck('id', 'key');
            $unknown = array_values(array_diff($keys, $ids->keys()->all()));
            if ($unknown !== []) {
                throw new ToolError('Not issues of this project: '.implode(', ', $unknown).'. Nothing was changed.');
            }

            $rows = ReleaseLifecycle::assignItems($project, $release, $ids->values()->all());
            $names = $project->releases()->pluck('name', 'id');
            $byKey = $rows->keyBy(fn (array $row) => $row['item']->key);

            $items = array_map(function (string $key) use ($byKey, $names, $release) {
                /** @var Item $item */
                $item = $byKey[$key]['item'];
                $previous = $byKey[$key]['previous_release_id'];

                return [
                    'key' => $key,
                    'title' => $item->title,
                    'previous_release' => $previous ? (string) ($names[$previous] ?? '') : '',
                    'changed' => $previous !== $release?->id,
                ];
            }, $keys);

            return Response::structured([
                'release' => $release?->name ?? '',
                'changed' => count(array_filter($items, fn ($i) => $i['changed'])),
                'items' => $items,
                'releases_url' => $release ? route('releases.show', [$project->workspace->slug, $project->slug, $release->id]) : $this->releasesUrl($project),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'release' => $schema->string()->description('The release the issues are in now; empty when cleared.'),
            'changed' => $schema->integer()->description('Issues whose release changed (the rest were already there).'),
            'items' => $schema->array()->items($schema->object([
                'key' => $schema->string(),
                'title' => $schema->string(),
                'previous_release' => $schema->string()->description('Empty when it had none.'),
                'changed' => $schema->boolean(),
            ])),
            'releases_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectArgs($schema) + [
            'release_id' => $schema->integer()->nullable()->description('Release id from list_releases; 0 or null clears the issues\' release.')->required(),
            'item_keys' => $schema->array()->items($schema->string())->description('Issue keys of this project, e.g. ["BRAI-12", "BRAI-14"] (≤ 200).')->required(),
        ];
    }
}

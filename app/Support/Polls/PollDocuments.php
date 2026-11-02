<?php

namespace App\Support\Polls;

use App\Models\Item;
use App\Models\Page;
use App\Models\User;

/**
 * The polls a saved doc or issue description really contains (Codex web review E): poll id => its option uids. A
 * vote is only taken for a poll and option found here, so nobody can fill `poll_votes` with made-up polls.
 *
 * The editor saves a poll as `<div data-lexical-poll data-poll-state="{question, options: [{uid…}], pollId}">` in
 * the HTML (PollNode::exportDOM); polls saved before `pollId` existed get the editor's fallback id
 * (`o-<uid>-<uid>…`, at most 120 characters, PollNode::stablePollId).
 */
final class PollDocuments
{
    /**
     * Polls a user may vote in: the published content; people who can edit the page also its draft.
     *
     * @return array<string, list<string>>
     */
    public static function for(Page|Item $scope, ?User $user = null): array
    {
        if ($scope instanceof Item) {
            return self::parse($scope->description);
        }

        $polls = self::parse($scope->content);
        if ($user?->can('update', $scope) && $scope->hasUnpublishedChanges()) {
            $polls = array_merge_recursive($polls, self::parse($scope->draft_content));
        }

        return array_map(fn (array $uids) => array_values(array_unique($uids)), $polls);
    }

    public static function allows(Page|Item $scope, ?User $user, string $pollId, string $optionUid): bool
    {
        return in_array($optionUid, self::for($scope, $user)[$pollId] ?? [], true);
    }

    /** @return array<string, list<string>> */
    public static function parse(mixed $content): array
    {
        $polls = [];
        foreach (self::states($content) as $state) {
            $uids = [];
            foreach (is_array($state['options'] ?? null) ? $state['options'] : [] as $option) {
                if (is_array($option) && is_string($option['uid'] ?? null) && $option['uid'] !== '') {
                    $uids[] = $option['uid'];
                }
            }
            $pollId = is_string($state['pollId'] ?? null) && $state['pollId'] !== ''
                ? $state['pollId']
                : mb_substr('o-'.implode('-', $uids), 0, 120);
            $polls[$pollId] = array_merge($polls[$pollId] ?? [], $uids);
        }

        return $polls;
    }

    /** @return list<array<string, mixed>> */
    private static function states(mixed $content): array
    {
        $states = [];
        $strings = [];
        if (is_string($content)) {
            $strings[] = $content;
        } elseif (is_array($content)) {
            array_walk_recursive($content, function ($value) use (&$strings) {
                if (is_string($value) && str_contains($value, 'data-poll-state')) {
                    $strings[] = $value;
                }
            });
            // a Lexical JSON state: serialized poll nodes
            $walk = function (array $node) use (&$walk, &$states) {
                if (($node['type'] ?? null) === 'poll' && is_array($node['options'] ?? null)) {
                    $states[] = $node;
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            };
            $walk($content);
        }

        foreach ($strings as $html) {
            if (preg_match_all('/data-poll-state\s*=\s*(["\'])(.*?)\1/s', $html, $matches)) {
                foreach ($matches[2] as $raw) {
                    $state = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
                    if (is_array($state)) {
                        $states[] = $state;
                    }
                }
            }
        }

        return $states;
    }
}

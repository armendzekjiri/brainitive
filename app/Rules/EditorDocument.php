<?php

namespace App\Rules;

use App\Support\Validation\ContentLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rich-text value in one of the two formats the app stores (Codex web review D): the Lexical editor's
 * `{type: "lexical", html: "…"}` (what the task, page and issue editors send today) or a legacy TipTap /
 * ProseMirror document `{type: "doc", content: [nodes]}` with known node and mark types, bounded nesting and
 * serialized size. Arbitrary JSON is refused.
 */
class EditorDocument implements ValidationRule
{
    public const NODES = [
        'doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
        'blockquote', 'codeBlock', 'hardBreak', 'horizontalRule', 'image', 'table', 'tableRow', 'tableCell',
        'tableHeader', 'mention',
    ];

    public const MARKS = ['bold', 'italic', 'strike', 'code', 'link', 'underline', 'highlight', 'subscript', 'superscript', 'textStyle'];

    public function __construct(private readonly int $maxBytes = ContentLimits::BODY, private readonly int $maxDepth = ContentLimits::DOCUMENT_DEPTH) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value) || ContentLimits::size($value) > $this->maxBytes) {
            $fail('The :attribute is too large or not a document.');

            return;
        }

        $keys = array_keys($value);
        sort($keys);
        $valid = match ($value['type'] ?? null) {
            'lexical' => $keys === ['html', 'type'] && is_string($value['html']),
            'doc' => $this->node($value, 0),
            default => false,
        };
        if (! $valid) {
            $fail('The :attribute must be a rich-text document.');
        }
    }

    private function node(mixed $node, int $depth): bool
    {
        if (! is_array($node) || $depth > $this->maxDepth || ! in_array($node['type'] ?? null, self::NODES, true)) {
            return false;
        }
        if (array_diff(array_keys($node), ['type', 'content', 'text', 'attrs', 'marks']) !== []) {
            return false;
        }
        if (isset($node['text']) && ! is_string($node['text'])) {
            return false;
        }
        if (isset($node['attrs']) && ! $this->attrs($node['attrs'])) {
            return false;
        }
        if (isset($node['marks']) && ! is_array($node['marks'])) {
            return false;
        }
        foreach ($node['marks'] ?? [] as $mark) {
            if (! is_array($mark) || ! in_array($mark['type'] ?? null, self::MARKS, true) || (isset($mark['attrs']) && ! $this->attrs($mark['attrs']))) {
                return false;
            }
        }
        if (isset($node['content'])) {
            if (! is_array($node['content']) || ! array_is_list($node['content'])) {
                return false;
            }
            foreach ($node['content'] as $child) {
                if (! $this->node($child, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Attributes are flat: scalars or null. */
    private function attrs(mixed $attrs): bool
    {
        if (! is_array($attrs)) {
            return false;
        }
        foreach ($attrs as $value) {
            if (! is_scalar($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }
}

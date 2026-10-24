<?php

namespace App\Support\MeetingNotes;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\Mcp\PageContentConverter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The text an extraction reads (BRAI-62): a doc of the project the user can open, or pasted text.
 *
 * Draft visibility follows the page screen: people who may edit the doc read the unpublished
 * changes they see in the editor; everyone else only ever reads the live version.
 */
class MeetingNotesSource
{
    public function __construct(
        public readonly string $text,
        public readonly ?Page $page = null,
        public readonly bool $usesDraft = false,
    ) {}

    /**
     * A document of `$project` by uuid, authorized with `PagePolicy::view`.
     *
     * @throws AuthorizationException|ValidationException
     */
    public static function fromPage(User $user, Project $project, string $uuid): self
    {
        $page = Page::where('project_id', $project->id)->where('uuid', $uuid)->first();
        if (! $page) {
            throw ValidationException::withMessages(['page' => 'This doc doesn’t exist any more.']);
        }

        Gate::forUser($user)->authorize('view', $page);

        if ($page->type !== 'page' || $page->is_whiteboard) {
            throw ValidationException::withMessages(['page' => 'Action items can only be extracted from a doc.']);
        }

        $usesDraft = $page->draft_content !== null && Gate::forUser($user)->allows('update', $page);
        $content = $usesDraft ? $page->draft_content : $page->content;

        return new self(self::plain(PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($content))), $page, $usesDraft);
    }

    public static function fromText(string $text): self
    {
        return new self(self::plain($text));
    }

    /** Title shown in the dialog. */
    public function title(): ?string
    {
        if (! $this->page) {
            return null;
        }

        return $this->usesDraft && $this->page->draft_name ? $this->page->draft_name : $this->page->name;
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }

    public function tooLong(): bool
    {
        return $this->length() > ActionItemExtractor::MAX_SOURCE_CHARS;
    }

    /** Normalized line endings, no trailing spaces, at most one blank line in a row. */
    private static function plain(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}

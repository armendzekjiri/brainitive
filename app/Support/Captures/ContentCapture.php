<?php

namespace App\Support\Captures;

use App\Jobs\FetchLinkMetadataJob;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Boards\ProjectIssues;
use App\Support\Mcp\PageContentConverter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The shared "save this somewhere" behaviour of the browser extension (BRAI-52/59) and the
 * capture inbox conversion (BRAI-70): which policy guards each destination, what a capture may
 * contain, and how it is created. Markdown is converted to the editors' formats here.
 *
 * Destinations by type: issue → Board, task → TaskList, page → Project, bookmark → Bookmark
 * (a bookmark group), snippet → SnippetCollection.
 */
class ContentCapture
{
    /** @var array<string, class-string<Model>> */
    public const DESTINATIONS = [
        'issue' => Board::class,
        'task' => TaskList::class,
        'page' => Project::class,
        'bookmark' => Bookmark::class,
        'snippet' => SnippetCollection::class,
    ];

    public static function findDestination(string $type, int $id): ?Model
    {
        $class = self::DESTINATIONS[$type] ?? null;

        return $class ? $class::query()->find($id) : null;
    }

    /**
     * The same policies as the app's own create actions.
     *
     * @throws AuthorizationException
     */
    public static function authorize(User $user, string $type, Model $destination): void
    {
        $gate = Gate::forUser($user);

        match ($type) {
            'issue' => $gate->authorize('createItems', $destination),
            'task' => $gate->authorize('manageItems', $destination),
            'page' => $user->hasProjectAccess($destination)
                ? $gate->authorize('create', [Page::class, $destination])
                : throw new AuthorizationException('You do not have access to this project.'),
            'bookmark' => $gate->authorize('update', $destination),
            'snippet' => $gate->authorize('create', [SnippetCollection::class, $destination->workspace]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(string $type, Model $destination): array
    {
        return match ($type) {
            'issue' => [
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:50000',
                'column_id' => ['nullable', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $destination->id)],
                'assign_to_me' => 'nullable|boolean',
            ],
            'task' => [
                'title' => 'required|string|max:500',
                'notes' => 'nullable|string|max:10000',
                'due_date' => 'nullable|date_format:Y-m-d',
                'assign_to_me' => 'nullable|boolean',
            ],
            'page' => [
                'title' => 'required|string|max:255',
                'markdown' => 'nullable|string|max:500000',
                'publish' => 'nullable|boolean',
            ],
            'bookmark' => [
                'type' => 'required|in:link,note',
                'title' => 'nullable|string|max:500',
                'url' => 'nullable|required_if:type,link|url:http,https|max:2048',
                'content' => 'nullable|string|max:10000',
            ],
            'snippet' => [
                'title' => 'required|string|max:255',
                'content' => 'required|string|max:200000',
                'language' => 'nullable|string|max:50|regex:/^[a-z0-9+#-]+$/',
                'description' => 'nullable|string|max:2000',
            ],
        };
    }

    /**
     * Create the content from validated data (see rules()). Call authorize() first.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \DomainException when the destination can't take content (a board without columns)
     */
    public static function create(User $user, string $type, Model $destination, array $data): Model
    {
        return match ($type) {
            'issue' => self::issue($user, $destination, $data),
            'task' => self::task($user, $destination, $data),
            'page' => self::page($user, $destination, $data),
            'bookmark' => self::bookmarkItem($user, $destination, $data),
            'snippet' => self::snippet($user, $destination, $data),
        };
    }

    /**
     * New issue (Markdown description) in the chosen column — on Scrum boards in the backlog,
     * like issues from plans and whiteboards.
     */
    private static function issue(User $user, Board $board, array $data): Item
    {
        $item = ProjectIssues::create($board, $board->project, $user, [[
            'title' => $data['title'],
            'description' => ! empty($data['description'])
                ? PageContentConverter::markdownToHtml($data['description'])
                : null,
            'assigned_to' => ! empty($data['assign_to_me']) ? $user->id : null,
        ]], isset($data['column_id']) ? $board->columns()->find($data['column_id']) : null)->first();

        // ProjectIssues loads a slim board (no project_id); the locator needs the full one.
        $item->setRelation('board', $board);

        return $item;
    }

    private static function task(User $user, TaskList $taskList, array $data): TaskListItem
    {
        return $taskList->items()->create([
            'title' => $data['title'],
            'description' => ! empty($data['notes'])
                ? ['type' => 'lexical', 'html' => PageContentConverter::markdownToHtml($data['notes'])]
                : null,
            'status' => 'todo',
            'due_date' => $data['due_date'] ?? null,
            'assignee_id' => ! empty($data['assign_to_me']) ? $user->id : null,
            'position' => ($taskList->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);
    }

    /** A draft only the author sees unless `publish` is set (pages workflow). */
    private static function page(User $user, Project $project, array $data): Page
    {
        $page = $project->allPages()->create([
            'name' => $data['title'],
            'type' => 'page',
            'icon' => '🔖',
            'created_by' => $user->id,
            'content' => ! empty($data['markdown'])
                ? PageContentConverter::htmlToPageContent(PageContentConverter::markdownToHtml($data['markdown']))
                : ['type' => 'lexical', 'html' => ''],
        ]);

        if (! empty($data['publish'])) {
            $page->publish($user);
        }

        return $page;
    }

    private static function bookmarkItem(User $user, Bookmark $bookmark, array $data): BookmarkItem
    {
        $item = $bookmark->items()->create([
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'content' => $data['content'] ?? null,
            'position' => ($bookmark->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        if ($item->type === 'link' && $item->url) {
            // only once the row is committed (a conversion runs inside a transaction)
            FetchLinkMetadataJob::dispatch($item->id)->afterCommit();
        }

        return $item;
    }

    /** Code / text into a snippet collection, verbatim. */
    private static function snippet(User $user, SnippetCollection $collection, array $data): Snippet
    {
        return $collection->snippets()->create([
            'title' => $data['title'],
            'content' => $data['content'],
            'language' => $data['language'] ?? 'plaintext',
            'description' => $data['description'] ?? null,
            'visibility' => 'inherit',
            'position' => ($collection->snippets()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);
    }
}

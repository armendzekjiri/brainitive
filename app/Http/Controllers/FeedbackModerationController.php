<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\FeedbackEvent;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\Item;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Feedback\FeedbackModeration;
use App\Support\Feedback\FeedbackText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project → Feedback (v1.42.0, BRAI-60): portal settings, the moderation queue, public status,
 * merge, create / link card and the audit log. Project admins only (ProjectPolicy::update). Posts are
 * resolved inside the URL's project; another project's post id is 404.
 */
class FeedbackModerationController extends Controller
{
    public const TABS = ['pending', 'published', 'rejected', 'merged'];

    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('update', $project);
        $portal = FeedbackPortal::where('project_id', $project->id)->first();
        $user = $request->user();

        $counts = $portal ? [
            'pending' => $portal->posts()->where('moderation', 'pending')->whereNull('merged_into_id')->count(),
            'published' => $portal->posts()->published()->count(),
            'rejected' => $portal->posts()->where('moderation', 'rejected')->whereNull('merged_into_id')->count(),
            'merged' => $portal->posts()->whereNotNull('merged_into_id')->count(),
        ] : array_fill_keys(self::TABS, 0);

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : ($counts['pending'] > 0 ? 'pending' : 'published');

        $posts = collect();
        if ($portal) {
            $query = $portal->posts()->with(['author:id,name,email', 'item:id,key,title,board_id', 'item.board:id,slug', 'mergedInto:id,number,title']);
            match ($tab) {
                'pending' => $query->where('moderation', 'pending')->whereNull('merged_into_id')->orderBy('id'),
                'published' => $query->published()->orderByDesc('votes_count')->orderByDesc('id'),
                'rejected' => $query->where('moderation', 'rejected')->whereNull('merged_into_id')->orderByDesc('id'),
                'merged' => $query->whereNotNull('merged_into_id')->orderByDesc('id'),
            };
            $posts = $query->limit(200)->get();
        }

        return Inertia::render('Projects/Feedback', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'project' => $project->only(['id', 'name', 'slug', 'key']),
            'portal' => $portal ? [
                'enabled' => $portal->enabled,
                'slug' => $portal->slug,
                'title' => $portal->title,
                'intro' => $portal->intro,
                'public_url' => $portal->publicUrl(),
            ] : null,
            'suggestedSlug' => $portal ? null : FeedbackPortal::suggestSlug($project),
            'tab' => $tab,
            'counts' => $counts,
            'posts' => $posts->map(fn (FeedbackPost $post) => $this->presentPost($workspace, $project, $portal, $post))->values(),
            'targets' => $portal ? $portal->posts()->published()->orderByDesc('votes_count')->limit(200)->get(['id', 'number', 'title'])
                ->map(fn (FeedbackPost $p) => ['id' => $p->id, 'number' => $p->number, 'title' => $p->title])->values() : [],
            'boards' => Board::where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'slug', 'project_id'])
                ->filter(fn (Board $board) => $user->can('createItems', $board))
                ->map(fn (Board $board) => ['id' => $board->id, 'name' => $board->name])->values(),
            'events' => $portal ? $portal->events()->with('post:id,number')->latest('id')->limit(30)->get()
                ->map(fn (FeedbackEvent $event) => [
                    'id' => $event->id,
                    'actor_name' => $event->actor_name,
                    'action' => $event->action,
                    'label' => FeedbackEvent::LABELS[$event->action] ?? $event->action,
                    'post_number' => $event->post?->number,
                    'changes' => $event->changes,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values() : [],
            'statuses' => FeedbackPost::STATUS_LABELS,
        ]);
    }

    public function updateSettings(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $portal = FeedbackPortal::where('project_id', $project->id)->first()
            ?? new FeedbackPortal(['project_id' => $project->id, 'created_by' => $request->user()->id]);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:'.FeedbackPortal::SLUG_PATTERN, Rule::unique('feedback_portals', 'slug')->ignore($portal->id)],
            'title' => ['required', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:2000'],
        ], [
            'slug.regex' => 'Use lower-case letters, numbers and dashes (not at the start or end).',
            'slug.unique' => 'Another portal already uses this address.',
        ]);

        $title = FeedbackText::line($data['title'], 120);
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Give the portal a title.']);
        }

        FeedbackModeration::savePortal($portal, [
            'enabled' => (bool) $data['enabled'],
            'slug' => $data['slug'],
            'title' => $title,
            'intro' => FeedbackText::block($data['intro'] ?? null, 2000),
        ], $request->user());

        return back()->with('success', $data['enabled'] ? 'Feedback portal saved. It is public now.' : 'Feedback portal saved. It is switched off.');
    }

    public function moderate(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);

        FeedbackModeration::moderate($post, $request->user(), $data['decision']);

        return back()->with('success', $data['decision'] === 'approve' ? "#{$post->number} is public now." : "#{$post->number} was rejected.");
    }

    public function updateStatus(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);
        $data = $request->validate([
            'status' => ['required', Rule::in(FeedbackPost::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        FeedbackModeration::setStatus($post, $request->user(), $data['status'], FeedbackText::block($data['note'] ?? null, 1000));

        return back()->with('success', "#{$post->number} is now ".FeedbackPost::STATUS_LABELS[$data['status']].'.');
    }

    public function merge(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);
        $data = $request->validate(['target_id' => ['required', 'integer']]);
        $target = FeedbackPost::where('feedback_portal_id', $post->feedback_portal_id)->whereKey($data['target_id'])->first()
            ?? throw ValidationException::withMessages(['target_id' => 'Pick another idea of this portal.']);

        $into = FeedbackModeration::merge($post, $target, $request->user());

        return back()->with('success', "#{$post->number} was merged into #{$into->number}.");
    }

    public function createCard(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);
        $data = $request->validate(['board_id' => ['required', 'integer']]);
        $board = Board::where('project_id', $project->id)->whereKey($data['board_id'])->first()
            ?? throw ValidationException::withMessages(['board_id' => 'Pick a board of this project.']);
        $this->authorize('createItems', $board);

        [$item, $created] = FeedbackModeration::createCard($post, $board, $request->user());

        return back()->with('success', $created ? "Card {$item->key} created." : "#{$post->number} already has card {$item->key}.");
    }

    public function linkCard(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);
        $data = $request->validate([
            'item_id' => ['required_without:key', 'nullable', 'integer'],
            'key' => ['required_without:item_id', 'nullable', 'string', 'max:40'],
        ]);

        // only issues on this project's boards — another project's id or key is "not found"
        $item = Item::query()
            ->whereIn('board_id', Board::where('project_id', $project->id)->select('id'))
            ->when($data['item_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('key', Str::upper(trim($data['key']))))
            ->first()
            ?? throw ValidationException::withMessages(['key' => 'No issue with that key in this project.']);
        $this->authorize('view', $item);

        FeedbackModeration::linkCard($post, $item, $request->user());

        return back()->with('success', "#{$post->number} is linked to {$item->key}.");
    }

    public function unlinkCard(Request $request, Workspace $workspace, Project $project, int $feedbackPost): RedirectResponse
    {
        $this->authorize('update', $project);
        $post = $this->post($project, $feedbackPost);

        FeedbackModeration::unlinkCard($post, $request->user());

        return back()->with('success', "The card was unlinked from #{$post->number}.");
    }

    /** A post of THIS project's portal, or 404. */
    private function post(Project $project, int $id): FeedbackPost
    {
        return FeedbackPost::whereKey($id)
            ->whereHas('portal', fn ($q) => $q->where('project_id', $project->id))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function presentPost(Workspace $workspace, Project $project, FeedbackPortal $portal, FeedbackPost $post): array
    {
        return [
            'id' => $post->id,
            'number' => $post->number,
            'title' => $post->title,
            'body' => $post->body,
            'moderation' => $post->moderation,
            'status' => $post->status,
            'status_note' => $post->status_note,
            'votes_count' => $post->votes_count,
            'author' => $post->author ? ['name' => $post->author->displayName(), 'email' => $post->author->email] : null,
            'created_at' => $post->created_at?->toIso8601String(),
            'public_url' => route('feedback.post', [$portal->slug, $post->number]),
            'merged_into' => $post->mergedInto ? ['number' => $post->mergedInto->number, 'title' => $post->mergedInto->title] : null,
            'item' => $post->item && $post->item->board ? [
                'key' => $post->item->key,
                'title' => $post->item->title,
                'url' => route('boards.items.show', [$workspace->slug, $project->slug, $post->item->board->slug, $post->item->id]),
            ] : null,
        ];
    }
}

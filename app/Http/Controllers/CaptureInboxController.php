<?php

namespace App\Http\Controllers;

use App\Models\Capture;
use App\Support\Captures\CaptureConversion;
use App\Support\Captures\CaptureDestinations;
use App\Support\Captures\CaptureFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quick capture (BRAI-70): a private inbox of ideas captured without a project, organized
 * later into an issue, list task, doc, bookmark or snippet. Every query is scoped to the
 * signed-in user; other people's captures are 404 (CapturePolicy).
 */
class CaptureInboxController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $state = in_array($request->query('state'), Capture::STATES, true) ? $request->query('state') : 'inbox';
        $search = trim((string) $request->query('q', ''));

        $page = Capture::ownedBy($user)
            ->inState($state)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(fn ($q) => $q->whereLike('text', $like)->orWhereLike('url', $like)->orWhereLike('source', $like));
            })
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        $captures = collect($page->items());
        $locations = CaptureConversion::locations($captures);

        $counts = [];
        foreach (Capture::STATES as $s) {
            $counts[$s] = Capture::ownedBy($user)->inState($s)->count();
        }

        return Inertia::render('Captures/Index', [
            'captures' => $captures->map(fn (Capture $c) => $this->present($c, $locations[$c->id] ?? null))->values(),
            'state' => $state,
            'search' => $search,
            'counts' => $counts,
            'pagination' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /** Capture an idea without choosing a project (top bar, shortcut C, mobile button). */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(Capture::rules());

        Capture::create(['user_id' => $request->user()->id] + $validated);

        return back()->with('success', 'Saved to your captures.');
    }

    public function update(Request $request, Capture $capture): RedirectResponse
    {
        $this->authorize('update', $capture);

        $capture->update($request->validate(Capture::rules()));

        return back()->with('success', 'Capture updated.');
    }

    public function archive(Capture $capture): RedirectResponse
    {
        $this->authorize('update', $capture);

        $capture->update(['archived_at' => $capture->archived_at ?? now()]);

        return back()->with('success', 'Capture archived.');
    }

    public function restore(Capture $capture): RedirectResponse
    {
        $this->authorize('update', $capture);

        $capture->update(['archived_at' => null]);

        return back()->with('success', $capture->processed_at ? 'Capture restored to Processed.' : 'Capture restored to your inbox.');
    }

    /** Permanently delete — only archived captures, so nothing disappears by accident. */
    public function destroy(Capture $capture): RedirectResponse
    {
        $this->authorize('delete', $capture);
        abort_if($capture->archived_at === null, 422, 'Archive a capture before deleting it.');

        $capture->delete();

        return back()->with('success', 'Capture deleted.');
    }

    /** Where the user can organize captures into (same list as the browser extension). */
    public function destinations(Request $request): JsonResponse
    {
        return response()->json(['workspaces' => CaptureDestinations::for($request->user())]);
    }

    /** The fields a capture becomes for one destination type (edited before converting). */
    public function preview(Request $request, Capture $capture): JsonResponse
    {
        $this->authorize('view', $capture);
        $data = $request->validate(['type' => ['required', Rule::in(Capture::TYPES)]]);

        return response()->json(['fields' => CaptureFields::defaults($capture, $data['type'])]);
    }

    /**
     * Organize a capture into content. Atomic + idempotent (CaptureConversion): a retry returns
     * the content created first (200), a new conversion 201.
     */
    public function convert(Request $request, Capture $capture): JsonResponse
    {
        $this->authorize('convert', $capture);

        $data = $request->validate([
            'type' => ['required', Rule::in(Capture::TYPES)],
            'destination_id' => 'required|integer',
            'fields' => 'required|array',
        ]);

        $result = CaptureConversion::convert($request->user(), $capture, $data['type'], (int) $data['destination_id'], $data['fields']);
        $capture = $result['capture'];

        return response()->json([
            'replayed' => $result['replayed'],
            'location' => $result['location'],
            'capture' => $this->present($capture, CaptureConversion::location($capture)),
        ], $result['replayed'] ? 200 : 201);
    }

    /**
     * Field by field, never the model.
     *
     * @param  array<string, mixed>|null  $location
     * @return array<string, mixed>
     */
    private function present(Capture $capture, ?array $location): array
    {
        return [
            'id' => $capture->uuid,
            'text' => $capture->text,
            'url' => $capture->url,
            'intended_type' => $capture->intended_type,
            'source' => $capture->source,
            'state' => $capture->state(),
            'captured_at' => $capture->captured_at?->toIso8601String(),
            'processed_at' => $capture->processed_at?->toIso8601String(),
            'archived_at' => $capture->archived_at?->toIso8601String(),
            'target' => $capture->target_type ? [
                'type' => $capture->target_type,
                'title' => $location['title'] ?? '',
                'breadcrumb' => $location['breadcrumb'] ?? '',
                'url' => $location['url'] ?? null,
                'available' => (bool) ($location['available'] ?? false),
            ] : null,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Page;
use App\Models\ShareLink;
use App\Support\Legal\PublicOperator;
use App\Support\Mcp\PageContentConverter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Secure sharing of a single doc or issue: people who can edit it create, list and revoke
 * links; anyone with an active link gets a read-only view of just that item.
 */
class ShareLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $model = $this->shareable($request);

        return response()->json(['links' => $this->links($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $model = $this->shareable($request);
        $validated = $request->validate(['expires_in_days' => 'nullable|integer|in:1,7,30,90']);

        $model->morphMany(ShareLink::class, 'shareable')->create([
            'created_by' => $request->user()->id,
            'expires_at' => isset($validated['expires_in_days']) ? now()->addDays($validated['expires_in_days']) : null,
        ]);

        return response()->json(['links' => $this->links($model)], 201);
    }

    public function destroy(Request $request, ShareLink $shareLink): JsonResponse
    {
        $model = $shareLink->shareable;
        abort_unless($model && $request->user()->can('update', $model), 403);

        $shareLink->update(['revoked_at' => now()]);

        return response()->json(['links' => $this->links($model)]);
    }

    /**
     * Public, read-only view. Revoked / expired links and deleted items look the same: gone.
     */
    public function show(Request $request, string $token): SymfonyResponse
    {
        $link = ShareLink::active()->where('token', $token)->first();
        $model = $link?->shareable; // soft-deleted items resolve to null

        if (! $link || ! $model) {
            return Inertia::render('Share/Show', ['shared' => null])->toResponse($request)->setStatusCode(410)
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $link->increment('view_count', 1, ['last_viewed_at' => now()]);

        $shared = $model instanceof Page
            ? [
                'type' => 'doc',
                'title' => $model->name,
                'icon' => $model->icon,
                'html' => PageContentConverter::pageContentToHtml($model->content),
                'updated_at' => $model->updated_at?->toIso8601String(),
            ]
            : [
                'type' => 'issue',
                'key' => $model->key,
                'title' => $model->title,
                'html' => (string) $model->description,
                'status' => $model->column?->name,
                'item_type' => $model->itemType?->name,
                'priority' => $model->priority?->name,
                'start_date' => $model->start_date?->toDateString(),
                'due_date' => $model->due_date?->toDateString(),
                'updated_at' => $model->updated_at?->toIso8601String(),
            ];

        $workspace = $model instanceof Page ? $model->project?->workspace : $model->board?->project?->workspace;

        return Inertia::render('Share/Show', ['shared' => $shared, 'operator' => PublicOperator::for($workspace)])->toResponse($request)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** The doc / issue from the request, if the user may share it (edit rights). */
    private function shareable(Request $request): Model
    {
        $validated = $request->validate([
            'type' => 'required|in:page,item',
            'id' => 'required|string|max:64',
        ]);

        $model = $validated['type'] === 'page'
            ? Page::where('uuid', $validated['id'])->first()
            : (ctype_digit($validated['id']) ? Item::find((int) $validated['id']) : null);

        abort_unless($model && $request->user()->can('update', $model), 404);
        abort_if($model instanceof Page && ($model->is_whiteboard || $model->type !== 'page'), 422, 'Only docs and issues can be shared with a link.');

        return $model;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function links(Model $model): array
    {
        return $model->morphMany(ShareLink::class, 'shareable')
            ->active()
            ->with('creator:id,name')
            ->latest()
            ->get()
            ->map(fn (ShareLink $l) => [
                'id' => $l->id,
                'url' => $l->url(),
                'created_by' => $l->creator?->name,
                'created_at' => $l->created_at?->toIso8601String(),
                'expires_at' => $l->expires_at?->toIso8601String(),
                'view_count' => $l->view_count,
                'last_viewed_at' => $l->last_viewed_at?->toIso8601String(),
            ])
            ->all();
    }
}

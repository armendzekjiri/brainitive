<?php

namespace App\Http\Controllers;

use App\Models\PageStickyNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PageStickyNoteController extends Controller
{
    /**
     * Return all sticky notes for the authenticated user on a given URL.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string|max:1000',
        ]);

        $notes = PageStickyNote::where('user_id', Auth::id())
            ->where('url', $request->query('url'))
            ->orderBy('created_at')
            ->get();

        return response()->json($notes);
    }

    /**
     * Create a new sticky note.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url'       => 'required|string|max:1000',
            'color'     => 'sometimes|string|in:yellow,pink',
            'x'         => 'sometimes|integer',
            'y'         => 'sometimes|integer',
            'width'     => 'sometimes|integer|min:100|max:800',
            'font_size' => 'sometimes|integer|min:10|max:40',
            'content'   => 'sometimes|nullable|string|max:10000',
        ]);

        $note = PageStickyNote::create(array_merge($validated, [
            'user_id' => Auth::id(),
        ]));

        return response()->json($note, 201);
    }

    /**
     * Update position, size, color or content of a sticky note.
     */
    public function update(Request $request, PageStickyNote $note): JsonResponse
    {
        abort_if($note->user_id !== Auth::id(), 403);

        $validated = $request->validate([
            'color'     => 'sometimes|string|in:yellow,pink',
            'x'         => 'sometimes|integer',
            'y'         => 'sometimes|integer',
            'width'     => 'sometimes|integer|min:100|max:800',
            'font_size' => 'sometimes|integer|min:10|max:40',
            'content'   => 'sometimes|nullable|string|max:10000',
        ]);

        $note->update($validated);

        return response()->json($note);
    }

    /**
     * Delete a sticky note.
     */
    public function destroy(PageStickyNote $note): JsonResponse
    {
        abort_if($note->user_id !== Auth::id(), 403);

        $note->delete();

        return response()->json(['ok' => true]);
    }
}

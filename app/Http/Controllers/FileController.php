<?php

namespace App\Http\Controllers;

use App\Support\Files\EditorUploads;
use Illuminate\Http\Request;

class FileController extends Controller
{
    /**
     * Upload an image for the editor.
     * Editor.js expects: { success: 1, file: { url: '...' } }
     */
    public function uploadImage(Request $request)
    {
        $request->validate(['image' => EditorUploads::IMAGE_RULES]); // 10MB max; no SVG (scripts on our origin)
        $workspace = EditorUploads::workspaceFor($request); // 422 / 404 (Codex review 8)

        // shared with the App API's POST /uploads: reserves the bytes (402 when full), counted in the usage
        $stored = EditorUploads::store($request->file('image'), true, $workspace, $request->user());

        // Editor.js format response
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $stored['url'],
                'name' => $stored['name'],
                'size' => $stored['size'],
            ],
        ]);
    }

    /**
     * Upload a document (PDF, Word, etc.) for attachments.
     * Editor.js Attaches expects: { success: 1, file: { url: '...', name: '...', size: ... } }
     */
    public function uploadDocument(Request $request)
    {
        $request->validate(['document' => EditorUploads::DOCUMENT_RULES]); // 20MB max
        $workspace = EditorUploads::workspaceFor($request);

        $stored = EditorUploads::store($request->file('document'), false, $workspace, $request->user());

        // Editor.js Attaches format response
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $stored['url'],
                'name' => $stored['name'],
                'size' => $stored['size'],
                'title' => $stored['name'],
                'extension' => $stored['extension'],
            ],
        ]);
    }
}

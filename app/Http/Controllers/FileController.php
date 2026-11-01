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

        // shared with the App API's POST /uploads
        $stored = EditorUploads::storeImage($request->file('image'));

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

        $stored = EditorUploads::storeDocument($request->file('document'));

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

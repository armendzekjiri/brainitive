<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    /**
     * Upload an image for the editor.
     * Editor.js expects: { success: 1, file: { url: '...' } }
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,gif,webp,svg|max:10240', // 10MB max
        ]);

        $file = $request->file('image');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('uploads/images', $filename, 'public');

        // Editor.js format response
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::url($path),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ],
        ]);
    }

    /**
     * Upload a document (PDF, Word, etc.) for attachments.
     * Editor.js Attaches expects: { success: 1, file: { url: '...', name: '...', size: ... } }
     */
    public function uploadDocument(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar|max:20480', // 20MB max
        ]);

        $file = $request->file('document');
        $filename = Str::uuid() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('uploads/documents', $filename, 'public');

        // Editor.js Attaches format response
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::url($path),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'title' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
            ],
        ]);
    }
}

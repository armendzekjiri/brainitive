<?php

namespace App\Http\Controllers;

use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    /**
     * Upload an image for the editor.
     * Editor.js expects: { success: 1, file: { url: '...' } }
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,gif,webp|max:10240', // 10MB max; no SVG (scripts on our origin)
        ]);

        $file = $request->file('image');
        $filename = Uploads::imageName($file);
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
        $filename = Uploads::name($file);
        $path = $file->storeAs('uploads/documents', $filename, 'public');

        // Editor.js Attaches format response
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::url($path),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'title' => $file->getClientOriginalName(),
                'extension' => Uploads::extension($file),
            ],
        ]);
    }
}

<?php

namespace App\Http\Requests\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Models\Workspace;
use App\Support\Files\EditorUploads;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * `POST /uploads`: an image or document for rich text, the web editor's rules (EditorUploads): images
 * jpeg / png / gif / webp ≤ 10 MB, documents pdf / office / txt / csv / zip / rar ≤ 20 MB. Authorized before
 * validation: a workspace the user isn't in is a 404.
 */
class StoreUploadRequest extends FormRequest
{
    use ResolvesVisible;

    private ?Workspace $resolvedWorkspace = null;

    public function authorize(): bool
    {
        $this->workspace();

        return true;
    }

    public function workspace(): Workspace
    {
        $slug = $this->input('workspace');
        $workspace = is_string($slug) && $slug !== '' ? Workspace::where('slug', $slug)->first() : null;
        abort_unless($workspace instanceof Workspace && $this->user()->hasWorkspaceAccess($workspace), 404);

        return $this->resolvedWorkspace ??= $workspace;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the workspace (slug) whose storage the file counts against: the one of the doc / issue it goes into
            'workspace' => ['required', 'string', 'max:255'],
            // multipart: an image (jpeg, png, gif, webp ≤ 10 MB) or a document (pdf, doc(x), xls(x), ppt(x), txt, csv, zip, rar ≤ 20 MB)
            'file' => ['required', 'file', 'mimes:'.implode(',', [...EditorUploads::IMAGE_MIMES, ...EditorUploads::DOCUMENT_MIMES]), 'max:'.EditorUploads::DOCUMENT_MAX_KB],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->isImage() && $this->upload()->getSize() > EditorUploads::IMAGE_MAX_KB * 1024) {
                $validator->errors()->add('file', 'Images may be 10 MB at most.');
            }
        }];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /** By the file's content, never its name. */
    public function isImage(): bool
    {
        return in_array(strtolower((string) $this->upload()->guessExtension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }
}

<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `PUT /whiteboards/{uuid}/collab/scene`: the scene of the live whiteboard, stored as its content — the web's
 * `pages.whiteboard.collab.save` body. `scene` and `vector` are JSON strings (the browser's FormData) or the
 * same JSON as objects.
 */
class WhiteboardSceneRequest extends PageRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the epoch of the session the client is connected to
            'epoch' => ['required', 'integer', 'min:0'],
            // the live document's scene as JSON: {elements, appState, files, libraryItems?}; `{}` with `baseline`
            'scene' => $this->jsonRule('scene', 52428800),
            // the Yjs state vector the scene was made from, as JSON ({client id: clock})
            'vector' => $this->jsonRule('vector', 200000),
            // true once, from the client that loaded the stored elements into an empty document: records the vector, writes nothing
            'baseline' => ['sometimes', 'boolean'],
            // Save / Save & exit: record a version even when nothing changed
            'checkpoint' => ['sometimes', 'boolean'],
            // the preview PNG of "Save & exit" (multipart: POST with `_method=PUT`)
            'image' => ['nullable', 'image', 'mimes:png', 'max:10240'],
        ];
    }

    /**
     * A JSON string (as the browser sends it) or the decoded object; `{}` decodes to an empty array, so `present`.
     *
     * @return list<string>
     */
    private function jsonRule(string $key, int $maxChars): array
    {
        return is_array($this->input($key)) ? ['present', 'array'] : ['required', 'string', 'max:'.$maxChars];
    }
}

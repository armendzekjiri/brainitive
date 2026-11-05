<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Contracts\Validation\ValidationRule;

/** `POST /pages/{uuid}/collab/bootstrap` and `POST /whiteboards/{uuid}/collab/bootstrap`: an editor's client synced an empty live document. */
class CollabBootstrapRequest extends PageRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // the epoch of the session the client is connected to
            'epoch' => ['required', 'integer', 'min:0'],
            // a random id of this client's attempt (16–64 of A-Z a-z 0-9 _ -), the same on retries
            'claim' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ];
    }
}

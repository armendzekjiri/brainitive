<?php

namespace App\Http\Requests\AppApi;

use App\Models\Capture;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A request about one of the user's captures (BRAI-398). The owner check runs before validation, so
 * somebody else's capture (or a malformed id) is a 404 whatever the body (CapturePolicy: private).
 */
abstract class CaptureRequest extends FormRequest
{
    private ?Capture $capture = null;

    public function authorize(): bool
    {
        $this->ownedCapture();

        return true;
    }

    public function ownedCapture(): Capture
    {
        if ($this->capture === null) {
            $id = (string) $this->route('capture');
            $capture = Str::isUuid($id) ? Capture::ownedBy($this->user())->where('uuid', $id)->first() : null;
            abort_unless($capture instanceof Capture, 404);
            $this->capture = $capture;
        }

        return $this->capture;
    }
}

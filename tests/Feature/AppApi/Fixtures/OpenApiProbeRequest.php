<?php

namespace Tests\Feature\AppApi\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

class OpenApiProbeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }
}

<?php

namespace App\Http\Requests\AppApi;

use App\Support\Live\LiveChannels;
use App\Support\Live\LiveConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/realtime/token`: the live channels to join (404 while live channels are off, before any 422). */
class RealtimeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(LiveConfig::enabled($this), 404, 'Live updates are off on this server.');

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // channel names `<type>.<id>`: user.{id} (own), board.{id}, item.{id}, plan.{id}, ticket.{id}, page.{uuid}
            'channels' => ['required', 'array', 'list', 'min:1', 'max:'.LiveChannels::MAX_PER_TOKEN],
            'channels.*' => ['required', 'string', 'max:100'],
        ];
    }
}

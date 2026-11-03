<?php

namespace App\Http\Requests\AppApi;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/v1/bookings/{uuid}/cancel`: the host cancels; the guest gets an email with the reason. */
class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // shown to the guest in the cancellation email and on the card
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

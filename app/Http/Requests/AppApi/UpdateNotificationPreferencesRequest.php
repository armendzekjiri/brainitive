<?php

namespace App\Http\Requests\AppApi;

use App\Notifications\NotificationCatalog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/v1/me/notification-preferences` (BRAI-393): Profile → Notifications. Partial: only the
 * sent categories and channels change — `{preferences: {"mentions": {"push": false}}}`.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return NotificationCatalog::preferenceRules();
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Controllers\ApiKeyController;
use App\Models\ApiKey;
use App\Support\ApiKeys\ApiKeyAreas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Profile → API keys → Create (Developer API, v1.52.0). The chosen workspaces / projects / boards must be ones the
 * user can open now (the key would never reach anything else anyway); at least one area must be granted.
 */
class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $areas = [];
        foreach (ApiKeyAreas::keys() as $area) {
            $areas['abilities.'.$area] = ['nullable', 'string', Rule::in(ApiKeyAreas::LEVELS)];
        }

        return [
            'name' => ['required', 'string', 'max:100'],
            'scope_type' => ['required', 'string', Rule::in(ApiKey::SCOPE_TYPES)],
            'scope_ids' => ['nullable', 'array', 'max:100'],
            'scope_ids.*' => ['integer', 'min:1'],
            'abilities' => ['required', 'array'],
            ...$areas,
            'can_delete' => ['boolean'],
            'expires_in_days' => ['nullable', 'integer', Rule::in(array_values(array_filter(config('api-keys.expiry_days'))))],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $user = $this->user();

            if ($user->apiKeys()->active()->count() >= (int) config('api-keys.max_per_user')) {
                $validator->errors()->add('name', 'You have the maximum number of active API keys. Revoke one first.');
            }

            $abilities = ApiKeyAreas::normalize((array) $this->input('abilities', []));
            if (! in_array(ApiKeyAreas::READ, $abilities, true) && ! in_array(ApiKeyAreas::WRITE, $abilities, true)) {
                $validator->errors()->add('abilities', 'Give the key access to at least one area.');
            }

            $type = (string) $this->input('scope_type');
            if ($type === ApiKey::SCOPE_ALL) {
                return;
            }
            $ids = $this->scopeIds();
            if ($ids === []) {
                $validator->errors()->add('scope_ids', 'Choose at least one '.rtrim($type, 's').'.');

                return;
            }

            $options = ApiKeyController::options($user);
            $allowed = collect(match ($type) {
                ApiKey::SCOPE_WORKSPACES => $options['workspaces'],
                ApiKey::SCOPE_PROJECTS => $options['projects'],
                default => $options['boards'],
            })->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($ids, $allowed) !== []) {
                $validator->errors()->add('scope_ids', 'Choose only '.$type.' you can open.');
            }
        }];
    }

    /** @return list<int> */
    public function scopeIds(): array
    {
        if ($this->input('scope_type') === ApiKey::SCOPE_ALL) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter((array) $this->input('scope_ids', []), 'is_numeric'))));
    }

    public function expiresInDays(): ?int
    {
        $days = $this->input('expires_in_days');

        return $days === null || $days === '' ? null : (int) $days;
    }
}

<?php

namespace App\Support\Calendar;

use Illuminate\Support\Facades\Crypt;

/**
 * A connect flow waiting for the user to pick calendars (the picker dialog on the calendar
 * page, opened by calendar.index?picking=<provider>). Lives in the session; the secrets
 * (OAuth tokens, CalDAV credentials) are stored encrypted. calendar.connections.store turns
 * the picked calendars into CalendarConnection rows and copies the secrets onto each.
 *
 * secrets keys: access_token, refresh_token, expires_in (seconds), credentials (array).
 */
final class PendingConnection
{
    public const SESSION_KEY = 'calendar_connect_pending';

    /**
     * @param  list<array{id: string, name: string, color: string|null, primary: bool, read_only: bool}>  $calendars
     * @param  list<array{id: string, title: string}>  $taskLists  Google Tasks only
     * @param  array{access_token?: string|null, refresh_token?: string|null, expires_in?: int|null, credentials?: array<string, mixed>|null}  $secrets
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $accountEmail,
        public readonly ?string $accountName,
        public readonly array $calendars,
        public readonly array $taskLists = [],
        public readonly array $secrets = [],
        public readonly ?string $serverUrl = null,
    ) {}

    public function put(): void
    {
        session()->put(self::SESSION_KEY, [
            'provider' => $this->provider,
            'account_email' => $this->accountEmail,
            'account_name' => $this->accountName,
            'calendars' => $this->calendars,
            'task_lists' => $this->taskLists,
            'server_url' => $this->serverUrl,
            'secrets' => Crypt::encrypt($this->secrets),
        ]);
    }

    public static function current(): ?self
    {
        $data = session()->get(self::SESSION_KEY);
        // No `secrets` = a pre-BRAI-140 payload (other calendar keys): the user connects again.
        if (! is_array($data) || ! isset($data['provider'], $data['account_email'], $data['secrets'])) {
            return null;
        }

        try {
            $secrets = (array) Crypt::decrypt($data['secrets']);
        } catch (\Throwable) {
            return null;
        }

        return new self(
            (string) $data['provider'],
            (string) $data['account_email'],
            $data['account_name'] ?? null,
            (array) ($data['calendars'] ?? []),
            (array) ($data['task_lists'] ?? []),
            $secrets,
            $data['server_url'] ?? null,
        );
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Columns copied onto every connection created from this flow.
     *
     * @return array<string, mixed>
     */
    public function connectionAttributes(): array
    {
        $attributes = ['account_name' => $this->accountName, 'is_active' => true];
        if (array_key_exists('access_token', $this->secrets)) {
            $attributes['access_token'] = $this->secrets['access_token'];
            $attributes['refresh_token'] = $this->secrets['refresh_token'] ?? null;
            $attributes['token_expires_at'] = now()->addSeconds(max(0, (int) ($this->secrets['expires_in'] ?? 3600) - 60));
        }
        if (! empty($this->secrets['credentials'])) {
            $attributes['credentials'] = $this->secrets['credentials'];
        }
        if ($this->serverUrl !== null) {
            $attributes['server_url'] = $this->serverUrl;
        }

        return $attributes;
    }

    /**
     * What the picker dialog may see (no secrets).
     *
     * @return array<string, mixed>
     */
    public function forPicker(string $providerLabel): array
    {
        return [
            'provider' => $this->provider,
            'provider_label' => $providerLabel,
            'account_email' => $this->accountEmail,
            'account_name' => $this->accountName,
            'calendars' => $this->calendars,
            'task_lists' => $this->taskLists,
        ];
    }
}

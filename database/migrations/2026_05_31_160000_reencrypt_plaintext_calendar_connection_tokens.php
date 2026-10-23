<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Heal calendar_connections rows whose access/refresh tokens were written in
 * plaintext.
 *
 * A bulk query-builder update() in CalendarConnectionController::store()
 * bypassed the model's `encrypted` cast and wrote raw OAuth tokens into columns
 * that are read back as encrypted payloads — causing a DecryptException ("The
 * payload is invalid.") for every already-affected user the moment those rows
 * were read.
 *
 * This migration re-encrypts any token column that is currently NOT a valid
 * encrypted payload, leaving already-encrypted (and null) values untouched, so
 * it is safe to run repeatedly and across all environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('calendar_connections')->orderBy('id')->each(function ($row) {
            $updates = [];

            foreach (['access_token', 'refresh_token'] as $column) {
                $value = $row->{$column} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                // If it already decrypts, it's a valid encrypted payload — skip.
                try {
                    Crypt::decryptString($value);

                    continue;
                } catch (Throwable $e) {
                    // Not decryptable → assume plaintext and re-encrypt it.
                    $updates[$column] = Crypt::encryptString($value);
                }
            }

            if ($updates) {
                DB::table('calendar_connections')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // No-op: we cannot (and should not) revert encrypted tokens to plaintext.
    }
};

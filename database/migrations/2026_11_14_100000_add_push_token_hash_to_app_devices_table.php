<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile push (BRAI-448): a SHA-256 (hex) of the push token next to the encrypted token, so the rows
 * holding one token can be found across accounts — an Expo token belongs to one install, and when the
 * app sets it on its device every other row with it loses it (a phone signed into another account is
 * no longer pushed for that account). Kept in sync by AppDevice on every save; never exposed.
 * Existing tokens are hashed here (decrypted in PHP; one that can't be decrypted stays unhashed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_devices', function (Blueprint $table) {
            $table->char('push_token_hash', 64)->nullable()->index();
        });

        DB::table('app_devices')->whereNotNull('push_token')->select(['id', 'push_token'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    try {
                        $token = Crypt::decryptString($row->push_token);
                    } catch (DecryptException) {
                        continue; // encrypted with an old APP_KEY: unreadable to the app as well
                    }
                    if ($token !== '') {
                        DB::table('app_devices')->where('id', $row->id)->update(['push_token_hash' => hash('sha256', $token)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('app_devices', function (Blueprint $table) {
            $table->dropIndex(['push_token_hash']);
            $table->dropColumn('push_token_hash');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contact-wide ticket tokens could be minted by anyone who submitted a ticket with the
 * contact's email and opened all of that contact's tickets. Links now use per-ticket
 * tokens (Ticket::publicAccessToken()); invalidate every old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ticket_contacts')->update(['access_token' => null, 'access_token_expires_at' => null]);
    }

    public function down(): void
    {
        //
    }
};

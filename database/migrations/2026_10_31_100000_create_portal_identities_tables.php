<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest identities (v1.42.0 foundation): people outside the workspace — feedback-portal voters
 * (BRAI-60) and invited clients (BRAI-73) — prove their email with a one-time sign-in link.
 * A guest is never a workspace member; a signed-in Brainitive user acts through an identity
 * linked by user_id. Contract: docs/ai/tasks/release-v1.42.0.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_identities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email')->unique(); // always lower-case (PortalIdentity::normalizeEmail)
            $table->string('name')->nullable();
            // set when the identity belongs to a Brainitive account (same verified email)
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_identity_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 of the emailed token; the token itself is never stored
            $table->string('return_to', 500)->nullable(); // a safe local path (PortalAuth::safeReturnTo)
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_login_tokens');
        Schema::dropIfExists('portal_identities');
    }
};

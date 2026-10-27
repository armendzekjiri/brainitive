<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal (BRAI-156): who is responsible for the workspace's public pages (support pages, lead
     * forms, feedback / client portals, share links) — shown on those pages with links to the
     * customer's own privacy policy and imprint. Https URLs only (WorkspaceLegalController).
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('legal_controller_name', 200)->nullable();
            $table->string('legal_privacy_url', 2048)->nullable();
            $table->string('legal_imprint_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['legal_controller_name', 'legal_privacy_url', 'legal_imprint_url']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal (BRAI-156): proof of the consent checkbox on a lead form — when the visitor ticked it
     * and the exact text they saw. Null = the form had no checkbox, or an optional one left unticked.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable();
            $table->text('consent_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['consent_at', 'consent_text']);
        });
    }
};

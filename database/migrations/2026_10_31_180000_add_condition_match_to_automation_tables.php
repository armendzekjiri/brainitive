<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flexible automation conditions (BRAI-144):
 *  - automation_rules.condition_match: "all" (every condition must hold, as before) or "any".
 *  - automation_runs.conditions: the conditions as checked for the run ({match, items: [{label,
 *    passed}]}), shown in the run history. Runs from before have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->string('condition_match', 3)->default('all')->after('trigger_config');
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('conditions')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn('conditions');
        });
        Schema::table('automation_rules', function (Blueprint $table) {
            $table->dropColumn('condition_match');
        });
    }
};

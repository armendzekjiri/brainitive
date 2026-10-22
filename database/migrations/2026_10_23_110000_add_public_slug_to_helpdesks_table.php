<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Short public address for a helpdesk's support page: /support/{public_slug}. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesks', function (Blueprint $table) {
            $table->string('public_slug', 60)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('helpdesks', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn('public_slug');
        });
    }
};

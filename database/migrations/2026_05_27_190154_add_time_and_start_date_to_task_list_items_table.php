<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_list_items', function (Blueprint $table) {
            $table->time('due_time')->nullable()->after('due_date');
            $table->date('start_date')->nullable()->after('due_time');
            $table->time('start_time')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('task_list_items', function (Blueprint $table) {
            $table->dropColumn(['due_time', 'start_date', 'start_time']);
        });
    }
};

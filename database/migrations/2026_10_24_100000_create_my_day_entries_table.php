<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A user's hand-picked plan for one (local) day: board items and list tasks.
        Schema::create('my_day_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('work_type', 10); // board | list
            $table->unsignedBigInteger('work_id');
            $table->date('plan_date');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'work_type', 'work_id', 'plan_date']);
            $table->index(['user_id', 'plan_date']);
        });

        // The one running focus timer per user (unique user_id).
        Schema::create('work_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_timers');
        Schema::dropIfExists('my_day_entries');
    }
};

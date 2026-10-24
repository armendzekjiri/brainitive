<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time tracking & client billing foundation (v1.38.0, BRAI-99/100/101), the shared contract in
 * docs/ai/tasks/release-v1.38.0-billing.md:
 * - activities the workspace admin defines, priced per hour or as a fixed price;
 * - the workspace's clients, assigned to projects;
 * - one row per piece of logged work, the ledger behind items.time_spent and every billing report.
 * Money is integer cents, tax is basis points (19 % = 1900). `billing_report_id` gets its foreign
 * key with the billing_reports table (BRAI-101); set means billed = locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('pricing_type', 10); // hourly | fixed
            $table->unsignedBigInteger('rate_cents')->default(0);
            $table->boolean('billable')->default(true);
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('vat_id', 40)->nullable();
            $table->unsignedSmallInteger('tax_rate_bp')->nullable(); // null = the workspace's default VAT
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('workspace_id')->constrained()->nullOnDelete();
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_activity_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('spent_on');
            $table->unsignedInteger('minutes');
            $table->text('note')->nullable();
            $table->boolean('billable')->default(true);
            $table->string('source', 10)->default('manual'); // manual | timer | legacy
            $table->string('item_key', 40)->nullable();
            $table->string('item_title')->nullable();
            $table->unsignedBigInteger('billing_report_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'spent_on']);
            $table->index(['project_id', 'spent_on']);
            $table->index(['user_id', 'spent_on']);
            $table->index('item_id');
            $table->index('billing_activity_id');
            $table->index('billing_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::dropIfExists('clients');
        Schema::dropIfExists('billing_activities');
    }
};

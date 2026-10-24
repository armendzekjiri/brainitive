<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client billing foundation (v1.38.0): one settings row per workspace (issuer details, VAT,
 * rounding, invoice numbering) and the billing reports themselves. A report is computed live while
 * it is a draft; finalizing freezes `snapshot` (shape: docs/ai/tasks/release-v1.38.0-billing.md)
 * and locks its time entries through time_entries.billing_report_id. Money in cents, tax in bp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('default_tax_rate_bp')->default(0);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedSmallInteger('rounding_minutes')->default(0); // 0 | 5 | 15 | 30, per entry, reports only
            $table->boolean('invoice_mode')->default(false);
            $table->string('number_prefix', 20)->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->string('company_name', 160)->nullable();
            $table->text('company_address')->nullable();
            $table->string('company_vat_id', 40)->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone', 40)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('iban', 40)->nullable();
            $table->string('bic', 20)->nullable();
            $table->text('footer')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('report'); // report | invoice
            $table->string('number', 40)->nullable();
            $table->string('title', 160)->nullable();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 10)->default('draft'); // draft | finalized | sent | paid | void
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0);
            $table->boolean('prices_include_tax')->default(false);
            $table->unsignedInteger('total_minutes')->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->json('options')->nullable();  // draft config: project_ids, group_by, show_people, show_notes, excluded_entry_ids
            $table->json('snapshot')->nullable(); // frozen at finalize
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index('client_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreign('billing_report_id')->references('id')->on('billing_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['billing_report_id']);
        });
        Schema::dropIfExists('billing_reports');
        Schema::dropIfExists('billing_settings');
    }
};

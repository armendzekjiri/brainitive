<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GitHub App connect (v1.42.0, BRAI-61): one app per instance, its installations, the repositories
 * each project follows, the app's webhook deliveries, plus merge transitions (primary PR).
 * Additive only: manual-webhook connections keep working as before (mode = webhook).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The app created through GitHub's manifest flow (one row). GITHUB_APP_* env vars override it.
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_id');
            $table->string('slug', 100);
            $table->string('name', 100)->nullable();
            $table->string('owner', 100)->nullable();
            $table->string('html_url', 300)->nullable();
            $table->string('client_id', 100)->nullable();
            $table->text('client_secret')->nullable(); // encrypted
            $table->text('private_key'); // encrypted
            $table->text('webhook_secret'); // encrypted
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('github_installations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installation_id')->unique();
            $table->string('account_login', 100)->nullable();
            $table->string('account_type', 30)->nullable();
            $table->string('repository_selection', 20)->nullable();
            $table->json('repositories')->nullable(); // [{id, full_name, private}] the app can see
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('removed_at')->nullable(); // uninstalled on GitHub
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('github_connections', function (Blueprint $table) {
            $table->string('mode', 20)->default('webhook'); // webhook (legacy) | app
            $table->foreignId('github_installation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('access_lost_at')->nullable(); // set once per loss → one notification
            $table->timestamp('rate_limited_until')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
        });

        // Repositories a project follows through the app: a repository maps to one project.
        Schema::create('github_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('github_installation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('repo_id')->unique();
            $table->string('full_name', 200);
            $table->boolean('private')->default(false);
            $table->timestamp('removed_at')->nullable(); // the installation lost access to it
            $table->timestamps();

            $table->index(['github_installation_id', 'repo_id']);
        });

        Schema::create('github_app_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 100)->unique();
            $table->string('event', 50);
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->foreignId('github_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('payload');
            $table->string('status', 20)->default('pending'); // pending | processed | ignored | failed
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::table('item_dev_links', function (Blueprint $table) {
            // The PR whose merge moves the card (merge transitions); none marked = the first linked PR.
            $table->boolean('primary')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('item_dev_links', function (Blueprint $table) {
            $table->dropColumn('primary');
        });
        Schema::dropIfExists('github_app_deliveries');
        Schema::dropIfExists('github_repositories');
        Schema::table('github_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_installation_id');
            $table->dropColumn(['mode', 'access_lost_at', 'rate_limited_until', 'last_refreshed_at']);
        });
        Schema::dropIfExists('github_installations');
        Schema::dropIfExists('github_apps');
    }
};

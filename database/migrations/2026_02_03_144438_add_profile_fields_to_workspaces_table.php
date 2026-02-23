<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('header_image')->nullable()->after('logo');
            $table->string('website')->nullable()->after('header_image');
            $table->string('industry')->nullable()->after('website');
            $table->string('company_size')->nullable()->after('industry');
            $table->string('timezone')->default('UTC')->after('company_size');
            $table->string('language')->default('en')->after('timezone');
            $table->string('currency')->default('USD')->after('language');
            $table->boolean('is_public')->default(false)->after('currency');
            $table->json('social_links')->nullable()->after('is_public');
            $table->json('branding')->nullable()->after('social_links');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'header_image',
                'website',
                'industry',
                'company_size',
                'timezone',
                'language',
                'currency',
                'is_public',
                'social_links',
                'branding',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `require_email_verification` was seeded as false but never enforced: verification has
 * always been required. Now that the setting is honoured, store true so existing instances
 * keep their behaviour until an admin changes it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (DB::table('instance_settings')->get(['id', 'settings']) as $row) {
            $settings = json_decode($row->settings ?? '[]', true) ?: [];
            $settings['require_email_verification'] = true;

            DB::table('instance_settings')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

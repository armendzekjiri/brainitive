<?php

namespace Database\Seeders;

use App\Models\InstanceSettings;
use Illuminate\Database\Seeder;

class InstanceSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InstanceSettings::firstOrCreate([], [
            'name' => 'Brainitive',
            'settings' => [
                'allow_registration' => true,
                'require_email_verification' => true,
            ],
        ]);
    }
}

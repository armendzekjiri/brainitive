<?php

namespace Database\Seeders;

use App\Models\ItemType;
use Illuminate\Database\Seeder;

class ItemTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Task',
                'slug' => 'task',
                'icon' => 'check-square',
                'color' => '#4ade80',
                'description' => 'A task that needs to be done',
                'is_default' => true,
            ],
            [
                'name' => 'Bug',
                'slug' => 'bug',
                'icon' => 'bug',
                'color' => '#ef4444',
                'description' => 'A problem that impairs product functionality',
                'is_default' => false,
            ],
            [
                'name' => 'Story',
                'slug' => 'story',
                'icon' => 'book-open',
                'color' => '#22c55e',
                'description' => 'A user story describing a feature from the user perspective',
                'is_default' => false,
            ],
            [
                'name' => 'Epic',
                'slug' => 'epic',
                'icon' => 'zap',
                'color' => '#a855f7',
                'description' => 'A large body of work that can be broken down into stories',
                'is_default' => false,
            ],
            [
                'name' => 'Subtask',
                'slug' => 'subtask',
                'icon' => 'list',
                'color' => '#6b7280',
                'description' => 'A smaller piece of work that is part of a larger task',
                'is_default' => false,
            ],
        ];

        foreach ($types as $type) {
            ItemType::firstOrCreate(
                ['slug' => $type['slug'], 'project_id' => null],
                $type
            );
        }
    }
}

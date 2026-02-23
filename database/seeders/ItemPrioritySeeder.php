<?php

namespace Database\Seeders;

use App\Models\ItemPriority;
use Illuminate\Database\Seeder;

class ItemPrioritySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $priorities = [
            [
                'name' => 'Highest',
                'slug' => 'highest',
                'icon' => 'chevrons-up',
                'color' => '#dc2626',
                'order' => 1,
                'is_default' => false,
            ],
            [
                'name' => 'High',
                'slug' => 'high',
                'icon' => 'chevron-up',
                'color' => '#f97316',
                'order' => 2,
                'is_default' => false,
            ],
            [
                'name' => 'Medium',
                'slug' => 'medium',
                'icon' => 'equal',
                'color' => '#eab308',
                'order' => 3,
                'is_default' => true,
            ],
            [
                'name' => 'Low',
                'slug' => 'low',
                'icon' => 'chevron-down',
                'color' => '#22c55e',
                'order' => 4,
                'is_default' => false,
            ],
            [
                'name' => 'Lowest',
                'slug' => 'lowest',
                'icon' => 'chevrons-down',
                'color' => '#6b7280',
                'order' => 5,
                'is_default' => false,
            ],
        ];

        foreach ($priorities as $priority) {
            ItemPriority::firstOrCreate(
                ['slug' => $priority['slug'], 'project_id' => null],
                $priority
            );
        }
    }
}

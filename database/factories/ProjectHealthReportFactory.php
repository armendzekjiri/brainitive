<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectHealthReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectHealthReport>
 */
class ProjectHealthReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'created_by' => User::factory(),
            'status' => 'on_track',
            'summary' => null,
            'sections' => ['progress' => [], 'risks' => [], 'next_steps' => []],
            'metrics' => ['values' => [], 'lists' => []],
            'as_of' => now(),
            'period_start' => now()->subDays(13)->toDateString(),
            'period_end' => now()->toDateString(),
            'timezone' => 'UTC',
        ];
    }
}

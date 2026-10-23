<?php

namespace App\Console\Commands;

use App\Models\InstanceAdmin;
use Illuminate\Console\Command;

class ListInstanceAdmins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'list:instance-admins';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all instance admins';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $admins = InstanceAdmin::with('user')->get();

        if ($admins->isEmpty()) {
            $this->warn('No instance admins found.');
            $this->info('Use php artisan make:instance-admin {email} to create one.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Created At'],
            $admins->map(fn ($admin) => [
                $admin->user->id,
                $admin->user->name,
                $admin->user->email,
                $admin->created_at->format('Y-m-d H:i:s'),
            ])
        );

        return Command::SUCCESS;
    }
}

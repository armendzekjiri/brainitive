<?php

namespace App\Console\Commands;

use App\Models\InstanceAdmin;
use App\Models\User;
use Illuminate\Console\Command;

class MakeInstanceAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:instance-admin {email : The email of the user to promote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote a user to instance admin';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email '{$email}' not found.");
            return Command::FAILURE;
        }

        if ($user->isInstanceAdmin()) {
            $this->warn("User '{$user->name}' is already an instance admin.");
            return Command::SUCCESS;
        }

        InstanceAdmin::create(['user_id' => $user->id]);

        $this->info("User '{$user->name}' has been promoted to instance admin.");
        return Command::SUCCESS;
    }
}

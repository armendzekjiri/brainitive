<?php

namespace App\Console\Commands;

use App\Models\InstanceAdmin;
use App\Models\User;
use Illuminate\Console\Command;

class RemoveInstanceAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'remove:instance-admin {email : The email of the user to demote}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove instance admin status from a user';

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

        if (!$user->isInstanceAdmin()) {
            $this->warn("User '{$user->name}' is not an instance admin.");
            return Command::SUCCESS;
        }

        InstanceAdmin::where('user_id', $user->id)->delete();

        $this->info("User '{$user->name}' is no longer an instance admin.");
        return Command::SUCCESS;
    }
}

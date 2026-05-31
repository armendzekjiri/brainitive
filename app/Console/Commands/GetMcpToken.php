<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GetMcpToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp:get-token {email : The email address of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate (or rotate) a Passport personal access token for a user to use with the MCP server';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return Command::FAILURE;
        }

        // Revoke any existing CLI/MCP token so there is only ever one active.
        try {
            $user->tokens()->where('name', 'brainitive-cli')->get()->each->revoke();
        } catch (\Exception) {
            // OAuth tables not yet migrated — harmless, continue.
        }

        $token = $user->createToken('brainitive-cli')->accessToken;

        $this->info("Token generated for {$user->name} <{$user->email}>:");
        $this->line('');
        $this->line($token);
        $this->line('');
        $this->comment('Copy this token and store it as a GitHub repository secret named COPILOT_MCP_BRAINITIVE_TOKEN.');
        $this->comment('Then add the following to your Copilot MCP configuration (repo Settings → Copilot → Cloud agent → MCP):');
        $this->line('');
        $this->line(json_encode([
            'mcpServers' => [
                'brainitive' => [
                    'type' => 'http',
                    'url' => rtrim(config('app.url'), '/').'/mcp',
                    'headers' => [
                        'Authorization' => '******',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}

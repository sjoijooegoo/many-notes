<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class RevokeMcpTokenCommand extends Command
{
    protected $signature = 'mcp:token:revoke
        {email : Many Notes account email}
        {token : Token ID returned by mcp:token:create or mcp:token:list}';

    protected $description = 'Revoke a Many Notes MCP API token';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (!$user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $deleted = $user->tokens()->whereKey((int) $this->argument('token'))->delete();

        if ($deleted === 0) {
            $this->error('Token not found for this user.');

            return self::FAILURE;
        }

        $this->info('Token revoked.');

        return self::SUCCESS;
    }
}

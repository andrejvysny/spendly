<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use Illuminate\Console\Command;

class GocardlessCredentialsCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:credentials
        {--user= : User ID or email (default: first user)}
        {--set : Save secret_id and secret_key}
        {--purge : Clear all GoCardless credentials and tokens}
        {--secret-id= : GoCardless secret ID (with --set)}
        {--secret-key= : GoCardless secret key (with --set)}';

    protected $description = 'View, set, or purge GoCardless credentials for a user.';

    public function handle(): int
    {
        $user = $this->resolveUser($this->option('user'));
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $user->gocardless_secret_id = null;
            $user->gocardless_secret_key = null;
            $user->gocardless_access_token = null;
            $user->gocardless_refresh_token = null;
            $user->gocardless_refresh_token_expires_at = null;
            $user->gocardless_access_token_expires_at = null;
            $user->save();

            $this->info('All GoCardless credentials purged.');

            return self::SUCCESS;
        }

        if ($this->option('set')) {
            $secretId = $this->option('secret-id');
            $secretKey = $this->option('secret-key');

            if (! is_string($secretId) || $secretId === '' || ! is_string($secretKey) || $secretKey === '') {
                $this->error('Both --secret-id and --secret-key are required with --set.');

                return self::FAILURE;
            }

            $user->gocardless_secret_id = $secretId;
            $user->gocardless_secret_key = $secretKey;
            $user->save();

            $this->info('GoCardless credentials saved.');

            return self::SUCCESS;
        }

        // Default: show credential status
        $this->table(['Field', 'Status'], [
            ['Secret ID', $user->gocardless_secret_id ? $this->mask($user->gocardless_secret_id) : '<not set>'],
            ['Secret Key', $user->gocardless_secret_key ? $this->mask($user->gocardless_secret_key) : '<not set>'],
            ['Access Token', $user->gocardless_access_token ? $this->mask($user->gocardless_access_token) : '<not set>'],
            ['Access Token Expires', $user->gocardless_access_token_expires_at !== null ? (string) $user->gocardless_access_token_expires_at : '<not set>'],
            ['Refresh Token', $user->gocardless_refresh_token ? $this->mask($user->gocardless_refresh_token) : '<not set>'],
            ['Refresh Token Expires', $user->gocardless_refresh_token_expires_at !== null ? (string) $user->gocardless_refresh_token_expires_at : '<not set>'],
        ]);

        return self::SUCCESS;
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, 4).'...'.substr($value, -4);
    }
}

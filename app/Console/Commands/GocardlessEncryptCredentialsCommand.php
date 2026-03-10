<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GocardlessEncryptCredentialsCommand extends Command
{
    protected $signature = 'gocardless:encrypt-credentials';

    protected $description = 'Encrypt existing plaintext GoCardless credentials for all users.';

    private const CREDENTIAL_FIELDS = [
        'gocardless_secret_id',
        'gocardless_secret_key',
        'gocardless_access_token',
        'gocardless_refresh_token',
    ];

    public function handle(): int
    {
        $rows = DB::table('users')
            ->whereNotNull('gocardless_secret_id')
            ->get(['id', ...self::CREDENTIAL_FIELDS]);

        $processed = 0;

        foreach ($rows as $row) {
            /** @var User|null $user */
            $user = User::find($row->id);
            if ($user === null) {
                continue;
            }

            foreach (self::CREDENTIAL_FIELDS as $field) {
                $raw = $row->{$field};
                if ($raw !== null && is_string($raw)) {
                    $user->setAttribute($field, $raw);
                }
            }

            $user->save();
            $processed++;
        }

        $this->info("Processed {$processed} user(s).");

        return self::SUCCESS;
    }
}

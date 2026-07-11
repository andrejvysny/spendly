<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Per-user matching context: account identity lookup maps used by the tier
 * gates and the heuristic scorer. All values are pre-normalized (IBANs
 * uppercased/stripped, names ASCII-folded and uppercased).
 */
final readonly class AccountContext
{
    /**
     * @param  array<int, string>  $accountIdToIban
     * @param  array<int, string>  $accountIdToName
     * @param  array<int, string>  $accountIdToBankName
     */
    public function __construct(
        public array $accountIdToIban,
        public array $accountIdToName,
        public array $accountIdToBankName,
        public string $normalizedUserName,
    ) {}

    /**
     * @param  Collection<int, Account>  $accounts
     */
    public static function forUser(int $userId, Collection $accounts): self
    {
        $ibans = [];
        $names = [];
        $bankNames = [];

        foreach ($accounts as $account) {
            $iban = Iban::normalize($account->iban);
            if ($iban !== null) {
                $ibans[(int) $account->id] = $iban;
            }
            $name = self::fold((string) ($account->name ?? ''));
            if ($name !== '') {
                $names[(int) $account->id] = $name;
            }
            $bankName = self::fold((string) ($account->bank_name ?? ''));
            if ($bankName !== '') {
                $bankNames[(int) $account->id] = $bankName;
            }
        }

        $userName = User::query()->whereKey($userId)->value('name');

        return new self(
            accountIdToIban: $ibans,
            accountIdToName: $names,
            accountIdToBankName: $bankNames,
            normalizedUserName: self::fold(is_string($userName) ? $userName : ''),
        );
    }

    public function ibanFor(int $accountId): ?string
    {
        return $this->accountIdToIban[$accountId] ?? null;
    }

    /**
     * ASCII-fold + uppercase + collapse whitespace, for diacritics-insensitive
     * containment checks (e.g. "Kováč" matches "KOVAC").
     */
    public static function fold(string $value): string
    {
        $folded = Str::ascii(trim($value));
        $folded = (string) preg_replace('/\s+/', ' ', $folded);

        return mb_strtoupper($folded);
    }
}

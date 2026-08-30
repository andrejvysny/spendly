<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\GoCardless\FieldExtractors\FieldExtractorFactory;
use Carbon\Carbon;

class GocardlessMapper
{
    /** @var array<int, array<string, true>> */
    private array $ibanCache = [];

    /**
     * Drop the cached own-account IBAN set.
     *
     * The mapper is a container singleton, so in the queue worker (and under the FrankenPHP worker,
     * where config/octane.php flushes nothing) this cache outlives a single run. An account
     * imported after the cache warmed would otherwise never be recognised as own-account, and the
     * transfer_candidate flag would silently stop firing until the worker recycled.
     */
    public function forgetIbanCache(): void
    {
        $this->ibanCache = [];
    }

    public function __construct(
        private readonly FieldExtractorFactory $extractorFactory,
        private readonly AccountRepositoryInterface $accountRepository
    ) {}

    /**
     * Maps GoCardless account data into a structured array for application use.
     *
     * @param  array  $data  Raw GoCardless account data.
     * @return array Structured account data suitable for internal processing.
     */
    public function mapAccountData(array $data): array
    {
        return [
            'gocardless_account_id' => $data['id'] ?? null,
            'gocardless_institution_id' => $data['institution_id'] ?? null,
            'name' => $data['name'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'iban' => $data['iban'] ?? null,
            'type' => $this->mapAccountType($data),
            'currency' => $data['currency'] ?? null,
            'balance' => $data['balance'] ?? 0.00,
            'is_gocardless_synced' => true,
            // Deliberately NOT stamped here. gocardless_last_synced_at is the *data* watermark that
            // TransactionSyncService::calculateDateRange() windows the next fetch from. Setting it
            // at import time claimed a history depth that had never been fetched, so the first sync
            // of a freshly connected account asked the bank for `now()-1day … now()` and the 90-day
            // consent the app had just negotiated was never used. Leaving it null makes that first
            // sync take the full 90-day window.
            'import_data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Map GoCardless account type/cashAccountType to application account type.
     * accounts.type is NOT NULL; GoCardless uses cashAccountType (CACC, SVGS, etc.).
     */
    private function mapAccountType(array $data): string
    {
        $raw = $data['type'] ?? $data['cashAccountType'] ?? null;
        if ($raw === null || $raw === '') {
            return 'checking';
        }
        $upper = strtoupper((string) $raw);

        return match ($upper) {
            'SVGS' => 'savings',
            'CACC' => 'checking',
            default => in_array($upper, ['CHECKING', 'SAVINGS'], true) ? strtolower($upper) : 'checking',
        };
    }

    /**
     * Retrieves a value from a nested array using dot notation, returning a default if the key is not found.
     *
     * @param  array  $array  The array to search.
     * @param  string  $key  The dot notation key (e.g., 'foo.bar.baz').
     * @param  mixed  $default  The value to return if the key does not exist.
     * @return mixed The value found at the specified key, or the default value.
     */
    private function get(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;
        foreach ($keys as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Safely parses a date string into a Carbon instance.
     *
     * @param  string|null  $date  The date string to parse.
     * @return Carbon|null The parsed date or null if invalid.
     */
    private function parseDate(?string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Maps a GoCardless transaction array and associated account into a structured array for internal use.
     *
     * @param  array  $transaction  Raw transaction data from GoCardless.
     * @param  Account  $account  The associated account model.
     * @param  Carbon  $syncDate  Date of sync (used when dates are missing).
     * @return array Structured transaction data suitable for application processing.
     */
    public function mapTransactionData(array $transaction, Account $account, Carbon $syncDate): array
    {
        $extractor = $this->extractorFactory->make($account);

        $bookedRaw = $this->get($transaction, 'bookingDateTime', $this->get($transaction, 'bookingDate'));
        $bookedDateTime = $this->parseDate($bookedRaw);
        $valueRaw = $this->get($transaction, 'valueDateTime', $this->get($transaction, 'valueDate', $bookedRaw));
        $valueDateTime = $this->parseDate($valueRaw);

        // Raw values are carried through unchanged so TransactionDataValidator can reject what it
        // cannot read. Coercing a malformed amount to 0.0 or a missing currency to EUR here would
        // invent a financial value that then looks like something the bank actually sent.
        $amountRaw = $this->get($transaction, 'transactionAmount.amount');
        $amountIsNumeric = is_numeric($amountRaw);
        $amount = $amountIsNumeric ? (float) $amountRaw : $amountRaw;
        $currency = $this->get($transaction, 'transactionAmount.currency');

        // Type extraction needs a number to decide direction; an unreadable amount is about to be
        // quarantined by the validator anyway, so a local 0.0 here never reaches storage.
        $amountForType = $amountIsNumeric ? (float) $amountRaw : 0.0;

        $sourceIban = $this->get($transaction, 'debtorAccount.iban');
        $targetIban = $this->get($transaction, 'creditorAccount.iban');

        $description = $extractor->extractDescription($transaction);
        if (trim($description) === '') {
            $partner = $extractor->extractPartner($transaction);
            $description = $partner ?: 'Transaction '.($this->get($transaction, 'transactionId') ?? 'unknown');
        }
        $partner = $extractor->extractPartner($transaction);
        $type = $extractor->extractTransactionType($transaction, $amountForType);

        // Flag probable own-account transfers, but keep the source type until pairing confirms it.
        $ownIbanNormalized = $this->getOwnAccountIbansNormalized((int) $account->user_id);
        $counterpartyIban = $this->getCounterpartyIban($sourceIban, $targetIban, $account->iban);
        $isTransferCandidate = $counterpartyIban !== null
            && $ownIbanNormalized !== []
            && isset($ownIbanNormalized[$this->normalizeIban($counterpartyIban)]);

        $metadata = $extractor->extractMetadata($transaction);
        if ($isTransferCandidate) {
            $metadata['transfer_candidate'] = true;
        }

        $currencyExchange = $extractor->extractCurrencyExchange($transaction);
        $originalCurrency = null;
        $originalAmount = null;
        $exchangeRate = null;
        if ($currencyExchange !== null) {
            $metadata['currency_exchange'] = $currencyExchange;
            $originalCurrency = $currencyExchange['sourceCurrency'] ?? null;
            $instructed = $currencyExchange['instructedAmount'] ?? [];
            $originalAmount = isset($instructed['amount']) ? (float) $instructed['amount'] : null;
            $exchangeRate = isset($currencyExchange['exchangeRate']) ? (float) $currencyExchange['exchangeRate'] : null;
        }

        $transactionId = $this->get($transaction, 'transactionId');
        $internalTransactionId = $this->get($transaction, 'internalTransactionId');
        $entryReference = $this->get($transaction, 'entryReference');
        $bankTransactionCode = $this->get($transaction, 'bankTransactionCode');

        $importDataJson = $transaction;
        try {
            $importDataEncoded = json_encode($importDataJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            $importDataEncoded = '{}';
        }

        return [
            'transaction_id' => $transactionId,
            'account_id' => $account->id,
            'gocardless_account_id' => $account->gocardless_account_id,
            'is_gocardless_synced' => true,
            'gocardless_synced_at' => now(),

            'amount' => $amount,
            'currency' => $currency,
            'booked_date' => $bookedDateTime,
            'processed_date' => $valueDateTime,

            'source_iban' => $sourceIban,
            'target_iban' => $targetIban,
            'partner' => $partner,
            'description' => $description,
            'type' => $type,

            'balance_after_transaction' => $this->get($transaction, 'balanceAfterTransaction.balanceAmount.amount', null),
            'metadata' => $metadata,
            'import_data' => $importDataEncoded,

            'original_currency' => $originalCurrency,
            'original_amount' => $originalAmount,
            'exchange_rate' => $exchangeRate,
            'internal_transaction_id' => $internalTransactionId,
            'entry_reference' => $entryReference,
            'bank_transaction_code' => $bankTransactionCode,
        ];
    }

    /**
     * Get counterparty IBAN for this transaction: the one of debtor/creditor that is not the current account's IBAN.
     */
    private function getCounterpartyIban(?string $sourceIban, ?string $targetIban, ?string $accountIban): ?string
    {
        if ($accountIban === null || $accountIban === '') {
            return null;
        }
        $accountNorm = $this->normalizeIban($accountIban);
        if ($sourceIban !== null && $sourceIban !== '' && $this->normalizeIban($sourceIban) !== $accountNorm) {
            return $sourceIban;
        }
        if ($targetIban !== null && $targetIban !== '' && $this->normalizeIban($targetIban) !== $accountNorm) {
            return $targetIban;
        }

        return null;
    }

    /**
     * Get user's account IBANs normalized (key = normalized IBAN) for lookup.
     *
     * @return array<string, true>
     */
    private function getOwnAccountIbansNormalized(int $userId): array
    {
        if (isset($this->ibanCache[$userId])) {
            return $this->ibanCache[$userId];
        }

        $accounts = $this->accountRepository->findByUser($userId);
        $out = [];
        foreach ($accounts as $acc) {
            $iban = $acc->iban;
            if ($iban !== null && trim((string) $iban) !== '') {
                $out[$this->normalizeIban((string) $iban)] = true;
            }
        }

        $this->ibanCache[$userId] = $out;

        return $out;
    }

    private function normalizeIban(string $iban): string
    {
        $s = strtoupper(trim(preg_replace('/\s+/', '', $iban)));

        return $s;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recompute recurring-group fingerprints as v2 (amount-independent) so
 * dismissals and confirmed-group dedup survive price changes.
 *
 * v2 = sha256('v2|user|account-or-all|payeeKey|CURRENCY|interval|c{ordinal}')
 *
 * Detection logic is intentionally inlined (a migration must not call the
 * service, whose behavior can change after this migration ships).
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('recurring_groups')
            ->whereIn('status', ['confirmed', 'dismissed'])
            ->get();

        foreach ($groups as $group) {
            $payeeKey = $this->payeeKey($this->intOrNull($group->counterparty_id), $this->stringValue($group->normalized_description));
            $currencies = $this->currenciesForGroup($group);
            $primaryCurrency = $currencies[0] ?? '';

            $primaryHash = $this->fingerprintV2($group, $payeeKey, $primaryCurrency);
            DB::table('recurring_groups')
                ->where('id', $group->id)
                ->update(['dismissal_fingerprint' => $primaryHash]);

            if ($group->status === 'dismissed') {
                // Suppress re-suggestion under every plausible currency
                // (per_user groups may span accounts in different currencies).
                foreach ($currencies as $currency) {
                    DB::table('dismissed_recurring_suggestions')->insertOrIgnore([
                        'user_id' => $group->user_id,
                        'fingerprint' => $this->fingerprintV2($group, $payeeKey, $currency),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Suggested groups carry v1 fingerprints; drop them once - the next
        // detection run regenerates them with v2 fingerprints (same effect as
        // the per-run wipe this release removes).
        DB::table('recurring_groups')->where('status', 'suggested')->delete();

        // Legacy v1 hashes remain in dismissed_recurring_suggestions; they are
        // harmless orphans (nothing generates v1 hashes anymore).
    }

    public function down(): void
    {
        // Fingerprints are one-way hashes; the v1 values cannot be restored.
    }

    private function fingerprintV2(stdClass $group, string $payeeKey, string $currency): string
    {
        $accountId = $this->intOrNull($group->account_id);
        $payload = implode('|', [
            'v2',
            $this->stringValue($group->user_id),
            $accountId !== null ? (string) $accountId : 'all',
            $payeeKey,
            strtoupper($currency),
            $this->stringValue($group->interval),
            'c0',
        ]);

        return hash('sha256', $payload);
    }

    private function payeeKey(?int $counterpartyId, ?string $normalizedDescription): string
    {
        if ($counterpartyId !== null) {
            return 'm'.$counterpartyId;
        }

        return 'd:'.$this->normalizeDescriptionForPayee((string) $normalizedDescription);
    }

    /**
     * @return array<int, string>
     */
    private function currenciesForGroup(stdClass $group): array
    {
        $accountId = $this->intOrNull($group->account_id);
        if ($accountId !== null) {
            $currency = DB::table('accounts')->where('id', $accountId)->value('currency');

            return [strtoupper($this->stringValue($currency))];
        }

        $currencies = DB::table('accounts')
            ->where('user_id', $this->intOrNull($group->user_id))
            ->pluck('currency')
            ->map(fn ($c) => strtoupper($this->stringValue($c)))
            ->unique()
            ->values()
            ->all();

        return $currencies === [] ? [''] : $currencies;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function normalizeDescriptionForPayee(string $description): string
    {
        $s = (string) preg_replace('/\s+/u', ' ', trim($description));
        $s = strtolower($s);

        $recurringSuffixWords = [
            'subscription', 'payment', 'monthly', 'recurring', 'direct debit', 'dd',
            'standing order', 'so', 'preauthorized', 'preauth', 'autopay', 'auto pay',
        ];
        foreach ($recurringSuffixWords as $word) {
            $s = (string) preg_replace('/\s*'.preg_quote($word, '/').'\s*/iu', ' ', $s);
        }
        $s = (string) preg_replace('/\s+/u', ' ', trim($s));

        return $s === '' ? strtolower((string) preg_replace('/\s+/u', ' ', trim($description))) : $s;
    }
};

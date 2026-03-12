<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    private int $seq = 0;

    /** @var array<string, float> */
    private array $bal = [];

    /** @var array<string, Account> */
    private array $acct = [];

    /** @var array<string, int|string> */
    private array $cat = [];

    /** @var array<string, int|string> */
    private array $cp = [];

    /** @var array<string, int|string> */
    private array $tag = [];

    public function run(): void
    {
        Transaction::$fireRuleEvents = false;

        try {
            $this->init();

            $months = [
                '2025-04', '2025-05', '2025-06', '2025-07', '2025-08', '2025-09',
                '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03',
            ];

            foreach ($months as $ym) {
                $this->seedRecurring($ym);
                $this->seedTransfers($ym);
                $this->seedVariable($ym);
            }

            $this->seedCash();
            $this->seedUsd();
            $this->seedSeasonal();
            $this->finalize();
        } finally {
            Transaction::$fireRuleEvents = true;
        }
    }

    private function init(): void
    {
        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $accounts = Account::where('user_id', $user->id)->get();

        $names = ['Main Checking' => 'chk', 'Savings' => 'sav', 'Credit Card' => 'cc', 'Investment' => 'inv', 'Revolut USD' => 'usd', 'Cash Wallet' => 'cash'];
        foreach ($names as $name => $key) {
            /** @var Account $account */
            $account = $accounts->firstWhere('name', $name);
            $this->acct[$key] = $account;
            $this->bal[$key] = (float) $account->opening_balance;
        }

        /** @var array<string, int|string> $cats */
        $cats = Category::where('user_id', $user->id)->pluck('id', 'name')->toArray();
        $this->cat = $cats;
        /** @var array<string, int|string> $cps */
        $cps = Counterparty::where('user_id', $user->id)->pluck('id', 'name')->toArray();
        $this->cp = $cps;
        /** @var array<string, int|string> $tags */
        $tags = Tag::where('user_id', $user->id)->pluck('id', 'name')->toArray();
        $this->tag = $tags;
    }

    private function seedRecurring(string $ym): void
    {
        $elec = [
            '2025-04' => -92, '2025-05' => -89, '2025-06' => -95, '2025-07' => -98,
            '2025-08' => -102, '2025-09' => -96, '2025-10' => -105, '2025-11' => -112,
            '2025-12' => -118, '2026-01' => -115, '2026-02' => -108, '2026-03' => -99,
        ];
        $doAmts = [
            '2025-04' => -12, '2025-05' => -12, '2025-06' => -13, '2025-07' => -12,
            '2025-08' => -12, '2025-09' => -14, '2025-10' => -12, '2025-11' => -13,
            '2025-12' => -12, '2026-01' => -12, '2026-02' => -14, '2026-03' => -12,
        ];

        // Checking recurring (all fall on day ≤10, so included even in Mar partial)
        $this->tx('SAL', 'chk', "$ym-01", 5000, 'DEPOSIT', 'Salary', 'Acme Corp', 'Monthly Salary', ['Recurring']);
        $this->tx('GYM', 'chk', "$ym-01", -49.90, 'CARD_PAYMENT', 'Gym', 'Gym & Fitness GmbH', 'Gym Membership', ['Recurring', 'Health']);
        $this->tx('INS', 'chk', "$ym-01", -189, 'PAYMENT', 'Insurance', 'Health Insurance Co.', 'Health Insurance', ['Recurring']);
        $this->tx('RENT', 'chk', "$ym-02", -1500, 'PAYMENT', 'Rent', 'Landlord - Schmidt', 'Rent Payment', ['Recurring']);
        $this->tx('ELEC', 'chk', "$ym-05", $elec[$ym], 'PAYMENT', 'Utilities', null, 'Electricity & Gas', ['Recurring']);
        $this->tx('INET', 'chk', "$ym-10", -45, 'PAYMENT', 'Utilities', null, 'Internet & Phone', ['Recurring']);

        // Credit Card recurring
        $this->tx('DO', 'cc', "$ym-03", $doAmts[$ym], 'CARD_PAYMENT', 'Cloud Services', 'Digital Ocean', 'Digital Ocean Hosting', ['Recurring', 'Business']);
        $this->tx('NFLX', 'cc', "$ym-05", -15.99, 'CARD_PAYMENT', 'Streaming', 'Netflix', 'Netflix Subscription', ['Recurring']);
        $this->tx('SPOT', 'cc', "$ym-10", -9.99, 'CARD_PAYMENT', 'Streaming', 'Spotify', 'Spotify Premium', ['Recurring']);

        // iCloud on day 15 — skip for Mar 2026 partial (only up to day 11)
        if ($ym !== '2026-03') {
            $this->tx('ICLD', 'cc', "$ym-15", -2.99, 'CARD_PAYMENT', 'Software', 'Apple', 'Apple iCloud+', ['Recurring']);
        }
    }

    private function seedTransfers(string $ym): void
    {
        $chkIban = 'DE89370400440532013000';
        $savIban = 'DE89370400440532013001';
        $invIban = 'DE89370400440532013003';

        // Monthly Savings transfer (day 15) — skip Mar 2026 partial
        if ($ym !== '2026-03') {
            $this->tx('SVTR', 'chk', "$ym-15", -500, 'PAYMENT', null, null, 'Transfer to Savings', [], [
                'target_iban' => $savIban, 'source_iban' => $chkIban,
            ]);
            $this->tx('SVTR', 'sav', "$ym-15", 500, 'DEPOSIT', null, null, 'Transfer from Checking', [], [
                'source_iban' => $chkIban, 'target_iban' => $savIban,
            ]);
        }

        // Quarterly Investment transfer — Jun, Sep, Dec (Mar 2026 day 15 > 11, skip)
        if (in_array($ym, ['2025-06', '2025-09', '2025-12'])) {
            $this->tx('IVTR', 'chk', "$ym-15", -1000, 'PAYMENT', null, null, 'Transfer to Investment', [], [
                'target_iban' => $invIban, 'source_iban' => $chkIban,
            ]);
            $this->tx('IVTR', 'inv', "$ym-15", 1000, 'DEPOSIT', null, null, 'Transfer from Checking', [], [
                'source_iban' => $chkIban, 'target_iban' => $invIban,
            ]);
        }
    }

    private function seedVariable(string $ym): void
    {
        $p = ['Personal'];

        // --- Groceries: 5/month (3 for Mar partial) ---
        $groc = [
            '2025-04' => [[7, -95.40, 'Walmart'], [12, -67.20, 'Lidl'], [18, -132.80, 'Walmart'], [22, -48.50, 'Lidl'], [26, -78.90, 'Walmart']],
            '2025-05' => [[6, -88.20, 'Walmart'], [11, -55.80, 'Lidl'], [16, -145.60, 'Walmart'], [21, -71.40, 'Lidl'], [27, -92.10, 'Walmart']],
            '2025-06' => [[5, -102.30, 'Walmart'], [10, -63.40, 'Lidl'], [17, -118.90, 'Walmart'], [23, -52.70, 'Lidl'], [28, -85.60, 'Walmart']],
            '2025-07' => [[8, -91.50, 'Walmart'], [13, -74.80, 'Lidl'], [19, -128.40, 'Walmart'], [24, -58.30, 'Lidl'], [29, -96.20, 'Walmart']],
            '2025-08' => [[6, -108.70, 'Walmart'], [12, -61.50, 'Lidl'], [18, -135.20, 'Walmart'], [23, -69.80, 'Lidl'], [27, -82.40, 'Walmart']],
            '2025-09' => [[7, -94.60, 'Walmart'], [14, -57.30, 'Lidl'], [20, -141.80, 'Walmart'], [25, -66.40, 'Lidl'], [28, -88.90, 'Walmart']],
            '2025-10' => [[5, -112.40, 'Walmart'], [11, -72.60, 'Lidl'], [17, -125.30, 'Walmart'], [22, -54.90, 'Lidl'], [26, -98.70, 'Walmart']],
            '2025-11' => [[8, -99.80, 'Walmart'], [13, -68.40, 'Lidl'], [19, -138.50, 'Walmart'], [24, -61.20, 'Lidl'], [29, -91.30, 'Walmart']],
            '2025-12' => [[6, -115.60, 'Walmart'], [12, -75.30, 'Lidl'], [18, -142.70, 'Walmart'], [23, -58.80, 'Lidl'], [27, -105.40, 'Walmart']],
            '2026-01' => [[7, -93.20, 'Walmart'], [14, -64.50, 'Lidl'], [20, -129.80, 'Walmart'], [25, -71.90, 'Lidl'], [28, -87.40, 'Walmart']],
            '2026-02' => [[5, -106.80, 'Walmart'], [11, -59.70, 'Lidl'], [16, -133.40, 'Walmart'], [22, -67.30, 'Lidl'], [26, -95.50, 'Walmart']],
            '2026-03' => [[3, -98.60, 'Walmart'], [7, -62.40, 'Lidl'], [10, -121.50, 'Walmart']],
        ];
        foreach ($groc[$ym] ?? [] as [$d, $a, $c]) {
            $this->tx('GROC', 'cc', sprintf('%s-%02d', $ym, $d), $a, 'CARD_PAYMENT', 'Groceries', $c, 'Grocery Shopping', $p);
        }

        // --- Restaurants: 4/month (2 for Mar partial) ---
        $rest = [
            '2025-04' => [[8, -5.80, 'Starbucks'], [14, -12.50, "McDonald's"], [19, -6.20, 'Starbucks'], [25, -45.00, null]],
            '2025-05' => [[7, -4.90, 'Starbucks'], [13, -9.80, "McDonald's"], [20, -5.50, 'Starbucks'], [26, -38.50, null]],
            '2025-06' => [[9, -6.40, 'Starbucks'], [15, -11.20, "McDonald's"], [21, -5.90, 'Starbucks'], [27, -52.00, null]],
            '2025-07' => [[6, -5.20, 'Starbucks'], [12, -13.80, "McDonald's"], [18, -6.80, 'Starbucks'], [24, -41.50, null]],
            '2025-08' => [[8, -4.60, 'Starbucks'], [14, -10.50, "McDonald's"], [20, -5.70, 'Starbucks'], [26, -48.00, null]],
            '2025-09' => [[7, -6.10, 'Starbucks'], [13, -12.90, "McDonald's"], [19, -5.40, 'Starbucks'], [25, -35.80, null]],
            '2025-10' => [[9, -5.50, 'Starbucks'], [15, -11.80, "McDonald's"], [21, -6.30, 'Starbucks'], [27, -43.50, null]],
            '2025-11' => [[6, -4.80, 'Starbucks'], [12, -13.20, "McDonald's"], [18, -5.60, 'Starbucks'], [24, -39.90, null]],
            '2025-12' => [[8, -6.50, 'Starbucks'], [14, -10.80, "McDonald's"], [20, -5.30, 'Starbucks'], [26, -55.00, null]],
            '2026-01' => [[7, -5.10, 'Starbucks'], [13, -12.20, "McDonald's"], [19, -6.00, 'Starbucks'], [25, -42.80, null]],
            '2026-02' => [[9, -4.70, 'Starbucks'], [15, -11.50, "McDonald's"], [21, -5.80, 'Starbucks'], [27, -37.60, null]],
            '2026-03' => [[5, -6.20, 'Starbucks'], [9, -11.00, "McDonald's"]],
        ];
        foreach ($rest[$ym] ?? [] as [$d, $a, $c]) {
            $desc = $c === 'Starbucks' ? 'Coffee' : ($c === "McDonald's" ? 'Fast Food' : 'Restaurant Dinner');
            $this->tx('REST', 'cc', sprintf('%s-%02d', $ym, $d), $a, 'CARD_PAYMENT', 'Restaurants', $c, $desc, $p);
        }

        // --- Transport: Bus 3x + Shell + Uber per full month ---
        $bus = [
            '2025-04' => [4, 9, 16], '2025-05' => [5, 10, 17], '2025-06' => [4, 9, 15],
            '2025-07' => [7, 11, 18], '2025-08' => [5, 10, 16], '2025-09' => [6, 11, 17],
            '2025-10' => [4, 8, 14], '2025-11' => [5, 10, 16], '2025-12' => [4, 9, 15],
            '2026-01' => [6, 11, 17], '2026-02' => [4, 8, 14], '2026-03' => [4, 8],
        ];
        foreach ($bus[$ym] ?? [] as $d) {
            $this->tx('BUS', 'cc', sprintf('%s-%02d', $ym, $d), -3.50, 'CARD_PAYMENT', 'Public Transport', null, 'Bus Fare', $p);
        }

        $fuel = [
            '2025-04' => [20, -68.40], '2025-05' => [21, -65.20], '2025-06' => [22, -71.30],
            '2025-07' => [23, -67.50], '2025-08' => [22, -72.10], '2025-09' => [21, -66.80],
            '2025-10' => [20, -69.90], '2025-11' => [22, -70.60], '2025-12' => [21, -67.20],
            '2026-01' => [22, -71.80], '2026-02' => [20, -68.50],
        ];
        if (isset($fuel[$ym])) {
            [$d, $a] = $fuel[$ym];
            $this->tx('FUEL', 'cc', sprintf('%s-%02d', $ym, $d), $a, 'CARD_PAYMENT', 'Fuel', 'Shell', 'Gas Station', $p);
        }

        $uber = [
            '2025-04' => [23, -15.50], '2025-05' => [24, -14.80], '2025-06' => [25, -16.20],
            '2025-07' => [26, -13.90], '2025-08' => [25, -17.40], '2025-09' => [24, -15.20],
            '2025-10' => [23, -14.50], '2025-11' => [25, -16.80], '2025-12' => [24, -18.50],
            '2026-01' => [25, -13.40], '2026-02' => [23, -15.90],
        ];
        if (isset($uber[$ym])) {
            [$d, $a] = $uber[$ym];
            $this->tx('UBER', 'cc', sprintf('%s-%02d', $ym, $d), $a, 'CARD_PAYMENT', 'Public Transport', 'Uber', 'Uber Ride', $p);
        }

        // --- Entertainment: movie every full month, game every other ---
        $movie = [
            '2025-04' => 15, '2025-05' => 16, '2025-06' => 18, '2025-07' => 14,
            '2025-08' => 17, '2025-09' => 15, '2025-10' => 19, '2025-11' => 16,
            '2025-12' => 13, '2026-01' => 18, '2026-02' => 15,
        ];
        if (isset($movie[$ym])) {
            $this->tx('MOV', 'cc', sprintf('%s-%02d', $ym, $movie[$ym]), -14.50, 'CARD_PAYMENT', 'Movies', null, 'Movie Theater', $p);
        }

        $game = ['2025-05' => -29.99, '2025-07' => -39.99, '2025-09' => -29.99, '2025-11' => -49.99, '2026-01' => -29.99];
        if (isset($game[$ym])) {
            $this->tx('GAME', 'cc', "$ym-22", $game[$ym], 'CARD_PAYMENT', 'Games', null, 'Gaming Purchase', $p);
        }

        // --- Shopping: Zara every 2nd month, Amazon alternating ---
        $zara = ['2025-04' => -85, '2025-06' => -95, '2025-08' => -110, '2025-10' => -75, '2025-12' => -120, '2026-02' => -89];
        if (isset($zara[$ym])) {
            $this->tx('ZARA', 'cc', "$ym-21", $zara[$ym], 'CARD_PAYMENT', 'Clothing', 'Zara', 'Clothing Purchase', $p);
        }

        $amzn = ['2025-05' => -42.50, '2025-07' => -55.80, '2025-09' => -38.20, '2025-11' => -65.40, '2026-01' => -48.90];
        if (isset($amzn[$ym])) {
            $this->tx('AMZN', 'cc', "$ym-19", $amzn[$ym], 'CARD_PAYMENT', 'Electronics', 'Amazon', 'Amazon Purchase', $p);
        }

        // --- Health: Pharmacy Plus alternating ---
        $pharm = ['2025-04' => -18.50, '2025-06' => -22.30, '2025-08' => -15.80, '2025-10' => -24.50, '2025-12' => -19.70, '2026-02' => -21.40];
        if (isset($pharm[$ym])) {
            $this->tx('PHRM', 'cc', "$ym-24", $pharm[$ym], 'CARD_PAYMENT', 'Pharmacy', 'Pharmacy Plus', 'Pharmacy', ['Health']);
        }
    }

    private function seedCash(): void
    {
        // ATM withdrawals every 2-3 months (no IBAN — Cash has no IBAN)
        $atm = ['2025-05-12', '2025-07-18', '2025-10-08', '2026-01-14', '2026-03-05'];
        foreach ($atm as $date) {
            $this->tx('ATM', 'chk', $date, -100, 'WITHDRAWAL', null, null, 'ATM Withdrawal');
            $this->tx('ATM', 'cash', $date, 100, 'DEPOSIT', null, null, 'ATM Cash Deposit');
        }

        // Small cash expenses
        $cashExp = [
            ['2025-05-15', -3.50, 'Restaurants', 'Coffee'],
            ['2025-06-10', -4.00, 'Transportation', 'Parking Fee'],
            ['2025-07-22', -12.00, 'Groceries', 'Market Purchase'],
            ['2025-08-05', -3.50, 'Restaurants', 'Coffee'],
            ['2025-10-12', -8.50, 'Restaurants', 'Street Food'],
            ['2025-11-20', -4.00, 'Transportation', 'Parking Fee'],
            ['2026-01-18', -5.00, 'Restaurants', 'Coffee'],
            ['2026-03-08', -3.50, 'Restaurants', 'Coffee'],
        ];
        foreach ($cashExp as [$date, $amt, $cat, $desc]) {
            $this->tx('CASH', 'cash', $date, $amt, 'PAYMENT', $cat, null, $desc, ['Personal']);
        }
    }

    private function seedUsd(): void
    {
        $deposits = [
            ['2025-04-05', 1200, 'Freelance Project Payment'],
            ['2025-05-03', 800, 'Freelance Consulting'],
            ['2025-05-20', 450, 'Side Project Payment'],
            ['2025-06-04', 1500, 'Freelance Project Payment'],
            ['2025-07-06', 900, 'Freelance Consulting'],
            ['2025-08-03', 1100, 'Freelance Project Payment'],
            ['2025-08-22', 400, 'Bug Fix Bounty'],
            ['2025-09-05', 1400, 'Freelance Project Payment'],
            ['2025-10-03', 800, 'Freelance Consulting'],
            ['2025-11-04', 1200, 'Freelance Project Payment'],
            ['2025-11-18', 350, 'Code Review Payment'],
            ['2025-12-02', 1000, 'Freelance Consulting'],
            ['2026-01-06', 1300, 'Freelance Project Payment'],
            ['2026-02-04', 900, 'Freelance Consulting'],
            ['2026-03-03', 1100, 'Freelance Project Payment'],
        ];
        foreach ($deposits as [$date, $amt, $desc]) {
            $this->tx('FREE', 'usd', $date, $amt, 'DEPOSIT', 'Freelance', 'Freelance Client Inc.', $desc, ['Business']);
        }
    }

    private function seedSeasonal(): void
    {
        // July 2025: Travel (Main Checking)
        $t = ['Travel'];
        $this->tx('TRVL', 'chk', '2025-07-10', -350, 'CARD_PAYMENT', 'Transportation', null, 'Flight - Berlin to Barcelona', $t);
        $this->tx('TRVL', 'chk', '2025-07-10', -450, 'CARD_PAYMENT', 'Housing', null, 'Hotel Barcelona (5 nights)', $t);
        $this->tx('TRVL', 'chk', '2025-07-12', -85, 'CARD_PAYMENT', 'Restaurants', null, 'Restaurant La Boqueria', $t);
        $this->tx('TRVL', 'chk', '2025-07-13', -65, 'CARD_PAYMENT', 'Restaurants', null, 'Tapas Bar El Nacional', $t);
        $this->tx('TRVL', 'chk', '2025-07-14', -48, 'CARD_PAYMENT', 'Entertainment', null, 'Sagrada Familia Tickets', $t);

        // December 2025: Gifts (Credit Card)
        $g = ['Gift'];
        $this->tx('GIFT', 'cc', '2025-12-08', -85, 'CARD_PAYMENT', 'Electronics', 'Amazon', 'Christmas Gift - Headphones', $g);
        $this->tx('GIFT', 'cc', '2025-12-10', -45, 'CARD_PAYMENT', 'Shopping', null, 'Christmas Gift - Books', $g);
        $this->tx('GIFT', 'cc', '2025-12-15', -150, 'CARD_PAYMENT', 'Electronics', 'Apple', 'Christmas Gift - AirPods', $g);
        $this->tx('GIFT', 'cc', '2025-12-18', -35, 'CARD_PAYMENT', 'Clothing', 'Zara', 'Christmas Gift - Scarf', $g);
    }

    private function finalize(): void
    {
        foreach ($this->acct as $key => $acct) {
            $acct->update(['balance' => $this->bal[$key]]);
        }
    }

    /**
     * @param  array<string>  $tags
     * @param  array<string, mixed>  $extra
     */
    private function tx(
        string $prefix,
        string $acctKey,
        string $date,
        float $amount,
        string $type,
        ?string $cat = null,
        ?string $cp = null,
        string $desc = '',
        array $tags = [],
        array $extra = [],
    ): void {
        $ym = substr($date, 0, 7);
        $this->seq++;
        $acct = $this->acct[$acctKey];

        $this->bal[$acctKey] = round($this->bal[$acctKey] + $amount, 2);

        $data = array_merge([
            'transaction_id' => sprintf('%s-%s-%04d', $prefix, str_replace('-', '', $ym), $this->seq),
            'amount' => $amount,
            'currency' => $acct->currency,
            'booked_date' => $date,
            'processed_date' => $date,
            'description' => $desc,
            'type' => $type,
            'account_id' => $acct->id,
            'category_id' => $cat !== null ? ($this->cat[$cat] ?? null) : null,
            'counterparty_id' => $cp !== null ? ($this->cp[$cp] ?? null) : null,
            'balance_after_transaction' => $this->bal[$acctKey],
        ], $extra);

        $data['fingerprint'] = Transaction::generateFingerprint($data);

        $transaction = Transaction::create($data);

        if ($tags !== []) {
            $tagIds = array_filter(array_map(fn (string $t): int|string|null => $this->tag[$t] ?? null, $tags));
            if ($tagIds !== []) {
                $transaction->tags()->attach($tagIds);
            }
        }
    }
}

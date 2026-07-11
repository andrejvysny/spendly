<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Recurring Transaction Detection
    |--------------------------------------------------------------------------
    |
    | Algorithm tunables for the rule-based recurring detector. Per-user
    | preferences (variance, min occurrences, lookback) live in the
    | recurring_detection_settings table; these are the global knobs.
    |
    */

    // Fallback lookback window when the user setting is missing/zero.
    'lookback_months_default' => (int) env('RECURRING_LOOKBACK_MONTHS_DEFAULT', 24),

    // Suggestions scoring below this confidence (0-100) are not created.
    'min_confidence' => (int) env('RECURRING_MIN_CONFIDENCE', 40),

    // Fraction of inter-payment gaps that must fit the interval window
    // (after dividing by k for skipped occurrences).
    'gap_quorum' => 0.75,

    // A single gap may span up to this many consecutive expected occurrences
    // (k * interval), i.e. up to k-1 missed payments.
    'max_missed_multiplier' => 3,

    // Max distinct price levels (plateaus) a series may show within the
    // lookback, e.g. a subscription with two price increases = 3 plateaus.
    'max_amount_plateaus' => 3,

    // A single price step larger than this percentage is not a plateau change.
    'max_step_change_pct' => 100,

    // Absolute floor for percent-based amount tolerance (helps small amounts).
    'amount_tolerance_floor' => 0.50,

    // Payees charging more often than this per month are habitual merchants
    // (groceries, cafes), not subscriptions - unless a clean weekly cadence fits.
    'high_frequency_guard_per_month' => 4.0,

    // Interval windows in days between consecutive payments. min_occurrences is
    // the floor for that interval; short intervals also respect the (higher)
    // per-user setting, long intervals (semiannual/yearly) ignore it because
    // requiring 3+ yearly payments would make detection impossible.
    'intervals' => [
        'weekly' => ['min' => 5, 'max' => 10, 'nominal' => 7, 'min_occurrences' => 4],
        'biweekly' => ['min' => 11, 'max' => 18, 'nominal' => 14, 'min_occurrences' => 3],
        'monthly' => ['min' => 25, 'max' => 36, 'nominal' => 30, 'min_occurrences' => 3],
        'quarterly' => ['min' => 80, 'max' => 100, 'nominal' => 91, 'min_occurrences' => 3],
        'semiannual' => ['min' => 170, 'max' => 195, 'nominal' => 182, 'min_occurrences' => 2],
        'yearly' => ['min' => 350, 'max' => 380, 'nominal' => 365, 'min_occurrences' => 2],
    ],

];

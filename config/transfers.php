<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Transfer Detection
    |--------------------------------------------------------------------------
    |
    | Tunables for the rule-based transfer detector. All values have safe
    | defaults; env overrides are optional.
    |
    */

    // Max days between the debit and credit legs' booked dates (SEPA often settles 2-3 days apart).
    'date_gap_days' => (int) env('TRANSFERS_DATE_GAP_DAYS', 3),

    // Absolute amount tolerance between legs (rounding differences).
    'amount_tolerance' => (float) env('TRANSFERS_AMOUNT_TOLERANCE', 0.01),

    // Optional relative tolerance (percent) for fee-skimmed transfers.
    // Effective tolerance = max(amount_tolerance, amount_tolerance_percent/100 * amount).
    'amount_tolerance_percent' => (float) env('TRANSFERS_AMOUNT_TOLERANCE_PERCENT', 0.0),

    'heuristic' => [
        // Score at/above which a no-IBAN candidate pair is auto-marked as a transfer.
        'auto_threshold' => (float) env('TRANSFERS_HEURISTIC_AUTO_THRESHOLD', 0.60),
        // Score at/above which (but below auto) both legs are flagged for manual review.
        'review_threshold' => (float) env('TRANSFERS_HEURISTIC_REVIEW_THRESHOLD', 0.35),
    ],

    'cross_currency' => [
        'enabled' => (bool) env('TRANSFERS_CROSS_CURRENCY_ENABLED', true),
        // Allowed deviation when reconciling amounts via exchange rate / native amounts.
        'tolerance_percent' => (float) env('TRANSFERS_CROSS_CURRENCY_TOLERANCE_PERCENT', 3.0),
        // When false (default) candidates are flagged for review instead of auto-paired.
        'auto_mark' => (bool) env('TRANSFERS_CROSS_CURRENCY_AUTO_MARK', false),
    ],

    'single_leg' => [
        // Auto-mark internal single-leg moves (e.g. Revolut pocket/vault) as transfers.
        'auto_mark' => (bool) env('TRANSFERS_SINGLE_LEG_AUTO_MARK', true),
        // Case/diacritics-insensitive substrings matched against description/partner.
        'patterns' => [
            'revolut vault',
            'to pocket',
            'from pocket',
            'pocket withdrawal',
            'roundups',
        ],
    ],

    // Days subtracted from the earliest booked date of a sync/import batch when
    // windowing the automatic detection runs.
    'detection_window_padding_days' => (int) env('TRANSFERS_WINDOW_PADDING_DAYS', 3),

];

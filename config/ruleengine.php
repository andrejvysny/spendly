<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rule Engine Processing Mode
    |--------------------------------------------------------------------------
    |
    | Determine how transaction rules are processed.
    | Supported values: "sync" or "async". When set to "sync",
    | rules are executed immediately within the request.
    | "async" will queue rule processing via a listener.
    |
    */
    'processing_mode' => env('RULE_ENGINE_PROCESSING_MODE', 'async'),
];


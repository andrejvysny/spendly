<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduling lives in bootstrap/app.php. exchange-rates:fetch used to be registered here
// too, at a different time, so the job ran twice a day against the ECB API.

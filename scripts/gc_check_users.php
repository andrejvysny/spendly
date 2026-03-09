<?php

use App\Models\User;

require_once __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$users = User::select('id', 'name', 'email', 'gocardless_secret_id')->get();
foreach ($users as $u) {
    $hasCreds = ! empty($u->gocardless_secret_id) ? 'yes' : 'no';
    echo "{$u->id} | {$u->name} | {$u->email} | has_creds:{$hasCreds}\n";
}

<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Idempotency records are only meaningful until they expire. Hourly rather
// than daily so a key freed early by expiry is reusable soon after.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->hourly();

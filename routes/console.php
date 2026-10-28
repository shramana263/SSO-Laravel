<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Sync Central User Database & Product Access Roles from Legacy Databases.
 * Runs periodically to ingest new/updated users from Stellar, SFA, Saathi, and Link.
 */
Schedule::command('sso:sync-legacy --refresh')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();


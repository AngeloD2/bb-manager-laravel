<?php

use Illuminate\Support\Facades\Schedule;

// Sweep away assets whose optimization/conversion failed (see CleanupFailedAssets).
Schedule::command('assets:cleanup-failed')
    ->cron('*/12 * * * *')
    ->withoutOverlapping();

// Sweep away S3 uploads that were presigned and PUT, but never confirmed.
Schedule::command('assets:cleanup-orphans')
    ->cron('*/12 * * * *')
    ->withoutOverlapping();

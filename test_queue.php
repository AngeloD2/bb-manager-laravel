<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$device = \App\Models\Device::first();
dump("Device is frozen: " . ($device->is_frozen ? 'true' : 'false'));
$queue = app(\App\Services\QueueGenerationService::class)->getUpcomingQueue($device);
dump($queue);

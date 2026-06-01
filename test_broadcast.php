<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $device = \App\Models\Device::first();
    $asset = \App\Models\MediaAsset::first();
    broadcast(new \App\Events\PlaybackStarted($device, $asset, now()->toIso8601String()));
    echo "Broadcast successful.\n";
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$device = \App\Models\Device::first();
if (!$device) { echo "No device\n"; exit; }

$controller = new \App\Http\Controllers\Api\V1\DeviceController();
$response = $controller->schedule($device);
echo $response->getContent() . "\n";

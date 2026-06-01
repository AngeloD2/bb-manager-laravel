<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class CreateDeviceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'device:create {name} {--location=Unknown} {--zone=Default}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a new Billboard device and generate its API spot';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $device = Device::create([
            'name' => $this->argument('name'),
            'location' => $this->option('location'),
            'geo_zone' => $this->option('zone'),
        ]);

        // Billboards need 'device:sync' and 'device:log' abilities to function
        $spot = $device->createToken('board', ['device:sync', 'device:log'])->plainTextToken;

        $this->info("Billboard Device '{$device->name}' created successfully!");
        $this->line("ID: {$device->id}");
        $this->warn("Device Access Spot (Save this!): " . $spot);
        
        $this->line("\nYou can now use this spot to authenticate a billboard player script.");
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Billboard;
use Illuminate\Console\Command;

class CreateBillboardCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billboard:create {name} {--location=Unknown} {--zone=Default}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a new Billboard and generate its API token';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $billboard = Billboard::create([
            'name' => $this->argument('name'),
            'location' => $this->option('location'),
            'geo_zone' => $this->option('zone'),
        ]);

        // Billboards need 'billboard:sync' and 'billboard:log' abilities to function
        $token = $billboard->createToken('board', ['billboard:sync', 'billboard:log'])->plainTextToken;

        $this->info("Billboard '{$billboard->name}' created successfully!");
        $this->line("ID: {$billboard->id}");
        $this->warn("Billboard Access Token (Save this!): " . $token);
        
        $this->line("\nYou can now use this spot to authenticate a billboard player script.");
    }
}

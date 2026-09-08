<?php

namespace App\Console\Commands;

use App\Models\Billboard;
use App\Models\Zone;
use Illuminate\Console\Command;

class CreateBillboardCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billboard:create {name} {--location=Unknown} {--zone=}';

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
        $zone = null;

        if ($this->option('zone') !== null && $this->option('zone') !== '') {
            $zone = Zone::whereRaw('lower(name) = ?', [mb_strtolower($this->option('zone'))])->first();

            // A zone that does not resolve is refused rather than ignored: zone
            // targeting gates eligibility, so a typo swallowed here becomes a
            // paid spot that silently never plays.
            if ($zone === null) {
                $this->error("No zone named \"{$this->option('zone')}\".");

                $known = Zone::orderBy('name')->pluck('name');
                $this->line($known->isEmpty()
                    ? 'No zones exist yet. Create one before assigning a billboard to it.'
                    : 'Known zones: ' . $known->implode(', '));

                return self::FAILURE;
            }
        }

        $billboard = Billboard::create([
            'name'     => $this->argument('name'),
            'location' => $this->option('location'),
            'zone_id'  => $zone?->id,
        ]);

        // Billboards need 'billboard:sync' and 'billboard:log' abilities to function
        $token = $billboard->createToken('board', ['billboard:sync', 'billboard:log'])->plainTextToken;

        $this->info("Billboard '{$billboard->name}' created successfully!");
        $this->line("ID: {$billboard->id}");
        $this->line('Zone: ' . ($zone?->name ?? 'none'));
        $this->warn("Billboard Access Token (Save this!): " . $token);

        if ($zone === null) {
            $this->warn('This billboard is in no zone, so zone-targeted assets will not play on it.');
        }

        $this->line("\nYou can now use this token to authenticate a billboard player script.");

        return self::SUCCESS;
    }
}

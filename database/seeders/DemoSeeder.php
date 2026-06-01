<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use Illuminate\Database\Seeder;

/**
 * DemoSeeder — Seeds demo loops, assets, and devices for local development.
 *
 * Run manually with:  php artisan db:seed --class=DemoSeeder
 * NOT called by DatabaseSeeder — production databases start clean.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Loops ───────────────────────────────────────────────────────────

        $promoLoop = MediaLoop::create([
            'name'             => 'Promo Campaigns',
            'is_fallback'      => false,
            'is_global'        => true,
            'max_daily_spots' => 50,
        ]);

        $brandLoop = MediaLoop::create([
            'name'             => 'Brand Sponsorships',
            'is_fallback'      => false,
            'is_global'        => true,
            'max_daily_spots' => 80,
        ]);

        $fallbackLoop = MediaLoop::create([
            'name'        => 'Filler House Ads (Fallback)',
            'is_fallback' => true,
            'is_global'   => true,
        ]);

        // ── Assets ────────────────────────────────────────────────────────────

        MediaAsset::insert([
            [
                'id' => \Str::uuid(), 'name' => 'Coca-Cola Summer Splash',
                'file_path' => 'media/2026/01/coca-cola-summer.mp4', 'file_type' => 'VIDEO',
                'loop_id' => $promoLoop->id, 'size_bytes' => 14_500_000,
                'duration_secs' => 15, 'geo_campaign' => 'Downtown Core',
                'campaign_name' => 'Summer Splash 2026', 'is_synced' => true,
                'max_plays_per_hour' => 2, 'max_daily_plays' => null, 'play_spots_remaining' => 35,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Nike Running Elite',
                'file_path' => 'media/2026/01/nike-flyknit.gif', 'file_type' => 'GIF',
                'loop_id' => $promoLoop->id, 'size_bytes' => 2_400_000,
                'duration_secs' => 10, 'geo_campaign' => 'West Coast Highways',
                'campaign_name' => 'Run Free', 'is_synced' => true,
                'max_plays_per_hour' => 3, 'max_daily_plays' => null, 'play_spots_remaining' => 50,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'BMW Electric Future',
                'file_path' => 'media/2026/01/bmw-i4.mp4', 'file_type' => 'VIDEO',
                'loop_id' => $brandLoop->id, 'size_bytes' => 18_200_000,
                'duration_secs' => 15, 'geo_campaign' => 'Metro Transit Terminals',
                'campaign_name' => 'BMW Electric', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_spots_remaining' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Spotify Local Artist Spot',
                'file_path' => 'media/2026/01/spotify-pride.png', 'file_type' => 'PHOTO',
                'loop_id' => $brandLoop->id, 'size_bytes' => 900_000,
                'duration_secs' => 8, 'geo_campaign' => 'Downtown Core',
                'campaign_name' => 'Pride Music', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_spots_remaining' => 15,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Ad Space Available Call 555',
                'file_path' => 'media/2026/01/house-ad-1.png', 'file_type' => 'PHOTO',
                'loop_id' => $fallbackLoop->id, 'size_bytes' => 400_000,
                'duration_secs' => 8, 'geo_campaign' => 'All Zones',
                'campaign_name' => 'Promo Fillers', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_spots_remaining' => 999999,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Local Weather Service Widget',
                'file_path' => 'media/2026/01/weather-widget.gif', 'file_type' => 'GIF',
                'loop_id' => $fallbackLoop->id, 'size_bytes' => 1_200_000,
                'duration_secs' => 12, 'geo_campaign' => 'All Zones',
                'campaign_name' => 'Promo Fillers', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_spots_remaining' => 999999,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // ── Devices ───────────────────────────────────────────────────────────

        $boardAlpha = Device::create([
            'name'     => 'Board Alpha — Downtown Core',
            'location' => 'Main St & 5th Ave',
            'geo_zone' => 'Downtown Core',
        ]);

        $boardBeta = Device::create([
            'name'     => 'Board Beta — Highway 1',
            'location' => 'I-5 North Exit 42',
            'geo_zone' => 'West Coast Highways',
        ]);

        // Provision Sanctum tokens and print them (dev only)
        $tokenAlpha = $boardAlpha->createToken('device-alpha', ['device:sync', 'device:log'])->plainTextToken;
        $tokenBeta  = $boardBeta->createToken('device-beta',  ['device:sync', 'device:log'])->plainTextToken;

        $this->command->info('');
        $this->command->info('┌─────────────────────────────────────────────────────────────┐');
        $this->command->info('│  BCC — Seeded Device Tokens (store these securely on boards) │');
        $this->command->info('├─────────────────────────────────────────────────────────────┤');
        $this->command->info("│  Board Alpha: {$tokenAlpha}");
        $this->command->info("│  Board Beta:  {$tokenBeta}");
        $this->command->info('└─────────────────────────────────────────────────────────────┘');
        $this->command->info('');
    }
}

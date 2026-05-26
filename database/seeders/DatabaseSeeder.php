<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin User ────────────────────────────────────────────────────────
        \App\Models\User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);

        // ── Folders ───────────────────────────────────────────────────────────

        $promoFolder = MediaFolder::create([
            'name'             => 'Promo Campaigns',
            'is_fallback'      => false,
            'max_daily_tokens' => 50,
        ]);

        $brandFolder = MediaFolder::create([
            'name'             => 'Brand Sponsorships',
            'is_fallback'      => false,
            'max_daily_tokens' => 80,
        ]);

        $fallbackFolder = MediaFolder::create([
            'name'        => 'Filler House Ads (Fallback)',
            'is_fallback' => true,
        ]);

        // ── Assets ────────────────────────────────────────────────────────────

        MediaAsset::insert([
            [
                'id' => \Str::uuid(), 'name' => 'Coca-Cola Summer Splash',
                'file_path' => 'media/2026/01/coca-cola-summer.mp4', 'file_type' => 'VIDEO',
                'folder_id' => $promoFolder->id, 'size_bytes' => 14_500_000,
                'duration_secs' => 15, 'geo_campaign' => 'Downtown Core',
                'campaign_name' => 'Summer Splash 2026', 'is_synced' => true,
                'max_plays_per_hour' => 2, 'max_daily_plays' => null, 'play_tokens_remaining' => 35,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Nike Running Elite',
                'file_path' => 'media/2026/01/nike-flyknit.gif', 'file_type' => 'GIF',
                'folder_id' => $promoFolder->id, 'size_bytes' => 2_400_000,
                'duration_secs' => 10, 'geo_campaign' => 'West Coast Highways',
                'campaign_name' => 'Run Free', 'is_synced' => true,
                'max_plays_per_hour' => 3, 'max_daily_plays' => null, 'play_tokens_remaining' => 50,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'BMW Electric Future',
                'file_path' => 'media/2026/01/bmw-i4.mp4', 'file_type' => 'VIDEO',
                'folder_id' => $brandFolder->id, 'size_bytes' => 18_200_000,
                'duration_secs' => 15, 'geo_campaign' => 'Metro Transit Terminals',
                'campaign_name' => 'BMW Electric', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_tokens_remaining' => 60,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Spotify Local Artist Spot',
                'file_path' => 'media/2026/01/spotify-pride.png', 'file_type' => 'PHOTO',
                'folder_id' => $brandFolder->id, 'size_bytes' => 900_000,
                'duration_secs' => 8, 'geo_campaign' => 'Downtown Core',
                'campaign_name' => 'Pride Music', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_tokens_remaining' => 15,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Ad Space Available Call 555',
                'file_path' => 'media/2026/01/house-ad-1.png', 'file_type' => 'PHOTO',
                'folder_id' => $fallbackFolder->id, 'size_bytes' => 400_000,
                'duration_secs' => 8, 'geo_campaign' => 'All Zones',
                'campaign_name' => 'Promo Fillers', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_tokens_remaining' => 999999,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => \Str::uuid(), 'name' => 'Local Weather Service Widget',
                'file_path' => 'media/2026/01/weather-widget.gif', 'file_type' => 'GIF',
                'folder_id' => $fallbackFolder->id, 'size_bytes' => 1_200_000,
                'duration_secs' => 12, 'geo_campaign' => 'All Zones',
                'campaign_name' => 'Promo Fillers', 'is_synced' => true,
                'max_plays_per_hour' => null, 'max_daily_plays' => null, 'play_tokens_remaining' => 999999,
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

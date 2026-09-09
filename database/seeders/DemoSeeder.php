<?php

namespace Database\Seeders;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\Campaign;
use App\Models\MediaLoop;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * DemoSeeder — Seeds demo zones, loops, assets, and billboards for local development.
 *
 * Run manually with:  php artisan db:seed --class=DemoSeeder
 * NOT called by DatabaseSeeder — production databases start clean.
 *
 * Re-runnable: every row is keyed by name through firstOrCreate, so seeding a
 * database that already has the demo data updates it instead of duplicating it.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── Zones ───────────────────────────────────────────────────────────
        // Targeting used to be a free-text name on the asset ('geo_campaign')
        // and the billboard ('geo_zone'). Both columns are gone: zones are rows
        // now, an asset targets them by id through targeted_zones, and a board
        // belongs to one through zone_id.

        $downtown = Zone::firstOrCreate(['name' => 'Downtown Core']);
        $highways = Zone::firstOrCreate(['name' => 'West Coast Highways']);
        $transit  = Zone::firstOrCreate(['name' => 'Metro Transit Terminals']);

        // ── Campaigns ───────────────────────────────────────────────────────
        // A booking that owns the loops running during it. The fallback loop
        // gets none: unsold inventory has no campaign.

        $september = Campaign::firstOrCreate(
            ['name' => 'September 2026'],
            ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30'],
        );

        // ── Loops ───────────────────────────────────────────────────────────

        $promoLoop = MediaLoop::firstOrCreate(
            ['name' => 'Promo Campaigns'],
            [
                'campaign_id'     => $september->id,
                'is_fallback'     => false,
                'is_global'       => true,
                'max_daily_spots' => 50,
            ],
        );

        $brandLoop = MediaLoop::firstOrCreate(
            ['name' => 'Brand Sponsorships'],
            [
                'campaign_id'     => $september->id,
                'is_fallback'     => false,
                'is_global'       => true,
                'max_daily_spots' => 80,
            ],
        );

        $fallbackLoop = MediaLoop::firstOrCreate(
            ['name' => 'Filler House Ads (Fallback)'],
            [
                'is_fallback' => true,
                'is_global'   => true,
            ],
        );

        // ── Assets ──────────────────────────────────────────────────────────
        // targeted_zones is a list of zone ids; null means "no zone restriction"
        // and eligibility falls through to the loop's assignment, which is what
        // the old 'All Zones' string meant. Written through create() rather than
        // insert() so the model's array cast encodes the json column.
        //
        // These reference media/2026/01/* object keys that do not exist in the
        // bucket. That is deliberate: the rows exercise scheduling, quota and
        // eligibility without needing uploads. A board pointed at them will
        // build a queue and then fail to fetch the media.

        $assets = [
            ['Coca-Cola Summer Splash',      'media/2026/01/coca-cola-summer.mp4', 'VIDEO', $promoLoop,    14_500_000, 15, [$downtown->id], 2,    null, 35],
            ['Nike Running Elite',           'media/2026/01/nike-flyknit.gif',     'GIF',   $promoLoop,     2_400_000, 10, [$highways->id], 3,    null, 50],
            ['BMW Electric Future',          'media/2026/01/bmw-i4.mp4',           'VIDEO', $brandLoop,    18_200_000, 15, [$transit->id],  null, null, 60],
            ['Spotify Local Artist Spot',    'media/2026/01/spotify-pride.png',    'PHOTO', $brandLoop,       900_000,  8, [$downtown->id], null, null, 15],
            ['Ad Space Available Call 555',  'media/2026/01/house-ad-1.png',       'PHOTO', $fallbackLoop,    400_000,  8, null,            null, null, 999999],
            ['Local Weather Service Widget', 'media/2026/01/weather-widget.gif',   'GIF',   $fallbackLoop,  1_200_000, 12, null,            null, null, 999999],
        ];

        foreach ($assets as [$name, $path, $type, $loop, $size, $duration, $zones, $perHour, $perDay, $spots]) {
            MediaAsset::firstOrCreate(
                ['name' => $name],
                [
                    'file_path'            => $path,
                    'file_type'            => $type,
                    'loop_id'              => $loop->id,
                    'size_bytes'           => $size,
                    'duration_secs'        => $duration,
                    'targeted_zones'       => $zones,
                    'is_synced'            => true,
                    'max_plays_per_hour'   => $perHour,
                    'max_daily_plays'      => $perDay,
                    'play_spots_remaining' => $spots,
                ],
            );
        }

        // ── Billboards ──────────────────────────────────────────────────────
        // Active hours span the whole day so a seeded board has spots to spend
        // whenever the seeder happens to be run.

        $boardAlpha = Billboard::firstOrCreate(
            ['name' => 'Board Alpha — Downtown Core'],
            [
                'location'            => 'Main St & 5th Ave',
                'zone_id'             => $downtown->id,
                'active_hours_start'  => '00:00',
                'active_hours_end'    => '23:59',
            ],
        );

        $boardBeta = Billboard::firstOrCreate(
            ['name' => 'Board Beta — Highway 1'],
            [
                'location'            => 'I-5 North Exit 42',
                'zone_id'             => $highways->id,
                'active_hours_start'  => '00:00',
                'active_hours_end'    => '23:59',
            ],
        );

        // The player identifies a board by its password, so a demo board without
        // one cannot be connected to. Regenerated on every run: the plaintext is
        // encrypted at rest and cannot be read back to re-print it.
        $passwordAlpha = Billboard::generatePassword();
        $passwordBeta  = Billboard::generatePassword();

        $boardAlpha->setPassword($passwordAlpha);
        $boardAlpha->save();

        $boardBeta->setPassword($passwordBeta);
        $boardBeta->save();

        $this->command->info('');
        $this->command->info('┌──────────────────────────────────────────────────────────────┐');
        $this->command->info('│  BCC — Seeded Billboard Passwords (enter these in the player) │');
        $this->command->info('├──────────────────────────────────────────────────────────────┤');
        $this->command->info("│  Board Alpha: {$passwordAlpha}");
        $this->command->info("│  Board Beta:  {$passwordBeta}");
        $this->command->info('└──────────────────────────────────────────────────────────────┘');
        $this->command->info('');
    }
}

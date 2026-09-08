<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\User;
use App\Services\ConstraintValidationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private function asset(?Campaign $campaign, ?string $from = null, ?string $until = null): MediaAsset
    {
        $loop = MediaLoop::create([
            'name'        => 'Loop ' . uniqid(),
            'campaign_id' => $campaign?->id,
            'is_fallback' => false,
        ]);

        return MediaAsset::create([
            'name'                 => 'Ad ' . uniqid(),
            'file_path'            => 'media/ad.mp4',
            'file_type'            => 'VIDEO',
            'loop_id'              => $loop->id,
            'size_bytes'           => 1000,
            'duration_secs'        => 10,
            'is_synced'            => true,
            'play_spots_remaining' => 10,
            'runs_from'            => $from,
            'runs_until'           => $until,
        ])->load('loop.campaign');
    }

    // ── The effective window is the campaign's, narrowed by the asset's ──────

    /** @test */
    public function an_asset_with_no_campaign_and_no_dates_is_always_in_flight(): void
    {
        $asset = $this->asset(null);

        $this->assertSame([null, null], $asset->effectiveFlightWindow());
        $this->assertTrue($asset->isWithinFlightWindow(Carbon::parse('2030-01-01')));
    }

    /** @test */
    public function an_asset_inherits_its_campaigns_window(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $asset = $this->asset($campaign);

        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-09-30')));
        $this->assertTrue($asset->isWithinFlightWindow(Carbon::parse('2026-10-15')));
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-11-01')));
    }

    /** @test */
    public function an_asset_may_narrow_its_campaigns_window(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $asset = $this->asset($campaign, '2026-10-10', '2026-10-20');

        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-10-05')));
        $this->assertTrue($asset->isWithinFlightWindow(Carbon::parse('2026-10-15')));
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-10-25')));
    }

    /** @test */
    public function an_asset_cannot_widen_its_campaigns_window(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        // The asset asks for all of September through November; the campaign wins.
        $asset = $this->asset($campaign, '2026-09-01', '2026-11-30');

        [$from, $until] = $asset->effectiveFlightWindow();

        $this->assertSame('2026-10-01', $from->format('Y-m-d'));
        $this->assertSame('2026-10-31', $until->format('Y-m-d'));
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-09-15')));
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-11-15')));
    }

    /** @test */
    public function an_asset_dated_outside_its_campaign_never_airs(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $asset = $this->asset($campaign, '2026-12-01', '2026-12-31');

        // The intersection is empty, so no date can satisfy it.
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-10-15')));
        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-12-15')));
    }

    /** @test */
    public function an_asset_with_no_campaign_still_honours_its_own_dates(): void
    {
        $asset = $this->asset(null, '2026-10-01', '2026-10-31');

        $this->assertFalse($asset->isWithinFlightWindow(Carbon::parse('2026-09-30')));
        $this->assertTrue($asset->isWithinFlightWindow(Carbon::parse('2026-10-15')));
    }

    // ── The eligibility gate uses it ─────────────────────────────────────────

    /** @test */
    public function the_constraint_validator_rejects_an_asset_outside_its_campaign_window(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $asset = $this->asset($campaign);

        $validator = app(ConstraintValidationService::class);

        $this->assertSame(
            ConstraintValidationService::OUTSIDE_FLIGHT_DATES,
            $validator->validate($asset, null, Carbon::parse('2026-09-15'))
        );
        $this->assertSame(
            ConstraintValidationService::VALID,
            $validator->validate($asset, null, Carbon::parse('2026-10-15'))
        );
    }

    // ── A loop with no campaign is unsold inventory, not an error ────────────

    /** @test */
    public function a_fallback_loop_needs_no_campaign(): void
    {
        $loop = MediaLoop::create(['name' => 'Filler', 'is_fallback' => true]);

        $this->assertNull($loop->campaign_id);
        $this->assertNull($loop->campaign);
    }

    // ── API ──────────────────────────────────────────────────────────────────

    /** @test */
    public function an_admin_can_create_a_campaign(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/campaigns', [
                'name'      => 'October 2026',
                'starts_on' => '2026-10-01',
                'ends_on'   => '2026-10-31',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'October 2026');

        $this->assertDatabaseHas('campaigns', ['name' => 'October 2026']);
    }

    /** @test */
    public function a_campaign_end_date_cannot_precede_its_start(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/campaigns', [
                'name'      => 'Backwards',
                'starts_on' => '2026-10-31',
                'ends_on'   => '2026-10-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_on']);
    }

    /** @test */
    public function deleting_a_campaign_releases_its_loops_rather_than_removing_them(): void
    {
        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $loop = MediaLoop::create(['name' => 'Promo', 'campaign_id' => $campaign->id]);

        $this->actAsAdmin()
            ->deleteJson("/api/v1/admin/campaigns/{$campaign->id}")
            ->assertOk();

        $this->assertSoftDeleted('campaigns', ['id' => $campaign->id]);
        // Unsold inventory is still inventory.
        $this->assertDatabaseHas('media_loops', ['id' => $loop->id, 'campaign_id' => null]);
    }

    // ── The board never learns what a Campaign is ────────────────────────────

    /** @test */
    public function the_sync_payload_flattens_the_effective_window_for_the_board(): void
    {
        $billboard = Billboard::create(['name' => 'Board Alpha', 'location' => 'Main St']);
        $token = $billboard->createToken('board', ['billboard:sync', 'billboard:log'])->plainTextToken;

        $campaign = Campaign::create(['name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $loop = MediaLoop::create([
            'name' => 'Promo', 'campaign_id' => $campaign->id,
            'is_fallback' => false, 'is_global' => true,
        ]);
        // Asks to run all of September-November; the campaign narrows it.
        $asset = MediaAsset::create([
            'name' => 'Nike Ad', 'file_path' => 'media/nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
            'runs_from' => '2026-09-01', 'runs_until' => '2026-11-30',
        ]);

        // Sync from inside the campaign window, or the asset is correctly
        // ineligible and never reaches the payload at all.
        $this->travelTo(Carbon::parse('2026-10-15'));

        $response = $this->withToken($token)->getJson('/api/v1/sync')->assertOk();

        // The board receives the intersection under the keys it already reads,
        // so scheduler.js and the parity fixture need no knowledge of Campaigns.
        $response->assertJsonPath("data.quota.assets.{$asset->id}.campaign_start_date", '2026-10-01');
        $response->assertJsonPath("data.quota.assets.{$asset->id}.campaign_end_date", '2026-10-31');

        // And no Campaign leaks into the payload's asset records.
        $this->assertArrayNotHasKey('campaign_name', $response->json('data.eligible_assets.0'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function actAsAdmin(): static
    {
        $adminUser = User::create([
            'name'     => 'Admin Test User',
            'username' => 'admin-test-' . uniqid(),
            'password' => Hash::make('password'),
        ]);
        $token = $adminUser->createToken('admin-token', ['admin'])->plainTextToken;

        return $this->withToken($token);
    }
}

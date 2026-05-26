<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\SecureShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecureShareLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $pin   = '482910';
    private string $token = 'abc12def';

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeLink(array $overrides = []): SecureShareLink
    {
        return SecureShareLink::create(array_merge([
            'label'         => 'Test Campaign Proof',
            'folder_id'     => null,
            'asset_id'      => null,
            'token'         => $this->token,
            'password_hash' => Hash::make($this->pin),
            'expires_at'    => now()->addHours(2),
            'is_one_time'   => false,
            'is_expired'    => false,
            'used_count'    => 0,
        ], $overrides));
    }

    // ── Valid access ──────────────────────────────────────────────────────────

    /** @test */
    public function valid_pin_and_token_returns_200_with_payload(): void
    {
        $this->makeLink();

        $response = $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ]);

        $response->assertOk()->assertJsonPath('data.label', 'Test Campaign Proof');
    }

    // ── Wrong PIN ─────────────────────────────────────────────────────────────

    /** @test */
    public function wrong_pin_returns_401(): void
    {
        $this->makeLink();

        $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => '000000',
        ])->assertStatus(401);
    }

    // ── Missing link ──────────────────────────────────────────────────────────

    /** @test */
    public function unknown_token_returns_404(): void
    {
        $this->postJson('/api/v1/vault/verify', [
            'token' => 'xxxxxxxx',
            'pin'   => $this->pin,
        ])->assertNotFound();
    }

    // ── Expired (time) ────────────────────────────────────────────────────────

    /** @test */
    public function expired_link_returns_410(): void
    {
        $this->makeLink(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ])->assertStatus(410);
    }

    // ── Manually revoked ─────────────────────────────────────────────────────

    /** @test */
    public function revoked_link_returns_410(): void
    {
        $this->makeLink(['is_expired' => true]);

        $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ])->assertStatus(410);
    }

    // ── One-time (OTP) ────────────────────────────────────────────────────────

    /** @test */
    public function otp_link_expires_after_first_successful_use(): void
    {
        $this->makeLink(['is_one_time' => true]);

        // First use: succeeds
        $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ])->assertOk();

        // Second use: rejected
        $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ])->assertStatus(410);
    }

    /** @test */
    public function non_otp_link_can_be_used_multiple_times(): void
    {
        $this->makeLink(['is_one_time' => false]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/vault/verify', [
                'token' => $this->token,
                'pin'   => $this->pin,
            ])->assertOk();
        }

        $this->assertDatabaseHas('secure_share_links', [
            'token'      => $this->token,
            'used_count' => 3,
            'is_expired' => false,
        ]);
    }

    // ── used_count increments ─────────────────────────────────────────────────

    /** @test */
    public function successful_use_increments_used_count(): void
    {
        $this->makeLink();

        $this->postJson('/api/v1/vault/verify', ['token' => $this->token, 'pin' => $this->pin])->assertOk();
        $this->postJson('/api/v1/vault/verify', ['token' => $this->token, 'pin' => $this->pin])->assertOk();

        $this->assertDatabaseHas('secure_share_links', ['token' => $this->token, 'used_count' => 2]);
    }

    // ── Asset delivery URL included ───────────────────────────────────────────

    /** @test */
    public function response_includes_asset_delivery_url_when_link_targets_an_asset(): void
    {
        $asset = MediaAsset::create([
            'name'                  => 'Coca-Cola Proof',
            'file_path'             => 'media/2026/01/coca.mp4',
            'file_type'             => 'VIDEO',
            'size_bytes'            => 5_000_000,
            'duration_secs'         => 15,
            'is_synced'             => true,
            'play_tokens_remaining' => 100,
        ]);

        $this->makeLink(['asset_id' => $asset->id]);

        // Mock S3 so tests don't need real AWS credentials
        \Illuminate\Support\Facades\Storage::fake('s3');

        $response = $this->postJson('/api/v1/vault/verify', [
            'token' => $this->token,
            'pin'   => $this->pin,
        ]);

        $response->assertOk()->assertJsonPath('data.asset.id', $asset->id);
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /** @test */
    public function vault_verify_is_rate_limited_to_10_requests_per_minute(): void
    {
        // Hit the endpoint 11 times; the 11th should be rate-limited
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/vault/verify', ['token' => 'xx', 'pin' => '000000']);
        }

        $this->postJson('/api/v1/vault/verify', ['token' => 'xx', 'pin' => '000000'])
            ->assertStatus(429);
    }
}

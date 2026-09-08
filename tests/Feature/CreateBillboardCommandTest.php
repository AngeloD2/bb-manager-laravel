<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateBillboardCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_files_the_billboard_into_the_named_zone(): void
    {
        $zone = Zone::create(['name' => 'Downtown Core']);

        $this->artisan('billboard:create', ['name' => 'Board Alpha', '--zone' => 'Downtown Core'])
            ->assertSuccessful();

        $this->assertSame($zone->id, Billboard::where('name', 'Board Alpha')->value('zone_id'));
    }

    /** @test */
    public function it_matches_a_zone_name_case_insensitively(): void
    {
        $zone = Zone::create(['name' => 'Downtown Core']);

        $this->artisan('billboard:create', ['name' => 'Board Alpha', '--zone' => 'downtown core'])
            ->assertSuccessful();

        $this->assertSame($zone->id, Billboard::where('name', 'Board Alpha')->value('zone_id'));
    }

    /** @test */
    public function it_refuses_an_unknown_zone_rather_than_silently_dropping_it(): void
    {
        Zone::create(['name' => 'Downtown Core']);

        // A typo must fail loudly: zone targeting gates eligibility, so a zone
        // swallowed here becomes a paid spot that silently never plays.
        $this->artisan('billboard:create', ['name' => 'Board Alpha', '--zone' => 'Downtwon Core'])
            ->expectsOutputToContain('No zone named "Downtwon Core".')
            ->expectsOutputToContain('Known zones: Downtown Core')
            ->assertFailed();

        $this->assertDatabaseMissing('billboards', ['name' => 'Board Alpha']);
    }

    /** @test */
    public function it_allows_a_billboard_with_no_zone_but_warns(): void
    {
        $this->artisan('billboard:create', ['name' => 'Board Alpha'])
            ->expectsOutputToContain('zone-targeted assets will not play on it')
            ->assertSuccessful();

        $billboard = Billboard::where('name', 'Board Alpha')->first();
        $this->assertNotNull($billboard);
        $this->assertNull($billboard->zone_id);
    }
}

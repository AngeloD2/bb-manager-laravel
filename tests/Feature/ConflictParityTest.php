<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Services\ConstraintValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConflictParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_scheduler_matches_canonical_conflict_parity_fixture(): void
    {
        $fixturePath = base_path('tests/fixtures/conflict_parity.json');
        if (!file_exists($fixturePath)) {
            $this->markTestSkipped('Canonical fixture not found.');
        }

        $fx = json_decode(file_get_contents($fixturePath), true);
        
        $assets = [];
        $idMap = [];
        foreach ($fx['assets'] as $aData) {
            $loopId = null;
            if ($aData['loop_id']) {
                $loopId = MediaLoop::firstOrCreate(['id' => (string) Str::uuid(), 'name' => 'loop'])->id;
            }
            
            $asset = MediaAsset::create([
                'name' => $aData['name'],
                'loop_id' => $loopId,
                'file_type' => 'VIDEO',
                'file_path' => 'dummy',
                'duration_secs' => 10,
                'status' => 'ready',
                'play_spots_remaining' => 1000
            ]);
            
            $assets[$aData['id']] = $asset;
            $idMap[$aData['id']] = $asset->id;
        }

        foreach ($fx['conflicts'] as $c) {
            $assets[$c['asset_id_1']]->conflicts()->attach($assets[$c['asset_id_2']]->id, [
                'separation_slots' => $c['separation_slots']
            ]);
            $assets[$c['asset_id_2']]->conflicts()->attach($assets[$c['asset_id_1']]->id, [
                'separation_slots' => $c['separation_slots']
            ]);
        }

        $validator = new ConstraintValidationService();

        foreach ($fx['scenarios'] as $scenario) {
            $candidate = $assets[$scenario['candidate']];
            $history = array_map(fn($id) => $idMap[$id], $scenario['history']);

            $candidate->load('conflicts');
            
            $reason = $validator->validate($candidate, $history);
            $isValid = $reason === ConstraintValidationService::VALID;
            
            if ($scenario['is_override'] ?? false) {
                $this->assertEquals(
                    $scenario['expected'],
                    $reason !== ConstraintValidationService::CONFLICT,
                    "Failed scenario (override): {$scenario['name']}"
                );
            } else {
                $this->assertEquals(
                    $scenario['expected'],
                    $isValid,
                    "Failed scenario: {$scenario['name']}"
                );
            }
        }
    }
}

<?php

namespace App\Services;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\PlaybackLog;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SpotManagerService
 *
 * Processes bulk PlaybackLog submissions from billboard devices.
 * Validates each entry against the spot budget, deducts spots with
 * a DB-level lock to handle concurrent billboard reporting safely,
 * and persists audit logs.
 */
class SpotManagerService
{
    public function __construct(
        private readonly ConstraintValidationService $constraintValidator
    ) {}

    /**
     * Process a batch of raw log entries sent by a billboard device.
     *
     * @param  Device  $device
     * @param  array<int, array{asset_id: string, played_at: string, was_override: bool}>  $entries
     * @return array{accepted: int, rejected: int, errors: array<string>}
     */
    public function processBatch(Device $device, array $entries): array
    {
        $accepted = 0;
        $rejected = 0;
        $errors   = [];

        // Slot length is global; read once and charge each play by its footprint.
        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        foreach ($entries as $entry) {
            try {
                $result = $this->processEntry($device, $entry, $secondsPerSpot);
                $result ? $accepted++ : $rejected++;
            } catch (\Throwable $e) {
                $rejected++;
                $errors[] = "asset_id={$entry['asset_id']}: {$e->getMessage()}";
                Log::warning('SpotManagerService: entry rejected', [
                    'device_id' => $device->id,
                    'entry'     => $entry,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return compact('accepted', 'rejected', 'errors');
    }

    /**
     * Process a single log entry inside a pessimistic DB lock to prevent
     * race conditions when multiple boards report the same asset concurrently.
     */
    private function processEntry(Device $device, array $entry, int $secondsPerSpot): bool
    {
        return DB::transaction(function () use ($device, $entry, $secondsPerSpot): bool {
            /** @var MediaAsset|null $asset */
            $asset = MediaAsset::lockForUpdate()->find($entry['asset_id']);

            if (! $asset) {
                throw new \RuntimeException('Asset not found.');
            }

            // Skip constraint validation for fallback assets (unlimited filler).
            if (! $asset->isFallback()) {
                $status = $this->constraintValidator->validate($asset);

                if ($status !== ConstraintValidationService::VALID) {
                    // Log the rejection but don't throw — just mark as rejected.
                    return false;
                }

                $asset->deductSpot();
            }

            PlaybackLog::create([
                'asset_id'    => $asset->id,
                'loop_id'     => $asset->loop_id,
                'device_id'   => $device->id,
                // Charge the loop/board budget by airtime: a long clip spends several slots.
                'spot_spent'  => $asset->spotFootprint($secondsPerSpot),
                'was_override' => $entry['was_override'] ?? false,
                'played_at'   => $entry['played_at'],
            ]);

            return true;
        });
    }
}

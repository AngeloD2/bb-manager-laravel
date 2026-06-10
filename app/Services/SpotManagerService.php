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
     * Each entry carries a client-generated `client_event_id` so retries over a
     * flaky link are deduped and never double-charge a spot. The per-entry
     * `results` let the device confirm exactly which queued events the server
     * has durably accounted for. `accepted`/`rejected` totals are retained for
     * backward compatibility (a duplicate counts as accepted — it is already on
     * the books).
     *
     * @param  Device  $device
     * @param  array<int, array{asset_id: string, played_at: string, was_override?: bool, client_event_id?: string}>  $entries
     * @return array{accepted: int, rejected: int, errors: array<string>, results: array<int, array{client_event_id: ?string, status: string, reason?: string}>}
     */
    public function processBatch(Device $device, array $entries): array
    {
        $accepted = 0;
        $rejected = 0;
        $errors   = [];
        $results  = [];

        // Slot length is global; read once and charge each play by its footprint.
        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);

        foreach ($entries as $entry) {
            $eventId = $entry['client_event_id'] ?? null;

            try {
                $status = $this->processEntry($device, $entry, $secondsPerSpot);
                $status === 'rejected' ? $rejected++ : $accepted++;
                $results[] = ['client_event_id' => $eventId, 'status' => $status];
            } catch (\Throwable $e) {
                $rejected++;
                $errors[]  = "asset_id={$entry['asset_id']}: {$e->getMessage()}";
                $results[] = ['client_event_id' => $eventId, 'status' => 'rejected', 'reason' => $e->getMessage()];
                Log::warning('SpotManagerService: entry rejected', [
                    'device_id' => $device->id,
                    'entry'     => $entry,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return compact('accepted', 'rejected', 'errors', 'results');
    }

    /**
     * Process a single log entry inside a pessimistic DB lock to prevent
     * race conditions when multiple boards report the same asset concurrently.
     *
     * @return string  'new' (counted now), 'duplicate' (already on the books),
     *                 or 'rejected' (failed a constraint).
     */
    private function processEntry(Device $device, array $entry, int $secondsPerSpot): string
    {
        $eventId = $entry['client_event_id'] ?? null;

        // Idempotency gate: a known key was already charged on a prior flush.
        if ($eventId !== null && PlaybackLog::where('device_id', $device->id)
                ->where('client_event_id', $eventId)->exists()) {
            return 'duplicate';
        }

        try {
            return $this->insertEntry($device, $entry, $eventId, $secondsPerSpot);
        } catch (DuplicateEventException) {
            // A concurrent flush of the same key won the race; the deduction was
            // rolled back with the transaction, so report it as already counted.
            return 'duplicate';
        }
    }

    /**
     * Validate, deduct, and persist a single entry within a pessimistic lock.
     *
     * @return string  'new' or 'rejected'.
     */
    private function insertEntry(Device $device, array $entry, ?string $eventId, int $secondsPerSpot): string
    {
        return DB::transaction(function () use ($device, $entry, $eventId, $secondsPerSpot): string {
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
                    return 'rejected';
                }

                $asset->deductSpot();
            }

            try {
                PlaybackLog::create([
                    'asset_id'        => $asset->id,
                    'loop_id'         => $asset->loop_id,
                    'device_id'       => $device->id,
                    'client_event_id' => $eventId,
                    // Charge the loop/board budget by airtime: a long clip spends several slots.
                    'spot_spent'      => $asset->spotFootprint($secondsPerSpot),
                    'was_override'    => $entry['was_override'] ?? false,
                    'played_at'       => $entry['played_at'],
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // A concurrent flush of the same key won the race — it is already
                // counted, so roll back our deduction by treating this as a dup.
                throw new DuplicateEventException();
            }

            return 'new';
        });
    }
}

/**
 * Thrown when a concurrent flush inserts the same client_event_id first, so the
 * current transaction must roll back its spot deduction and report a duplicate.
 */
class DuplicateEventException extends \RuntimeException
{
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\FallbackSpotRecord;
use App\Models\MediaLoop;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class FallbackSpotController extends Controller
{
    /**
     * GET /api/v1/admin/billboards/{billboard}/fallback-spots
     * Get or generate fallback spots for a billboard within a given date range.
     */
    public function index(Request $request, Billboard $billboard): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date'   => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));
        $tz = $billboard->timezone ?? 'UTC';

        // Ensure we don't generate too many days at once
        if ($startDate->diffInDays($endDate) > 31) {
            return response()->json(['message' => 'Date range cannot exceed 31 days.'], 400);
        }

        // Get fallback loops for this billboard
        // If a loop has no assigned_billboards, it's global. If it does, check if billboard id is in array.
        $fallbackLoops = MediaLoop::where('is_fallback', true)->get()->filter(function ($loop) use ($billboard) {
            return empty($loop->assigned_billboards) || in_array($billboard->id, $loop->assigned_billboards);
        });

        if ($fallbackLoops->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // Generate on demand
        $this->generateSpotsForBillboard($billboard, $fallbackLoops, $startDate, $endDate, $tz);

        // Fetch records
        $records = FallbackSpotRecord::where('billboard_id', $billboard->id)
            ->where('spot_date', '>=', $startDate->format('Y-m-d'))
            ->where('spot_date', '<=', $endDate->format('Y-m-d'))
            ->orderBy('spot_date')
            ->get();

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'          => $r->id,
                'billboardId' => $r->billboard_id,
                'loopId'      => $r->loop_id,
                'spotDate'    => $r->spot_date,
                'status'      => $r->status,
                'campaignId'  => $r->campaign_id,
            ])
        ]);
    }

    private function generateSpotsForBillboard(Billboard $billboard, $fallbackLoops, Carbon $startDate, Carbon $endDate, string $tz): void
    {
        $secondsPerSpot = (int) (Setting::where('key', 'seconds_per_spot')->value('value') ?? 15);
        $activeStart = $billboard->active_hours_start ?? '00:00';
        $activeEnd = $billboard->active_hours_end ?? '23:59';
        
        $start = Carbon::parse("2000-01-01 $activeStart:00");
        $end = Carbon::parse("2000-01-01 $activeEnd:00");
        if ($end->lessThan($start)) {
            $end->addDay();
        }
        $durationSecs = $end->diffInSeconds($start);
        $totalSpotsPerDay = (int) floor($durationSecs / $secondsPerSpot);

        // Find how many spots the primary loops take up, max. 
        // For fallback-as-inventory, we probably just create 1 record per day per fallback loop, 
        // OR the record itself just tracks "the day's inventory for this fallback loop". 
        // The spec implies one FallbackSpotRecord per (billboard, loop, date).
        // It says: " unsold house spots as campaign inventory... 1 record per loop per day?" 
        // If a campaign buys it, it buys "the fallback loop for the day" or individual spots?
        // Let's assume the record represents the entire day's availability for that fallback loop on that billboard,
        // or we just need the row to track status='sold', campaignId.
        
        $current = $startDate->copy();
        while ($current->lessThanOrEqualTo($endDate)) {
            $dateStr = $current->format('Y-m-d');
            
            foreach ($fallbackLoops as $loop) {
                // Use insertOrIgnore or firstOrCreate
                FallbackSpotRecord::firstOrCreate(
                    [
                        'billboard_id' => $billboard->id,
                        'loop_id'   => $loop->id,
                        'spot_date' => $dateStr,
                    ],
                    [
                        'status' => 'available',
                        'campaign_id' => null,
                    ]
                );
            }
            $current->addDay();
        }
    }
}

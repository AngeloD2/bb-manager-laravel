<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CampaignResource;
use App\Models\Campaign;
use App\Services\BillboardNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CampaignController
 *
 * Admin CRUD for campaigns:
 *  GET    /api/v1/admin/campaigns
 *  POST   /api/v1/admin/campaigns
 *  PUT    /api/v1/admin/campaigns/{campaign}
 *  DELETE /api/v1/admin/campaigns/{campaign}
 *
 * Loops are filed into a campaign through LoopController's `campaign_id`.
 */
class CampaignController extends Controller
{
    public function __construct(private BillboardNotifier $notifier) {}

    public function index(): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            Campaign::withCount('loops')->latest('starts_on')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:200'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);

        $campaign = Campaign::create($data);

        // A campaign's window narrows what its loops' assets may air, so a new
        // one can change eligibility immediately.
        $this->notifier->notifyScheduleChanged();

        return response()->json(new CampaignResource($campaign), 201);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:200'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on'   => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);

        $campaign->update($data);
        $this->notifier->notifyScheduleChanged();

        return response()->json(new CampaignResource($campaign->fresh()));
    }

    /**
     * Soft delete. Loops are detached rather than removed: unsold inventory is
     * still inventory, and a null campaign_id is exactly how a loop says so.
     */
    public function destroy(Campaign $campaign): JsonResponse
    {
        $campaign->loops()->update(['campaign_id' => null]);
        $campaign->delete();

        $this->notifier->notifyScheduleChanged();

        return response()->json(['message' => 'Campaign deleted.']);
    }
}

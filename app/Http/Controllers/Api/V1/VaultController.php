<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SecureShareLinkResource;
use App\Models\MediaAsset;
use App\Models\SecureShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * VaultController
 *
 * Manages the Secure Client Sharing module:
 *
 *  GET    /api/v1/admin/vault/links          - list all share links (admin)
 *  POST   /api/v1/admin/vault/links          - create ephemeral link + return PIN once
 *  DELETE /api/v1/admin/vault/links/{link}   - revoke a link
 *  POST   /api/v1/vault/verify               - submit PIN to get delivery URL (public)
 */
class VaultController extends Controller
{
    // ── Admin: list ───────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $links = SecureShareLink::with(['loop', 'asset'])
            ->latest()
            ->get();

        return response()->json(SecureShareLinkResource::collection($links));
    }

    // ── Admin: create link ────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label'       => ['required', 'string', 'max:200'],
            'loop_id'   => ['nullable', 'uuid', 'exists:media_loops,id'],
            'asset_id'    => ['nullable', 'uuid', 'exists:media_assets,id'],
            'is_one_time' => ['boolean'],
            'ttl_hours'   => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $pin   = SecureShareLink::generatePin();
        $token = SecureShareLink::generateToken();
        $ttl   = $data['ttl_hours'] ?? (int) config('app.share_link_ttl_hours', 2);

        $link = SecureShareLink::create([
            'label'         => $data['label'],
            'loop_id'     => $data['loop_id'] ?? null,
            'asset_id'      => $data['asset_id']  ?? null,
            'token'         => $token,
            'password_hash' => Hash::make($pin),
            'expires_at'    => now()->addHours($ttl),
            'is_one_time'   => $data['is_one_time'] ?? true,
            'is_expired'    => false,
            'used_count'    => 0,
        ]);

        // PIN is returned ONLY at creation — never stored in plaintext, never exposed again.
        return response()->json([
            'data' => array_merge(
                (new SecureShareLinkResource($link))->resolve(),
                ['pin' => $pin]   // one-time cleartext PIN disclosure
            ),
            'message' => 'Link created. Store the PIN — it will not be shown again.',
        ], 201);
    }

    // ── Admin: revoke link ────────────────────────────────────────────────────

    public function destroy(SecureShareLink $link): JsonResponse
    {
        $link->update(['is_expired' => true]);

        return response()->json(['message' => 'Link revoked.']);
    }

    // ── Public: verify PIN and return delivery URL ────────────────────────────

    /**
     * POST /api/v1/vault/verify
     *
     * Public endpoint — no Sanctum auth required.
     * Client (recipient) submits the share spot + PIN.
     * Returns a short-lived S3 delivery URL if valid.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'pin'   => ['required', 'string', 'size:6'],
        ]);

        $link = SecureShareLink::where('token', $request->token)->first();

        if (! $link) {
            return response()->json(['message' => 'Invalid link.'], 404);
        }

        if (! $link->isActive()) {
            return response()->json(['message' => 'This link has expired or been revoked.'], 410);
        }

        if (! $link->verifyPin($request->pin)) {
            return response()->json(['message' => 'Invalid PIN.'], 401);
        }

        // Record use (expires if OTP)
        $link->recordUse();

        // Build response payload
        $payload = [
            'label'      => $link->label,
            'expires_at' => $link->expires_at->toIso8601String(),
        ];

        if ($link->asset_id) {
            $asset = MediaAsset::find($link->asset_id);
            $payload['asset'] = $asset ? [
                'id'          => $asset->id,
                'name'        => $asset->name,
                'file_type'   => $asset->file_type,
                'duration_secs' => $asset->duration_secs,
                'delivery_url'=> $asset->deliveryUrl(1800),   // 30-min presigned URL
            ] : null;
        }

        if ($link->loop_id) {
            $assets = MediaAsset::where('loop_id', $link->loop_id)
                ->where('is_synced', true)
                ->get()
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'file_type'   => $a->file_type,
                    'delivery_url'=> $a->deliveryUrl(1800),
                ]);

            $payload['folder_assets'] = $assets;
        }

        return response()->json(['data' => $payload]);
    }
}

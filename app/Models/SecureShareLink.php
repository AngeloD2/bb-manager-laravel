<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SecureShareLink extends Model
{
    use HasUuids;

    protected $table = 'secure_share_links';

    protected $fillable = [
        'label',
        'folder_id',
        'asset_id',
        'token',
        'password_hash',
        'expires_at',
        'is_one_time',
        'is_expired',
        'used_count',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'expires_at'  => 'datetime',
        'is_one_time' => 'boolean',
        'is_expired'  => 'boolean',
        'used_count'  => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'asset_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public static function generateToken(): string
    {
        return strtolower(Str::random(8));
    }

    public static function generatePin(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function isActive(): bool
    {
        return ! $this->is_expired && $this->expires_at->isFuture();
    }

    public function verifyPin(string $pin): bool
    {
        return Hash::check($pin, $this->password_hash);
    }

    /** Record a successful use; expire if OTP. */
    public function recordUse(): void
    {
        $this->increment('used_count');

        if ($this->is_one_time) {
            $this->update(['is_expired' => true]);
        }
    }

    /** Full public URL for sharing. */
    public function shareUrl(): string
    {
        return config('app.share_link_base_url', config('app.url') . '/vault')
            . '/' . $this->token;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FallbackSpotRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'device_id',
        'loop_id',
        'spot_date',
        'status',
        'campaign_id',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(MediaLoop::class, 'loop_id');
    }
}

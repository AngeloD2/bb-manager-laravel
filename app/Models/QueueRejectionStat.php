<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QueueRejectionStat extends Model
{
    protected $fillable = ['billboard_id', 'asset_id', 'reason', 'date', 'count'];
    public $timestamps = false;
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlaOutageWindow extends Model
{
    protected $fillable = ['started_at', 'ended_at', 'business_minutes_lost', 'compensated_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'compensated_at' => 'datetime',
    ];
}

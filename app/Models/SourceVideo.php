<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceVideo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'shot_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }
}

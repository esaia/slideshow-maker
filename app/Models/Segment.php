<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Segment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'excluded' => 'boolean',
        'used_in_render' => 'boolean',
    ];

    public function sourceVideo(): BelongsTo
    {
        return $this->belongsTo(SourceVideo::class);
    }

    public function finalScore(): float
    {
        // AI score (0-10) carries most of the weight when present;
        // heuristic_score is 0-1, scaled to the same range.
        if ($this->ai_score !== null) {
            return 0.7 * (float) $this->ai_score + 0.3 * (float) $this->heuristic_score * 10;
        }

        return (float) $this->heuristic_score * 10;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Project extends Model
{
    protected $guarded = [];

    protected $casts = [
        'music_analysis' => 'array',
    ];

    public function sourceVideos(): HasMany
    {
        return $this->hasMany(SourceVideo::class)->orderBy('sort_order');
    }

    public function segments(): HasManyThrough
    {
        return $this->hasManyThrough(Segment::class, SourceVideo::class);
    }

    public function pipelineSteps(): HasMany
    {
        return $this->hasMany(PipelineStep::class);
    }

    public function storagePath(string $sub = ''): string
    {
        $base = storage_path("app/projects/{$this->id}");

        return $sub === '' ? $base : "{$base}/{$sub}";
    }

    public function outputPath(string $aspect): string
    {
        return $this->storagePath("output/{$aspect}.mp4");
    }

    public function step(string $name): PipelineStep
    {
        return $this->pipelineSteps()->firstOrCreate(['name' => $name]);
    }
}

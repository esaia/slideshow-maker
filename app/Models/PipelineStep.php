<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineStep extends Model
{
    protected $guarded = [];

    public const PREPARE = ['ingest', 'proxies', 'music'];

    public const NAMES = ['ingest', 'proxies', 'music', 'plan', 'render'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function start(): void
    {
        $this->update(['status' => 'running', 'progress' => 0, 'log' => null]);
    }

    public function tick(int $progress, ?string $log = null): void
    {
        $data = ['progress' => min(100, max(0, $progress))];
        if ($log !== null) {
            $data['log'] = $log;
        }
        $this->update($data);
    }

    public function finish(): void
    {
        $this->update(['status' => 'done', 'progress' => 100]);
    }

    public function fail(string $message): void
    {
        $this->update(['status' => 'failed', 'log' => $message]);
    }
}

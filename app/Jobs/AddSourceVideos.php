<?php

namespace App\Jobs;

use App\Jobs\Concerns\PreparesSourceVideos;
use App\Models\Project;
use App\Services\Ffmpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Extends an already-prepared project with newly added clips: probes and
 * proxies just the new videos (existing ones are skipped, see
 * PreparesSourceVideos::makeProxies), then drops the project back to "ready"
 * without touching the music analysis.
 */
class AddSourceVideos implements ShouldQueue
{
    use PreparesSourceVideos, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    public function handle(Ffmpeg $ffmpeg): void
    {
        $project = $this->project;
        $project->update(['status' => 'preparing', 'error' => null]);

        try {
            $this->ingest($project, $ffmpeg);
            $this->makeProxies($project, $ffmpeg);

            $project->update(['status' => 'ready']);
        } catch (Throwable $e) {
            $project->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $project->pipelineSteps()->where('status', 'running')
                ->get()->each->fail($e->getMessage());
            throw $e;
        }
    }
}

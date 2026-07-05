<?php

namespace App\Jobs;

use App\Jobs\Concerns\PreparesSourceVideos;
use App\Models\Project;
use App\Services\Ffmpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Extends an already-prepared project with newly added clips: probes the new
 * videos and re-sorts the timeline by capture time, then drops the project
 * back to "ready". Preview proxies are generated lazily per-video (see
 * ProjectController::proxy), so this is cheap even for large batches.
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

            $project->update(['status' => 'ready']);
        } catch (Throwable $e) {
            $project->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $project->pipelineSteps()->where('status', 'running')
                ->get()->each->fail($e->getMessage());
            throw $e;
        }
    }
}

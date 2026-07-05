<?php

namespace App\Jobs;

use App\Jobs\Concerns\PreparesSourceVideos;
use App\Models\Project;
use App\Services\AnalyzerClient;
use App\Services\Ffmpeg;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Prepare phase: read metadata (incl. capture time), build 480p proxies for
 * the trim player, analyze the music beats. Ends in the "ready" status where
 * the user marks the moments they want; rendering is a separate job.
 */
class RunPipeline implements ShouldQueue
{
    use PreparesSourceVideos, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    public function handle(Ffmpeg $ffmpeg, AnalyzerClient $analyzer): void
    {
        $project = $this->project;
        $project->update(['status' => 'preparing', 'error' => null]);

        try {
            $this->ingest($project, $ffmpeg);
            $this->makeProxies($project, $ffmpeg);
            $this->analyzeMusic($analyzer);

            $project->update(['status' => 'ready']);
        } catch (Throwable $e) {
            $project->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $project->pipelineSteps()->where('status', 'running')
                ->get()->each->fail($e->getMessage());
            throw $e;
        }
    }

    private function analyzeMusic(AnalyzerClient $analyzer): void
    {
        $step = $this->project->step('music');
        $step->start();

        $result = $analyzer->analyzeMusic($this->project->music_path);
        $this->project->update(['music_analysis' => $result]);

        $step->finish();
    }
}

<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\AnalyzerClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Re-analyzes the soundtrack after the user swaps the music file. */
class AnalyzeMusic implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Project $project) {}

    public function handle(AnalyzerClient $analyzer): void
    {
        $this->project->update(['status' => 'preparing', 'error' => null]);
        $step = $this->project->step('music');
        $step->start();

        try {
            $result = $analyzer->analyzeMusic($this->project->music_path);
            $this->project->update(['music_analysis' => $result, 'status' => 'ready']);
            $step->finish();
        } catch (Throwable $e) {
            $this->project->update(['status' => 'ready', 'error' => $e->getMessage()]);
            $step->fail($e->getMessage());
            throw $e;
        }
    }
}

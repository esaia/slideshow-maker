<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\RenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RenderProject implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public Project $project, public string $aspect) {}

    public function handle(RenderService $renderer): void
    {
        $this->project->update(['status' => 'rendering', 'error' => null, 'aspect' => $this->aspect]);

        try {
            $renderer->run($this->project, $this->aspect);
            $this->project->update(['status' => 'done']);
        } catch (Throwable $e) {
            // back to the trim studio, with the error visible
            $this->project->update(['status' => 'ready', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}

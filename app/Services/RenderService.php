<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Segment;
use Illuminate\Support\Facades\File;
use RuntimeException;

class RenderService
{
    public function __construct(
        private EditPlanner $planner,
        private Ffmpeg $ffmpeg,
    ) {}

    public function run(Project $project, string $aspect): void
    {
        $size = config("slideshow.outputs.{$aspect}");
        if (! $size) {
            throw new RuntimeException("Unknown aspect: {$aspect}");
        }

        $planStep = $project->step('plan');
        $planStep->start();

        $plan = $this->planner->plan($project);
        if ($plan === []) {
            $planStep->fail('No clips selected — mark some moments first.');
            throw new RuntimeException('Edit plan is empty.');
        }

        $project->segments()->update(['used_in_render' => false]);
        Segment::whereIn('id', array_column($plan, 'segment_id'))->update(['used_in_render' => true]);
        $planStep->finish();

        $renderStep = $project->step('render');
        $renderStep->start();

        // clear outputs from previous renders (possibly the other aspect)
        foreach (array_keys(config('slideshow.outputs')) as $old) {
            File::delete($project->outputPath($old));
        }

        $this->ffmpeg->renderMontage(
            clips: $plan,
            musicPath: $project->music_path,
            totalDuration: array_sum(array_column($plan, 'duration')),
            width: $size['width'],
            height: $size['height'],
            dest: $project->outputPath($aspect),
            workDir: $project->storagePath("work/{$aspect}"),
        );

        $renderStep->finish();
    }
}

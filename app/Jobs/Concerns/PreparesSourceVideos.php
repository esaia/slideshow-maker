<?php

namespace App\Jobs\Concerns;

use App\Models\Project;
use App\Services\Ffmpeg;

/**
 * Shared by RunPipeline (new project) and AddSourceVideos (adding clips to an
 * existing one): probe metadata and order the timeline by capture time.
 * Preview proxies are *not* built here — they're generated lazily per-video
 * on first playback (see ProjectController::proxy) so adding clips, even in
 * bulk, stays cheap.
 */
trait PreparesSourceVideos
{
    private function ingest(Project $project, Ffmpeg $ffmpeg): void
    {
        $step = $project->step('ingest');
        $step->start();

        $videos = $project->sourceVideos;
        foreach ($videos as $i => $video) {
            $meta = $ffmpeg->probe($video->path);
            $video->update($meta);
            $step->tick((int) (($i + 1) / $videos->count() * 100));
        }

        // order the whole project by capture time — the hike's timeline
        $project->sourceVideos()
            ->reorder() // drop the relation's default sort_order ordering
            ->orderByRaw('shot_at IS NULL, shot_at, id')
            ->get()
            ->each(fn ($video, $i) => $video->update(['sort_order' => $i]));

        $step->finish();
    }
}

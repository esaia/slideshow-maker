<?php

namespace App\Jobs\Concerns;

use App\Models\Project;
use App\Services\Ffmpeg;

/**
 * Shared by RunPipeline (new project) and AddSourceVideos (adding clips to an
 * existing one): probe metadata, order by capture time, build 480p proxies.
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

    private function makeProxies(Project $project, Ffmpeg $ffmpeg): void
    {
        $step = $project->step('proxies');
        $step->start();

        $videos = $project->sourceVideos()->get();
        foreach ($videos as $i => $video) {
            $proxy = $project->storagePath("proxies/{$video->id}.mp4");
            // resume support: keep proxies finished on a previous attempt
            if ($video->status !== 'proxied' || ! is_file($proxy)) {
                $ffmpeg->makeProxy($video->path, $proxy);
                $video->update(['proxy_path' => $proxy, 'status' => 'proxied']);
            }
            $step->tick((int) (($i + 1) / $videos->count() * 100), basename($video->path));
        }

        $step->finish();
    }
}

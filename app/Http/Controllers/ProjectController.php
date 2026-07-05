<?php

namespace App\Http\Controllers;

use App\Jobs\AddSourceVideos;
use App\Jobs\RenderProject;
use App\Jobs\RunPipeline;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Models\Segment;
use App\Models\SourceVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index()
    {
        return Inertia::render('projects/index', [
            'projects' => Project::latest()->withCount('sourceVideos')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('projects/create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'music_path' => ['required', 'string'],
            'video_paths' => ['required', 'array', 'min:1'],
            'video_paths.*' => ['required', 'string'],
        ]);

        $missing = collect([$data['music_path'], ...$data['video_paths']])
            ->reject(fn ($p) => is_file($p));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'video_paths' => 'File(s) not found: '.$missing->implode(', '),
            ]);
        }

        $project = Project::create([
            'name' => $data['name'],
            'music_path' => $data['music_path'],
        ]);

        foreach (array_values($data['video_paths']) as $i => $path) {
            $project->sourceVideos()->create(['path' => $path, 'sort_order' => $i]);
        }

        foreach (PipelineStep::PREPARE as $name) {
            $project->step($name);
        }

        RunPipeline::dispatch($project);

        return redirect()->route('projects.show', $project);
    }

    public function storeVideos(Request $request, Project $project)
    {
        abort_unless(in_array($project->status, ['ready', 'done'], true), 422);

        $data = $request->validate([
            'video_paths' => ['required', 'array', 'min:1'],
            'video_paths.*' => ['required', 'string'],
        ]);

        $missing = collect($data['video_paths'])->reject(fn ($p) => is_file($p));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'video_paths' => 'File(s) not found: '.$missing->implode(', '),
            ]);
        }

        $nextOrder = (int) $project->sourceVideos()->max('sort_order') + 1;
        foreach (array_values($data['video_paths']) as $i => $path) {
            $project->sourceVideos()->create(['path' => $path, 'sort_order' => $nextOrder + $i]);
        }

        $project->update(['status' => 'preparing', 'error' => null]);
        $project->step('ingest')->update(['status' => 'pending', 'progress' => 0, 'log' => null]);

        AddSourceVideos::dispatch($project);

        return back();
    }

    public function show(Project $project)
    {
        return Inertia::render('projects/show', [
            'project' => $this->payload($project),
        ]);
    }

    /** JSON polling endpoint for the progress screen. */
    public function status(Project $project)
    {
        return response()->json($this->payload($project));
    }

    /**
     * Stream the 480p proxy for the trim player (supports seeking via Range).
     * Built lazily on first request — only the video the user is actually
     * looking at gets transcoded, instead of the whole project upfront.
     */
    public function proxy(Project $project, SourceVideo $video, \App\Services\Ffmpeg $ffmpeg)
    {
        abort_unless($video->project_id === $project->id, 404);

        if (! $video->proxy_path || ! is_file($video->proxy_path)) {
            $proxy = $project->storagePath("proxies/{$video->id}.mp4");
            $ffmpeg->makeProxy($video->path, $proxy);
            $video->update(['proxy_path' => $proxy, 'status' => 'proxied']);
        }

        return response()->file($video->proxy_path, ['Content-Type' => 'video/mp4']);
    }

    /** Video thumbnail for the grid view — generated lazily and cached. */
    public function thumb(Project $project, SourceVideo $video, \App\Services\Ffmpeg $ffmpeg)
    {
        abort_unless($video->project_id === $project->id, 404);

        $thumb = $project->storagePath("thumbs/{$video->id}.jpg");
        if (! is_file($thumb)) {
            $src = ($video->proxy_path && is_file($video->proxy_path)) ? $video->proxy_path : $video->path;
            $at = min(1.0, max(0.1, (float) ($video->duration ?? 2) / 2));
            $ffmpeg->thumbnail($src, $thumb, $at);
        }

        return response()->file($thumb, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function storeSegment(Request $request, Project $project, SourceVideo $video)
    {
        abort_unless($video->project_id === $project->id, 404);

        $data = $request->validate([
            'start_s' => ['required', 'numeric', 'min:0'],
            'end_s' => ['required', 'numeric', 'gt:start_s'],
            'keep_audio' => ['sometimes', 'boolean'],
        ]);

        if ($data['end_s'] - $data['start_s'] < 0.8) {
            throw ValidationException::withMessages(['end_s' => 'Clip must be at least 0.8 seconds.']);
        }
        if ($video->duration && $data['end_s'] > $video->duration + 0.5) {
            throw ValidationException::withMessages(['end_s' => 'Clip ends past the video.']);
        }

        $video->segments()->create($data);

        return back();
    }

    public function updateSegment(Request $request, Project $project, Segment $segment)
    {
        abort_unless($segment->sourceVideo->project_id === $project->id, 404);

        $data = $request->validate([
            'start_s' => ['required', 'numeric', 'min:0'],
            'end_s' => ['required', 'numeric', 'gt:start_s'],
            'keep_audio' => ['sometimes', 'boolean'],
        ]);

        if ($data['end_s'] - $data['start_s'] < 0.8) {
            throw ValidationException::withMessages(['end_s' => 'Clip must be at least 0.8 seconds.']);
        }

        $segment->update($data);

        return back();
    }

    public function destroySegment(Project $project, Segment $segment)
    {
        abort_unless($segment->sourceVideo->project_id === $project->id, 404);
        $segment->delete();

        return back();
    }

    public function updateMusic(Request $request, Project $project)
    {
        $data = $request->validate([
            'music_path' => ['required', 'string'],
        ]);

        if (! is_file($data['music_path'])) {
            throw ValidationException::withMessages(['music_path' => 'File not found.']);
        }

        abort_unless(in_array($project->status, ['ready', 'done'], true), 422);

        $project->update([
            'music_path' => $data['music_path'],
            'music_analysis' => null,
            'status' => 'preparing', // immediate UI feedback while the job queues
        ]);
        $project->step('music')->update(['status' => 'pending', 'progress' => 0, 'log' => null]);
        \App\Jobs\AnalyzeMusic::dispatch($project);

        return back();
    }

    public function render(Request $request, Project $project)
    {
        $data = $request->validate([
            'aspect' => ['required', 'in:'.implode(',', array_keys(config('slideshow.outputs')))],
        ]);

        abort_unless(in_array($project->status, ['ready', 'done'], true), 422);

        // flip the status right away so the UI shows progress immediately —
        // the queued job may take a few seconds to start
        $project->update(['status' => 'rendering', 'error' => null, 'aspect' => $data['aspect']]);
        foreach (['plan', 'render'] as $name) {
            $project->step($name)->update(['status' => 'pending', 'progress' => 0, 'log' => null]);
        }

        RenderProject::dispatch($project, $data['aspect']);

        return back();
    }

    public function output(Project $project, string $aspect)
    {
        abort_unless(array_key_exists($aspect, config('slideshow.outputs')), 404);
        $path = $project->outputPath($aspect);
        abort_unless(is_file($path), 404);

        $name = str($project->name)->slug()."-{$aspect}.mp4";

        return request()->boolean('download')
            ? response()->download($path, $name)
            : response()->file($path, ['Content-Type' => 'video/mp4']);
    }

    public function destroy(Project $project)
    {
        File::deleteDirectory($project->storagePath());
        $project->delete();

        return redirect()->route('projects.index');
    }

    private function payload(Project $project): array
    {
        $project->load(['sourceVideos', 'pipelineSteps']);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'error' => $project->error,
            'aspect' => $project->aspect,
            'music_path' => $project->music_path,
            'music_duration' => (float) ($project->music_analysis['duration'] ?? 0),
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
            'source_videos' => $project->sourceVideos->map(fn ($v) => [
                'id' => $v->id,
                'name' => basename($v->path),
                'duration' => $v->duration !== null ? (float) $v->duration : null,
                'shot_at' => $v->shot_at?->toIso8601String(),
                'has_proxy' => (bool) ($v->proxy_path && is_file($v->proxy_path)),
            ]),
            'steps' => $project->pipelineSteps
                ->sortBy(fn ($s) => array_search($s->name, PipelineStep::NAMES))
                ->values()
                ->map(fn ($s) => [
                    'name' => $s->name,
                    'status' => $s->status,
                    'progress' => $s->progress,
                    'log' => $s->log,
                ]),
            'segments' => $project->segments()
                ->with('sourceVideo:id,path,sort_order')
                ->get()
                ->sortBy(fn (Segment $s) => [$s->sourceVideo->sort_order, $s->start_s])
                ->values()
                ->map(fn (Segment $s) => [
                    'id' => $s->id,
                    'video_id' => $s->source_video_id,
                    'video' => basename($s->sourceVideo->path),
                    'start_s' => (float) $s->start_s,
                    'end_s' => (float) $s->end_s,
                    'keep_audio' => (bool) $s->keep_audio,
                    'used_in_render' => $s->used_in_render,
                ]),
            'outputs' => collect(array_keys(config('slideshow.outputs')))
                ->filter(fn ($aspect) => is_file($project->outputPath($aspect)))
                ->values(),
        ];
    }
}

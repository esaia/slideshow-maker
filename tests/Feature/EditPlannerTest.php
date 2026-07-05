<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\EditPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditPlannerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{shot_at: string, clips: array<int, array{float, float}>}>  $videoSpecs
     */
    private function makeProject(array $musicAnalysis, array $videoSpecs): Project
    {
        $project = Project::create([
            'name' => 'test',
            'music_path' => '/tmp/music.mp3',
            'music_analysis' => $musicAnalysis,
        ]);

        foreach (array_values($videoSpecs) as $i => $spec) {
            $video = $project->sourceVideos()->create([
                'path' => "/tmp/video_{$i}.mp4",
                'sort_order' => $i,
                'shot_at' => $spec['shot_at'],
                'duration' => $spec['duration'] ?? 60.0,
            ]);
            foreach ($spec['clips'] as [$start, $end]) {
                $video->segments()->create(['start_s' => $start, 'end_s' => $end]);
            }
        }

        return $project;
    }

    private function music(float $duration, float $beatInterval = 0.5): array
    {
        return [
            'duration' => $duration,
            'tempo' => 60.0 / $beatInterval,
            'beats' => array_map(fn ($b) => round($b, 3), range(0.0, $duration, $beatInterval)),
            'energy' => array_fill(0, (int) ($duration * 2), 0.5),
            'energy_hz' => 2,
        ];
    }

    public function test_each_clip_is_used_exactly_once_no_duplication(): void
    {
        $project = $this->makeProject($this->music(60.0), [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[0.0, 3.0], [10.0, 14.0]]],
            ['shot_at' => '2026-07-01 10:00:00', 'clips' => [[2.0, 5.0]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);

        // 3 clips total, music has room for far more — but nothing repeats
        $this->assertCount(3, $plan);
        $ids = array_column($plan, 'segment_id');
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_montage_can_be_shorter_than_music(): void
    {
        $project = $this->makeProject($this->music(120.0), [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[0.0, 4.0], [8.0, 12.0]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);
        $total = array_sum(array_column($plan, 'duration'));

        $this->assertLessThanOrEqual(8.0, $total);
        $this->assertGreaterThan(0, $total);
    }

    public function test_clips_follow_capture_time_not_selection_order(): void
    {
        // second video was shot FIRST — its clip must come first
        $project = $this->makeProject($this->music(60.0), [
            ['shot_at' => '2026-07-01 15:00:00', 'clips' => [[0.0, 3.0]]],   // afternoon
            ['shot_at' => '2026-07-01 08:00:00', 'clips' => [[5.0, 8.0]]],   // morning
        ]);

        // sort_order normally set during ingest; simulate it here
        $project->sourceVideos()
            ->reorder()
            ->orderByRaw('shot_at IS NULL, shot_at, id')
            ->get()
            ->each(fn ($v, $i) => $v->update(['sort_order' => $i]));

        $plan = app(EditPlanner::class)->plan($project);

        $this->assertSame('/tmp/video_1.mp4', $plan[0]['path']); // morning first
        $this->assertSame('/tmp/video_0.mp4', $plan[1]['path']);
    }

    public function test_every_cut_lands_exactly_on_a_beat(): void
    {
        $project = $this->makeProject($this->music(60.0), [
            // awkward marked lengths (3.3s, 2.1s, 4.7s) that don't align to the 0.5s grid
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[1.0, 4.3], [10.0, 12.1], [20.0, 24.7]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);
        $this->assertCount(3, $plan);

        $cursor = 0.0;
        foreach ($plan as $clip) {
            $cursor += $clip['duration'];
            $onGrid = abs($cursor - round($cursor * 2) / 2) < 0.02;
            $this->assertTrue($onGrid, "cut at {$cursor}s is off the beat grid");
        }
    }

    public function test_marked_length_does_not_dictate_duration(): void
    {
        $music = $this->music(60.0);
        $music['strong_beats'] = array_map(fn ($b) => round($b, 3), range(0.0, 60.0, 2.0));

        $project = $this->makeProject($music, [
            // user marked a long 10s range — only the part until the next accent shows
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[1.0, 11.0]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);

        $this->assertEqualsWithDelta(2.0, $plan[0]['duration'], 0.01); // next accent, not 10s
        $this->assertEqualsWithDelta(1.0, $plan[0]['in'], 0.01); // starts at the user's mark
    }

    public function test_clip_near_video_end_shifts_start_instead_of_overrunning(): void
    {
        $music = $this->music(60.0);
        $music['strong_beats'] = array_map(fn ($b) => round($b, 3), range(0.0, 60.0, 4.0));

        $project = $this->makeProject($music, [
            // marked at 8.0 on a 10s video; accent wants 4.0s → must not run past 10
            ['shot_at' => '2026-07-01 09:00:00', 'duration' => 10.0, 'clips' => [[8.0, 9.8]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);

        $this->assertEqualsWithDelta(4.0, $plan[0]['duration'], 0.01);
        $this->assertLessThanOrEqual(10.0, $plan[0]['in'] + $plan[0]['duration']);
    }

    public function test_every_video_change_lands_on_a_strong_beat(): void
    {
        $music = $this->music(60.0); // weak grid every 0.5s
        $music['strong_beats'] = array_map(fn ($b) => round($b, 3), range(0.0, 60.0, 2.0)); // accents every 2s

        $project = $this->makeProject($music, [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[1.0, 4.3], [10.0, 13.0], [20.0, 26.0]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);
        $this->assertCount(3, $plan);

        // every cut boundary sits on the 2s accent grid
        $cursor = 0.0;
        foreach ($plan as $clip) {
            $cursor += $clip['duration'];
            $this->assertEqualsWithDelta(0.0, fmod($cursor, 2.0), 0.02, "cut at {$cursor}s missed the accent grid");
        }
    }

    public function test_sparse_accents_fall_back_to_regular_beat_pacing(): void
    {
        $music = $this->music(60.0);
        $music['strong_beats'] = [0.0, 20.0, 40.0]; // accents way too far apart

        $project = $this->makeProject($music, [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[1.0, 4.3]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);

        // paced near the 3s target on the regular grid, not a 20s clip
        $this->assertEqualsWithDelta(3.0, $plan[0]['duration'], 0.01);
    }

    public function test_clips_stop_when_music_is_full(): void
    {
        $project = $this->makeProject($this->music(5.0), [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => [[0.0, 4.0], [10.0, 14.0], [20.0, 24.0]]],
        ]);

        $plan = app(EditPlanner::class)->plan($project);
        $total = array_sum(array_column($plan, 'duration'));

        $this->assertLessThanOrEqual(5.0, $total);
    }

    public function test_empty_selection_yields_empty_plan(): void
    {
        $project = $this->makeProject($this->music(30.0), [
            ['shot_at' => '2026-07-01 09:00:00', 'clips' => []],
        ]);

        $this->assertSame([], app(EditPlanner::class)->plan($project));
    }
}

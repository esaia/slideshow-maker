<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Segment;

/**
 * Turns the user's hand-picked clips + music beats into an edit decision list.
 *
 * Music-first pacing: each clip plays only until the NEXT strong beat
 * (accent/downbeat) — the video changes on every clear hit. The user's marked
 * range is treated as "best material to pull from", not a duration: whatever
 * fits before the next accent is shown, the rest is skipped. Clips play in
 * shooting order, each used once; the montage ends when the clips run out.
 *
 * Exception: clips marked "keep_audio" (the user wants to actually hear them)
 * play their full marked range instead — cutting a clip mid-sentence for the
 * beat would defeat the point of keeping its audio.
 */
class EditPlanner
{
    private const MIN_CLIP_S = 0.8;

    /**
     * The music drives the pacing: a clip ends at the NEXT strong beat, no
     * matter how long the user's marked range is (extra material is simply
     * not shown). If accents are sparse, fall back to a regular beat near
     * TARGET so a clip never drags on for ages.
     */
    private const MAX_CLIP_S = 6.0;

    private const TARGET_CLIP_S = 3.0;

    /**
     * @return array<int, array{segment_id: int, path: string, in: float, duration: float, start: float, keep_audio: bool}>
     */
    public function plan(Project $project): array
    {
        $music = $project->music_analysis ?? [];
        $musicDuration = (float) ($music['duration'] ?? 0);
        $beats = $this->beatGrid($music);
        $strongBeats = array_map('floatval', $music['strong_beats'] ?? []);

        $segments = $project->segments()
            ->where('excluded', false)
            ->with('sourceVideo')
            ->get()
            ->sortBy(fn (Segment $s) => [$s->sourceVideo->sort_order, $s->start_s])
            ->values();

        if ($segments->isEmpty() || $musicDuration <= 0) {
            return [];
        }

        $plan = [];
        $cursor = 0.0;

        foreach ($segments as $segment) {
            if ($cursor >= $musicDuration - self::MIN_CLIP_S) {
                break; // music is full — remaining clips don't fit
            }

            $duration = $segment->keep_audio
                ? min((float) $segment->end_s - (float) $segment->start_s, $musicDuration - $cursor)
                : min($this->nextCutLength($beats, $strongBeats, $cursor), $musicDuration - $cursor);

            // fit the beat-aligned duration inside the source material,
            // anchored at the user's start mark and shifted back if needed
            $in = (float) $segment->start_s;
            $videoDuration = (float) ($segment->sourceVideo->duration ?? 0);
            if ($videoDuration > 0) {
                if ($in + $duration > $videoDuration) {
                    $in = max(0.0, $videoDuration - $duration);
                }
                $duration = min($duration, $videoDuration - $in);
            }

            if ($duration < self::MIN_CLIP_S) {
                continue;
            }

            $plan[] = [
                'segment_id' => $segment->id,
                'path' => $segment->sourceVideo->path,
                'in' => round($in, 3),
                'duration' => round($duration, 3),
                'start' => round($cursor, 3),
                'keep_audio' => (bool) $segment->keep_audio,
            ];
            $cursor += $duration;
        }

        return $plan;
    }

    /** Beat timestamps; falls back to a 2s grid for beat-less audio. */
    private function beatGrid(array $music): array
    {
        $beats = $music['beats'] ?? [];
        if (count($beats) >= 4) {
            return array_map('floatval', $beats);
        }

        $duration = (float) ($music['duration'] ?? 0);

        return $duration > 0 ? range(0.0, $duration, 2.0) : [];
    }

    /**
     * The clip runs until the NEXT strong beat (accent/downbeat). When the
     * next accent is too far away, cut on the regular beat nearest to the
     * target pace instead.
     */
    private function nextCutLength(array $beats, array $strongBeats, float $cursor): float
    {
        foreach ($strongBeats as $beat) {
            $len = $beat - $cursor;
            if ($len >= self::MIN_CLIP_S) {
                if ($len <= self::MAX_CLIP_S) {
                    return $len;
                }
                break; // next accent is too far — pace with regular beats
            }
        }

        return $this->closestLength($beats, $cursor, self::TARGET_CLIP_S) ?? self::TARGET_CLIP_S;
    }

    /** Distance from the cursor to the beat closest to (cursor + desired). */
    private function closestLength(array $beats, float $cursor, float $desired): ?float
    {
        $best = null;
        $bestDiff = INF;

        foreach ($beats as $beat) {
            $len = $beat - $cursor;
            if ($len < self::MIN_CLIP_S) {
                continue;
            }
            $diff = abs($len - $desired);
            if ($diff < $bestDiff) {
                $best = $len;
                $bestDiff = $diff;
            }
            if ($len > $desired && $diff > $bestDiff) {
                break; // beats are sorted — we're only getting further away
            }
        }

        return $best;
    }
}

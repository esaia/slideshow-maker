<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class Ffmpeg
{
    private function bin(string $which): string
    {
        return config("slideshow.{$which}");
    }

    /** Feed the hardware decoder when available — the main win for large/4K sources. */
    private function hwaccelInputArgs(): array
    {
        $hw = config('slideshow.hwaccel');

        return $hw ? ['-hwaccel', $hw] : [];
    }

    /**
     * libx264 is quality-driven (CRF); the videotoolbox hardware encoder has
     * no equivalent, so it's driven by an explicit bitrate cap instead.
     */
    private function videoEncoderArgs(string $profile): array
    {
        if (config('slideshow.hwaccel') === 'videotoolbox') {
            return match ($profile) {
                'proxy' => ['-c:v', 'h264_videotoolbox', '-b:v', '900k', '-maxrate', '1300k', '-bufsize', '2000k'],
                'clip' => ['-c:v', 'h264_videotoolbox', '-b:v', '8M', '-maxrate', '10M', '-bufsize', '16M'],
                'final' => ['-c:v', 'h264_videotoolbox', '-b:v', '10M', '-maxrate', '14M', '-bufsize', '20M'],
            };
        }

        return match ($profile) {
            'proxy' => ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28'],
            'clip' => ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20'],
            'final' => ['-c:v', 'libx264', '-preset', 'medium', '-crf', '19'],
        };
    }

    private function run(array $cmd, int $timeout = 3600): string
    {
        $process = new Process($cmd, timeout: $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'ffmpeg failed: '.implode(' ', $cmd)."\n".substr($process->getErrorOutput(), -2000)
            );
        }

        return $process->getOutput();
    }

    /** @return array{duration: float, width: int, height: int, fps: float, shot_at: ?string} */
    public function probe(string $path): array
    {
        $json = $this->run([
            $this->bin('ffprobe'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,r_frame_rate:format=duration:format_tags=creation_time',
            '-of', 'json', $path,
        ], 60);

        $data = json_decode($json, true);
        $stream = $data['streams'][0] ?? [];
        [$num, $den] = array_pad(explode('/', $stream['r_frame_rate'] ?? '30/1'), 2, 1);

        // capture time: metadata tag first, file modification time as fallback
        $shotAt = $data['format']['tags']['creation_time'] ?? null;
        if (! $shotAt && ($mtime = @filemtime($path))) {
            $shotAt = date('c', $mtime);
        }

        return [
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'fps' => (float) $den > 0 ? round($num / $den, 3) : 30.0,
            'shot_at' => $shotAt,
        ];
    }

    /** Grab a single frame as a small JPEG (used for the video grid). */
    public function thumbnail(string $src, string $dest, float $at = 1.0): void
    {
        File::ensureDirectoryExists(dirname($dest));

        $this->run([
            $this->bin('ffmpeg'), '-y', '-v', 'error',
            '-ss', (string) $at, '-i', $src,
            '-frames:v', '1', '-vf', 'scale=320:-2',
            '-q:v', '5',
            $dest,
        ], 120);
    }

    public function makeProxy(string $src, string $dest): void
    {
        File::ensureDirectoryExists(dirname($dest));
        $h = config('slideshow.proxy_height');

        $this->run([
            $this->bin('ffmpeg'), '-y', '-v', 'error',
            ...$this->hwaccelInputArgs(),
            '-i', $src,
            '-vf', "scale=-2:{$h}",
            ...$this->videoEncoderArgs('proxy'),
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            $dest,
        ]);
    }

    /**
     * Render a montage: cut each clip from its source, conform to the target
     * frame, concat, lay the music under with fades. Clips marked
     * "keep_audio" bring their own sound along — the music automatically
     * ducks down under those and returns to full volume elsewhere.
     *
     * @param  array<int, array{path: string, in: float, duration: float, start: float, keep_audio: bool}>  $clips
     */
    public function renderMontage(array $clips, string $musicPath, float $totalDuration, int $width, int $height, string $dest, string $workDir): void
    {
        File::ensureDirectoryExists($workDir);
        File::ensureDirectoryExists(dirname($dest));

        // 1. Cut + conform each clip's video (fast input seeking + hw decode keeps big sources cheap)
        $videoParts = [];
        foreach ($clips as $i => $clip) {
            $part = "{$workDir}/part_{$i}.mp4";
            $this->run([
                $this->bin('ffmpeg'), '-y', '-v', 'error',
                ...$this->hwaccelInputArgs(),
                '-ss', (string) $clip['in'], '-t', (string) $clip['duration'],
                '-i', $clip['path'],
                '-vf', "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},fps=30,format=yuv420p",
                '-an', ...$this->videoEncoderArgs('clip'),
                $part,
            ]);
            $videoParts[] = $part;
        }

        // 1b. Build a matching audio track: each clip's own audio where
        // "keep_audio" is set, silence everywhere else — same order and
        // durations as the video parts, so it lines up when concatenated.
        $audioParts = [];
        foreach ($clips as $i => $clip) {
            $part = "{$workDir}/audio_{$i}.m4a";
            if ($clip['keep_audio']) {
                $this->run([
                    $this->bin('ffmpeg'), '-y', '-v', 'error',
                    '-ss', (string) $clip['in'], '-t', (string) $clip['duration'],
                    '-i', $clip['path'],
                    '-vn', '-ac', '2', '-ar', '48000', '-c:a', 'aac',
                    $part,
                ]);
            } else {
                $this->run([
                    $this->bin('ffmpeg'), '-y', '-v', 'error',
                    '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo',
                    '-t', (string) $clip['duration'], '-c:a', 'aac',
                    $part,
                ]);
            }
            $audioParts[] = $part;
        }

        // 2. Concat lists
        $videoList = "{$workDir}/concat_video.txt";
        File::put($videoList, implode("\n", array_map(fn ($p) => "file '{$p}'", $videoParts)));
        $audioList = "{$workDir}/concat_audio.txt";
        File::put($audioList, implode("\n", array_map(fn ($p) => "file '{$p}'", $audioParts)));

        // 3. Concat + duck the music under the "keep_audio" windows + fades, in one pass
        $fade = 1.0;
        $fadeOutStart = max(0, $totalDuration - $fade);
        $duckWindows = array_map(
            fn ($c) => [$c['start'], $c['start'] + $c['duration']],
            array_filter($clips, fn ($c) => $c['keep_audio']),
        );
        $duckExpr = $this->duckVolumeExpr($duckWindows);

        $this->run([
            $this->bin('ffmpeg'), '-y', '-v', 'error',
            '-f', 'concat', '-safe', '0', '-i', $videoList,
            '-i', $musicPath,
            '-f', 'concat', '-safe', '0', '-i', $audioList,
            '-filter_complex',
            "[0:v]fade=t=in:d={$fade},fade=t=out:st={$fadeOutStart}:d={$fade}[v];".
            "[1:a]atrim=0:{$totalDuration},volume='{$duckExpr}':eval=frame[music];".
            "[music][2:a]amix=inputs=2:duration=first:normalize=0,".
            "afade=t=in:d={$fade},afade=t=out:st={$fadeOutStart}:d={$fade}[a]",
            '-map', '[v]', '-map', '[a]',
            '-t', (string) $totalDuration,
            ...$this->videoEncoderArgs('final'),
            '-c:a', 'aac', '-b:a', '192k',
            '-movflags', '+faststart',
            $dest,
        ]);

        File::deleteDirectory($workDir);
    }

    /**
     * A ffmpeg `volume` expression that dips to 25% (with a short ramp)
     * during each [start, end] window and stays at 100% elsewhere.
     *
     * @param  array<int, array{0: float, 1: float}>  $windows
     */
    private function duckVolumeExpr(array $windows, float $duckTo = 0.25, float $rampS = 0.3): string
    {
        $expr = '1';
        foreach (array_reverse($windows) as [$start, $end]) {
            $rampUpEnd = $start + $rampS;
            $rampDownStart = max($rampUpEnd, $end - $rampS);
            $expr =
                "if(between(t,{$start},{$rampUpEnd}), 1-(1-{$duckTo})*(t-{$start})/{$rampS}, ".
                "if(between(t,{$rampUpEnd},{$rampDownStart}), {$duckTo}, ".
                "if(between(t,{$rampDownStart},{$end}), {$duckTo}+(1-{$duckTo})*(t-{$rampDownStart})/{$rampS}, ".
                "{$expr})))";
        }

        return $expr;
    }
}

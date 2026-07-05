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
            $this->bin('ffmpeg'), '-y', '-v', 'error', '-i', $src,
            '-vf', "scale=-2:{$h}", '-an',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
            $dest,
        ]);
    }

    /**
     * Render a montage: cut each clip from its source, conform to the target
     * frame, concat, lay the music under with fades.
     *
     * @param  array<int, array{path: string, in: float, duration: float}>  $clips
     */
    public function renderMontage(array $clips, string $musicPath, float $totalDuration, int $width, int $height, string $dest, string $workDir): void
    {
        File::ensureDirectoryExists($workDir);
        File::ensureDirectoryExists(dirname($dest));

        // 1. Cut + conform each clip (fast input seeking keeps 4K sources cheap)
        $parts = [];
        foreach ($clips as $i => $clip) {
            $part = "{$workDir}/part_{$i}.mp4";
            $this->run([
                $this->bin('ffmpeg'), '-y', '-v', 'error',
                '-ss', (string) $clip['in'], '-t', (string) $clip['duration'],
                '-i', $clip['path'],
                '-vf', "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},fps=30,format=yuv420p",
                '-an', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20',
                $part,
            ]);
            $parts[] = $part;
        }

        // 2. Concat list
        $list = "{$workDir}/concat.txt";
        File::put($list, implode("\n", array_map(fn ($p) => "file '{$p}'", $parts)));

        // 3. Concat + music + fades in one pass
        $fade = 1.0;
        $fadeOutStart = max(0, $totalDuration - $fade);
        $this->run([
            $this->bin('ffmpeg'), '-y', '-v', 'error',
            '-f', 'concat', '-safe', '0', '-i', $list,
            '-i', $musicPath,
            '-filter_complex',
            "[0:v]fade=t=in:d={$fade},fade=t=out:st={$fadeOutStart}:d={$fade}[v];".
            "[1:a]atrim=0:{$totalDuration},afade=t=in:d={$fade},afade=t=out:st={$fadeOutStart}:d={$fade}[a]",
            '-map', '[v]', '-map', '[a]',
            '-t', (string) $totalDuration,
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '19',
            '-c:a', 'aac', '-b:a', '192k',
            '-movflags', '+faststart',
            $dest,
        ]);

        File::deleteDirectory($workDir);
    }
}

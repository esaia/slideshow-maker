<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the Python analyzer CLIs (analyzer/ package) via `uv run` and returns
 * their JSON output as arrays.
 */
class AnalyzerClient
{
    private function run(array $args, int $timeout = 3600): array
    {
        // Only inject the key when configured — an empty value would override
        // the Anthropic SDK's own credential resolution in the child process.
        $env = [];
        if (config('slideshow.anthropic_api_key')) {
            $env['ANTHROPIC_API_KEY'] = config('slideshow.anthropic_api_key');
        }

        $process = new Process(
            command: ['uv', 'run', 'analyzer', ...$args],
            cwd: config('slideshow.analyzer_dir'),
            env: $env,
            timeout: $timeout,
        );
        $process->run();

        $output = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($output)) {
            $detail = $output['error'] ?? substr($process->getErrorOutput(), -2000);
            throw new RuntimeException('analyzer '.$args[0].' failed: '.$detail);
        }

        return $output;
    }

    public function analyzeVideo(string $path): array
    {
        return $this->run(['analyze-video', $path]);
    }

    public function analyzeMusic(string $path): array
    {
        return $this->run(['analyze-music', $path]);
    }

    public function rankFrames(array $spec): array
    {
        $specPath = tempnam(sys_get_temp_dir(), 'rank_').'.json';
        file_put_contents($specPath, json_encode($spec));

        try {
            return $this->run(['rank-frames', $specPath]);
        } finally {
            @unlink($specPath);
        }
    }
}

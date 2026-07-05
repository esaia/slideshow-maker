<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Lets the UI browse the local filesystem to pick source files.
 * The app runs on the user's own machine; browsing is still restricted
 * to the home directory and mounted volumes.
 */
class FileBrowserController extends Controller
{
    private const EXTENSIONS = [
        'video' => ['mp4', 'mov', 'm4v', 'avi', 'mkv', 'mts', 'm2ts', 'webm'],
        'audio' => ['mp3', 'm4a', 'wav', 'aac', 'flac', 'ogg'],
    ];

    public function browse(Request $request)
    {
        $type = $request->query('type') === 'audio' ? 'audio' : 'video';
        $roots = $this->roots();
        $path = $request->query('path') ?: $roots[0]['path'];

        $real = realpath($path);
        if ($real === false || ! is_dir($real) || ! $this->allowed($real, $roots)) {
            $real = $roots[0]['path'];
        }

        $entries = [];
        foreach (scandir($real) ?: [] as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }
            $full = "{$real}/{$name}";
            if (is_dir($full)) {
                $entries[] = ['name' => $name, 'path' => $full, 'type' => 'dir'];
            } elseif (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS[$type], true)) {
                $entries[] = [
                    'name' => $name,
                    'path' => $full,
                    'type' => 'file',
                    'size' => filesize($full) ?: 0,
                ];
            }
        }

        usort($entries, fn ($a, $b) => [$a['type'] !== 'dir', strcasecmp($a['name'], $b['name'])]
            <=> [$b['type'] !== 'dir', strcasecmp($b['name'], $a['name'])]);

        return response()->json([
            'path' => $real,
            'parent' => $this->allowed(dirname($real), $roots) ? dirname($real) : null,
            'roots' => $roots,
            'entries' => $entries,
        ]);
    }

    /** @return array<int, array{name: string, path: string}> */
    private function roots(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (! $home && function_exists('posix_getpwuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }
        $roots = [['name' => 'Home', 'path' => rtrim($home ?: '/Users', '/')]];

        // external drives — hiking footage often lives on an SSD
        foreach (glob('/Volumes/*') ?: [] as $volume) {
            if (is_dir($volume) && is_readable($volume)) {
                $roots[] = ['name' => basename($volume), 'path' => $volume];
            }
        }

        return $roots;
    }

    private function allowed(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root['path'] || str_starts_with($path, $root['path'].'/')) {
                return true;
            }
        }

        return false;
    }
}

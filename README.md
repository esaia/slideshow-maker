# Trail Cuts — automatic hiking slideshow maker

A local web app that turns raw hiking footage + a music track into finished montages.
Input → output, no timeline editing:

1. You give it video file paths and one music file.
2. It detects shots, scores them (motion / sharpness / exposure), and asks the
   Claude API to rank the best-looking moments.
3. It detects the beats in your music and plans an edit where every cut lands on a beat.
4. It renders two finished videos: **16:9 (1920×1080)** and **9:16 vertical (1080×1920)**.
5. On the results page you can exclude clips you don't like and re-render.

Files are read in place from your disk — nothing is uploaded or copied.

## Requirements

- PHP 8.3+, Composer, Node 20+
- ffmpeg / ffprobe (`brew install ffmpeg`)
- Python 3.11+ and [uv](https://docs.astral.sh/uv/)
- An Anthropic API key for the AI ranking step (optional — without it the app
  still works using heuristic scores only)

## Setup

```sh
composer install
npm install && npm run build
cd analyzer && uv sync && cd ..
cp .env.example .env && php artisan key:generate   # first time only
php artisan migrate
```

Put your API key in `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
```

## Running

Two processes (or use `composer dev` which runs everything including Vite):

```sh
php artisan serve          # web UI on http://127.0.0.1:8000
php artisan queue:work --timeout=7200   # runs the analysis/render pipeline
```

Open http://127.0.0.1:8000, click **New slideshow**, pick your videos and a music
track with the built-in file browser (Home + external drives), and watch it build.

## How it works

```
videos ──► ffprobe ──► 480p proxies ──► scene detection + heuristic scoring
                                              │
music ──► librosa beats/energy                ▼
   │                              Claude vision ranks frames (0–10 + tag)
   ▼                                          │
beat grid ◄───────── EditPlanner ◄────────────┘
                        │  best clips, chronological, cuts on beats
                        ▼
              ffmpeg render → landscape.mp4 + vertical.mp4
```

- **Laravel 12** + SQLite + database queue — pipeline in `app/Jobs/RunPipeline.php`
- **Edit brain** — `app/Services/EditPlanner.php` (unit-tested: `php artisan test`)
- **Python analyzer** — `analyzer/` (OpenCV, PySceneDetect, librosa, anthropic SDK);
  run standalone: `cd analyzer && uv run analyzer analyze-video some.mp4`
- Outputs land in `storage/app/projects/{id}/output/`

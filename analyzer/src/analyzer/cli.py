"""CLI entrypoints. Each command prints a single JSON document to stdout.

    analyzer analyze-video <proxy.mp4>
    analyzer analyze-music <track.mp3>
    analyzer rank-frames <spec.json>     # see frames.rank_segments for the spec shape
"""

from __future__ import annotations

import argparse
import json
import sys


def main() -> None:
    parser = argparse.ArgumentParser(prog="analyzer")
    sub = parser.add_subparsers(dest="command", required=True)

    p_video = sub.add_parser("analyze-video")
    p_video.add_argument("path")

    p_music = sub.add_parser("analyze-music")
    p_music.add_argument("path")

    p_rank = sub.add_parser("rank-frames")
    p_rank.add_argument("spec", help="Path to JSON spec file")

    args = parser.parse_args()

    try:
        if args.command == "analyze-video":
            from .video import analyze_video

            result = analyze_video(args.path)
        elif args.command == "analyze-music":
            from .music import analyze_music

            result = analyze_music(args.path)
        else:
            from .frames import rank_segments

            with open(args.spec) as f:
                spec = json.load(f)
            result = rank_segments(spec)
    except Exception as e:  # surface a machine-readable error to the PHP caller
        json.dump({"error": f"{type(e).__name__}: {e}"}, sys.stdout)
        sys.exit(1)

    json.dump(result, sys.stdout)


if __name__ == "__main__":
    main()

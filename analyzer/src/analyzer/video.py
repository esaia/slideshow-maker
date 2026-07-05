"""Candidate-segment detection: scene cuts + per-shot heuristic scoring.

Outputs segments scored 0-1 on a blend of motion, sharpness and exposure.
Runs on a low-res proxy, so per-frame work is cheap.
"""

from __future__ import annotations

import cv2
import numpy as np
from scenedetect import ContentDetector, detect

MIN_SHOT_S = 1.5
MAX_SHOT_S = 12.0
SAMPLE_FPS = 4  # frames sampled per second within a shot for scoring


def detect_shots(path: str) -> list[tuple[float, float]]:
    scenes = detect(path, ContentDetector(threshold=27.0))
    shots = [(s.get_seconds(), e.get_seconds()) for s, e in scenes]
    if not shots:  # single continuous take -> chunk it
        cap = cv2.VideoCapture(path)
        fps = cap.get(cv2.CAP_PROP_FPS) or 30
        n = cap.get(cv2.CAP_PROP_FRAME_COUNT)
        cap.release()
        duration = n / fps if fps else 0
        shots = [(t, min(t + MAX_SHOT_S, duration)) for t in np.arange(0, duration, MAX_SHOT_S)]
    # split overly long shots so the planner has finer material to cut with
    result: list[tuple[float, float]] = []
    for start, end in shots:
        t = start
        while end - t > MAX_SHOT_S * 1.5:
            result.append((t, t + MAX_SHOT_S))
            t += MAX_SHOT_S
        if end - t >= MIN_SHOT_S:
            result.append((t, end))
    return result


def score_shot(cap: cv2.VideoCapture, fps: float, start: float, end: float) -> dict:
    frame_times = np.arange(start, end, 1.0 / SAMPLE_FPS)
    prev_gray = None
    motions, sharpness, exposures = [], [], []

    for t in frame_times:
        cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000)
        ok, frame = cap.read()
        if not ok:
            continue
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        sharpness.append(cv2.Laplacian(gray, cv2.CV_64F).var())
        mean = gray.mean()
        # exposure quality: 1 at mid-gray, 0 at pure black/white
        exposures.append(1.0 - abs(mean - 128.0) / 128.0)
        if prev_gray is not None:
            diff = cv2.absdiff(gray, prev_gray)
            motions.append(float(diff.mean()))
        prev_gray = gray

    if not sharpness:
        return {"motion": 0.0, "sharpness": 0.0, "exposure": 0.0, "score": 0.0}

    motion = float(np.mean(motions)) if motions else 0.0
    sharp = float(np.mean(sharpness))
    expo = float(np.mean(exposures))

    # normalize to 0-1 with soft caps tuned for 480p proxies
    motion_n = min(motion / 20.0, 1.0)
    sharp_n = min(sharp / 300.0, 1.0)

    # moderate motion is best: reward some movement, punish violent shake
    motion_score = motion_n if motion_n < 0.6 else max(0.0, 1.2 - motion_n)
    score = 0.4 * motion_score + 0.35 * sharp_n + 0.25 * expo

    return {
        "motion": round(motion_n, 4),
        "sharpness": round(sharp_n, 4),
        "exposure": round(expo, 4),
        "score": round(float(score), 4),
    }


def analyze_video(path: str) -> dict:
    shots = detect_shots(path)
    cap = cv2.VideoCapture(path)
    fps = cap.get(cv2.CAP_PROP_FPS) or 30.0
    segments = []
    for start, end in shots:
        metrics = score_shot(cap, fps, start, end)
        segments.append({"start": round(start, 3), "end": round(end, 3), **metrics})
    cap.release()
    return {"path": path, "segments": segments}

"""Beat and energy analysis of the soundtrack via librosa.

Besides the plain beat grid, we detect *strong* beats — downbeats and loud
accents — so the edit can switch clips on the hits the listener actually
feels, not just any metronome tick.
"""

from __future__ import annotations

import librosa
import numpy as np


def analyze_music(path: str) -> dict:
    y, sr = librosa.load(path, mono=True)
    duration = float(librosa.get_duration(y=y, sr=sr))

    onset_env = librosa.onset.onset_strength(y=y, sr=sr)
    tempo, beat_frames = librosa.beat.beat_track(y=y, sr=sr, onset_envelope=onset_env)
    beats = librosa.frames_to_time(beat_frames, sr=sr)

    # how hard each beat "hits" (onset strength at the beat, normalized 0-1)
    strong_beats: list[float] = []
    strengths_out: list[float] = []
    if len(beat_frames) > 0:
        strengths = onset_env[np.minimum(beat_frames, len(onset_env) - 1)].astype(float)
        peak = strengths.max()
        s = strengths / peak if peak > 0 else strengths
        strengths_out = [round(float(v), 4) for v in s]

        # downbeat phase: the every-4th-beat offset with the highest average accent
        phase = 0
        if len(s) >= 4:
            phase = int(max(range(4), key=lambda p: float(np.mean(s[p::4]))))
        accent_threshold = float(np.mean(s) + np.std(s))

        strong_beats = [
            round(float(b), 3)
            for i, b in enumerate(beats)
            if i % 4 == phase or s[i] >= accent_threshold
        ]

    # RMS energy sampled at 2 Hz, normalized 0-1, for pacing decisions
    rms = librosa.feature.rms(y=y, hop_length=sr // 2)[0]
    rms = rms / rms.max() if rms.max() > 0 else rms

    return {
        "path": path,
        "duration": round(duration, 3),
        "tempo": round(float(np.atleast_1d(tempo)[0]), 2),
        "beats": [round(float(b), 3) for b in beats],
        "beat_strengths": strengths_out,
        "strong_beats": strong_beats,
        "energy": [round(float(v), 4) for v in rms],
        "energy_hz": 2,
    }

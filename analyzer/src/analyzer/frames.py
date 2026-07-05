"""AI ranking of candidate segments.

Extracts representative frames per segment, sends them to the Claude API in
batches, and returns a visual-interest score (0-10) plus a short tag for each.
"""

from __future__ import annotations

import base64
import json
import os
import sys

import anthropic
import cv2

BATCH_SIZE = 20
JPEG_QUALITY = 80
FRAME_MAX_W = 768  # enough for scene understanding, keeps tokens low

SYSTEM = (
    "You rate clips from raw hiking footage for inclusion in a highlights montage. "
    "You are shown one representative frame per clip. Score each frame 0-10 for visual "
    "interest: dramatic landscapes, summit views, waterfalls, wildlife, golden light, "
    "people in striking settings score high; boring trail ground, blurry shots, "
    "backs of heads filling the frame, dark or washed-out images score low. "
    "Give each a 2-4 word tag describing the content."
)

SCHEMA = {
    "type": "object",
    "properties": {
        "ratings": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "integer"},
                    "score": {"type": "number"},
                    "tag": {"type": "string"},
                },
                "required": ["id", "score", "tag"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["ratings"],
    "additionalProperties": False,
}


def extract_frame(video_path: str, t: float, out_path: str | None = None) -> bytes | None:
    cap = cv2.VideoCapture(video_path)
    cap.set(cv2.CAP_PROP_POS_MSEC, t * 1000)
    ok, frame = cap.read()
    cap.release()
    if not ok:
        return None
    h, w = frame.shape[:2]
    if w > FRAME_MAX_W:
        scale = FRAME_MAX_W / w
        frame = cv2.resize(frame, (FRAME_MAX_W, int(h * scale)))
    ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY])
    if not ok:
        return None
    data = buf.tobytes()
    if out_path:
        with open(out_path, "wb") as f:
            f.write(data)
    return data


def rank_segments(spec: dict) -> dict:
    """spec: {"thumbnails_dir": str|None, "segments": [{"id", "video", "start", "end"}]}"""
    client = anthropic.Anthropic()  # ANTHROPIC_API_KEY from env
    thumbs_dir = spec.get("thumbnails_dir")
    if thumbs_dir:
        os.makedirs(thumbs_dir, exist_ok=True)

    items = []  # (segment_id, jpeg_bytes, thumb_path)
    for seg in spec["segments"]:
        mid = (seg["start"] + seg["end"]) / 2.0
        thumb = os.path.join(thumbs_dir, f"{seg['id']}.jpg") if thumbs_dir else None
        data = extract_frame(seg["video"], mid, thumb)
        if data is not None:
            items.append((seg["id"], data, thumb))

    results = {}
    thumb_paths = {sid: thumb for sid, _, thumb in items}

    for i in range(0, len(items), BATCH_SIZE):
        batch = items[i : i + BATCH_SIZE]
        content = []
        for sid, data, _ in batch:
            content.append({"type": "text", "text": f"Clip id {sid}:"})
            content.append({
                "type": "image",
                "source": {
                    "type": "base64",
                    "media_type": "image/jpeg",
                    "data": base64.standard_b64encode(data).decode(),
                },
            })
        content.append({
            "type": "text",
            "text": "Rate every clip above. Return one rating per clip id.",
        })

        response = client.messages.create(
            model="claude-opus-4-8",
            max_tokens=4096,
            system=SYSTEM,
            messages=[{"role": "user", "content": content}],
            output_config={"format": {"type": "json_schema", "schema": SCHEMA}},
        )
        if response.stop_reason == "refusal":
            print(f"batch starting at {i}: refused", file=sys.stderr)
            continue
        text = next(b.text for b in response.content if b.type == "text")
        for rating in json.loads(text)["ratings"]:
            results[rating["id"]] = {
                "score": max(0.0, min(10.0, float(rating["score"]))),
                "tag": rating["tag"][:60],
            }

    return {
        "ratings": [
            {
                "id": sid,
                "thumbnail": thumb_paths.get(sid),
                **results.get(sid, {"score": None, "tag": None}),
            }
            for sid, _, _ in items
        ]
    }

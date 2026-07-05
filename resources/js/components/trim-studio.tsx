import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import FileBrowser from '@/components/file-browser';
import { fmtTime, type ProjectData, type SegmentData } from '@/pages/projects/types';

type Range = { in: number; out: number };

const MIN_RANGE_S = 0.8;

/**
 * Step through your videos, drag across the timeline to frame a moment,
 * add it as a clip — then pick a format and render.
 */
export default function TrimStudio({ project }: { project: ProjectData }) {
    const videos = project.source_videos;
    const [idx, setIdx] = useState(0);
    const [aspect, setAspect] = useState<'landscape' | 'vertical'>('landscape');
    const [musicPicker, setMusicPicker] = useState(false);
    const [gridView, setGridView] = useState(false);
    const [videoPicker, setVideoPicker] = useState(false);
    const videoRef = useRef<HTMLVideoElement>(null);

    const video = videos[idx];
    const [duration, setDuration] = useState(video?.duration ?? 0);
    const [currentTime, setCurrentTime] = useState(0);
    const [range, setRange] = useState<Range | null>(null);
    const [editingClipId, setEditingClipId] = useState<number | null>(null);

    const clipsHere = project.segments.filter((s) => s.video_id === video?.id);
    const totalSelected = useMemo(
        () => project.segments.reduce((sum, s) => sum + (s.end_s - s.start_s), 0),
        [project.segments],
    );

    // no default selection — reset everything when switching videos
    useEffect(() => {
        setDuration(video?.duration ?? 0);
        setCurrentTime(0);
        setRange(null);
        setEditingClipId(null);
    }, [idx, video?.duration]);

    function go(delta: number) {
        setIdx((i) => Math.min(videos.length - 1, Math.max(0, i + delta)));
    }

    // editor-style shortcuts: I = mark in, O = mark out, Space = play/pause
    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            const target = e.target as HTMLElement | null;
            if (
                target &&
                (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'VIDEO'].includes(target.tagName) ||
                    target.isContentEditable)
            ) {
                return; // let native controls / focused elements handle keys
            }
            if (gridView || musicPicker) return;

            const key = e.key.toLowerCase();

            if (key === ' ') {
                e.preventDefault(); // stop the page from scrolling
                const el = videoRef.current;
                if (el) {
                    if (el.paused) void el.play();
                    else el.pause();
                }
                return;
            }

            if (key !== 'i' && key !== 'o') return;
            e.preventDefault();

            const t = videoRef.current?.currentTime ?? 0;
            setRange((prev) => {
                if (key === 'i') {
                    const out = prev && prev.out > t + MIN_RANGE_S ? prev.out : Math.min(duration, t + MIN_RANGE_S);
                    return { in: Math.min(t, out - MIN_RANGE_S), out };
                }
                const inPoint = prev && prev.in < t - MIN_RANGE_S ? prev.in : Math.max(0, t - MIN_RANGE_S);
                return { in: inPoint, out: Math.max(t, inPoint + MIN_RANGE_S) };
            });
        }
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [duration, gridView, musicPicker]);

    function seek(t: number) {
        const el = videoRef.current;
        if (el) el.currentTime = t;
        setCurrentTime(t);
    }

    function selectClip(clip: SegmentData) {
        setEditingClipId(clip.id);
        setRange({ in: clip.start_s, out: clip.end_s });
        preview(clip.start_s, clip.end_s); // clicking a clip plays it right away
    }

    function deselectClip() {
        setEditingClipId(null);
        setRange(null);
    }

    function addClip() {
        if (!range || range.out - range.in < MIN_RANGE_S) return;
        router.post(
            `/projects/${project.id}/videos/${video.id}/segments`,
            { start_s: Math.round(range.in * 1000) / 1000, end_s: Math.round(range.out * 1000) / 1000 },
            { preserveScroll: true, preserveState: true, onSuccess: () => setRange(null) },
        );
    }

    function saveClip() {
        if (editingClipId === null || !range || range.out - range.in < MIN_RANGE_S) return;
        router.patch(
            `/projects/${project.id}/segments/${editingClipId}`,
            { start_s: Math.round(range.in * 1000) / 1000, end_s: Math.round(range.out * 1000) / 1000 },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setEditingClipId(null);
                    setRange(null);
                },
            },
        );
    }

    function removeClip(id: number) {
        if (id === editingClipId) deselectClip();
        router.delete(`/projects/${project.id}/segments/${id}`, { preserveScroll: true, preserveState: true });
    }

    function render() {
        router.post(`/projects/${project.id}/render`, { aspect }, { preserveScroll: true });
    }

    const previewStopRef = useRef<(() => void) | null>(null);

    function preview(start: number, end: number) {
        const el = videoRef.current;
        if (!el) return;
        // kill any previous preview's stop-listener, or it pauses the new one
        if (previewStopRef.current) {
            el.removeEventListener('timeupdate', previewStopRef.current);
            previewStopRef.current = null;
        }
        el.currentTime = start;
        void el.play();
        const stop = () => {
            if (el.currentTime >= end) {
                el.pause();
                el.removeEventListener('timeupdate', stop);
                if (previewStopRef.current === stop) previewStopRef.current = null;
            }
        };
        previewStopRef.current = stop;
        el.addEventListener('timeupdate', stop);
    }

    if (!video) return null;

    if (gridView) {
        return (
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold">All videos ({videos.length})</h2>
                    <div className="flex gap-2">
                        <button
                            onClick={() => setVideoPicker(true)}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold hover:bg-emerald-500"
                        >
                            + Add videos
                        </button>
                        <button
                            onClick={() => setGridView(false)}
                            className="rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700"
                        >
                            Back to trimming
                        </button>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {videos.map((v, i) => {
                        const clipCount = project.segments.filter((s) => s.video_id === v.id).length;
                        return (
                            <button
                                key={v.id}
                                onClick={() => {
                                    setIdx(i);
                                    setGridView(false);
                                }}
                                className={`overflow-hidden rounded-lg border text-left transition hover:border-emerald-500 ${
                                    i === idx ? 'border-emerald-600' : 'border-neutral-800'
                                }`}
                            >
                                <div className="relative aspect-video bg-neutral-900">
                                    <img
                                        src={`/projects/${project.id}/videos/${v.id}/thumb`}
                                        alt={v.name}
                                        loading="lazy"
                                        className="h-full w-full object-cover"
                                    />
                                    {clipCount > 0 && (
                                        <span className="absolute top-1.5 right-1.5 rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">
                                            {clipCount} clip{clipCount > 1 ? 's' : ''}
                                        </span>
                                    )}
                                    {v.duration != null && (
                                        <span className="absolute bottom-1.5 right-1.5 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[10px] text-neutral-200">
                                            {fmtTime(v.duration)}
                                        </span>
                                    )}
                                </div>
                                <div className="p-2 text-xs">
                                    <div className="truncate font-medium">{v.name}</div>
                                    {v.shot_at && (
                                        <div className="text-neutral-500">
                                            {new Date(v.shot_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </div>
                                    )}
                                </div>
                            </button>
                        );
                    })}
                </div>
                {videoPicker && (
                    <FileBrowser
                        type="video"
                        multiple
                        initialSelected={[]}
                        onConfirm={(paths) => {
                            setVideoPicker(false);
                            if (paths.length > 0) {
                                router.post(`/projects/${project.id}/videos`, { video_paths: paths }, { preserveScroll: true });
                            }
                        }}
                        onClose={() => setVideoPicker(false)}
                    />
                )}
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* video navigation header */}
            <div className="flex items-center justify-between gap-3">
                <button
                    onClick={() => go(-1)}
                    disabled={idx === 0}
                    className="rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700 disabled:opacity-30"
                >
                    ← Previous
                </button>
                <div className="text-center">
                    <div className="font-medium">{video.name}</div>
                    <div className="text-xs text-neutral-400">
                        Video {idx + 1} of {videos.length}
                        {video.shot_at && <> · shot {new Date(video.shot_at).toLocaleString()}</>}
                    </div>
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={() => setGridView(true)}
                        className="rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700"
                        title="See all videos"
                    >
                        ⊞ All videos
                    </button>
                    <button
                        onClick={() => go(1)}
                        disabled={idx === videos.length - 1}
                        className="rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700 disabled:opacity-30"
                    >
                        Next →
                    </button>
                </div>
            </div>

            {/* player */}
            <video
                ref={videoRef}
                key={video.id}
                controls
                preload="metadata"
                src={`/projects/${project.id}/videos/${video.id}/proxy`}
                className="max-h-[46vh] w-full rounded-lg bg-black"
                onTimeUpdate={(e) => setCurrentTime(e.currentTarget.currentTime)}
                onLoadedMetadata={(e) => {
                    const d = e.currentTarget.duration;
                    if (Number.isFinite(d) && d > 0) setDuration(d);
                }}
            />

            {/* trim timeline */}
            <div className="rounded-lg border border-neutral-800 p-4">
                <Timeline
                    duration={duration}
                    range={range}
                    currentTime={currentTime}
                    clips={clipsHere.filter((c) => c.id !== editingClipId)}
                    onSeek={seek}
                    onSelectClip={selectClip}
                    onPreviewRange={() => range && preview(range.in, range.out)}
                    onChange={setRange}
                />
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    {range ? (
                        <span className="font-mono text-sm text-neutral-300">
                            {fmtTime(range.in)} → {fmtTime(range.out)}
                            <span className="ml-2 text-emerald-400">({(range.out - range.in).toFixed(1)}s)</span>
                        </span>
                    ) : (
                        <span className="text-sm text-neutral-500">
                            Drag across the bar to select a moment — or press{' '}
                            <kbd className="rounded bg-neutral-800 px-1.5">Space</kbd> to play/pause,{' '}
                            <kbd className="rounded bg-neutral-800 px-1.5">I</kbd> for start,{' '}
                            <kbd className="rounded bg-neutral-800 px-1.5">O</kbd> for end
                        </span>
                    )}
                    {range && (
                        <>
                            <button
                                onClick={() => setRange({ in: Math.min(currentTime, range.out - MIN_RANGE_S), out: range.out })}
                                className="rounded bg-neutral-800 px-2.5 py-1 text-xs hover:bg-neutral-700"
                                title="Set start to the playhead"
                            >
                                ⇤ start = playhead
                            </button>
                            <button
                                onClick={() => setRange({ in: range.in, out: Math.max(currentTime, range.in + MIN_RANGE_S) })}
                                className="rounded bg-neutral-800 px-2.5 py-1 text-xs hover:bg-neutral-700"
                                title="Set end to the playhead"
                            >
                                end = playhead ⇥
                            </button>
                            <button
                                onClick={() => preview(range.in, range.out)}
                                className="rounded bg-neutral-800 px-2.5 py-1 text-xs hover:bg-neutral-700"
                            >
                                ▶ Preview range
                            </button>
                        </>
                    )}
                    {editingClipId === null ? (
                        <button
                            onClick={addClip}
                            disabled={!range || range.out - range.in < MIN_RANGE_S}
                            className="ml-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold hover:bg-emerald-500 disabled:opacity-40"
                        >
                            + Add clip
                        </button>
                    ) : (
                        <span className="ml-auto flex items-center gap-2">
                            <span className="text-xs text-sky-400">editing saved clip</span>
                            <button
                                onClick={deselectClip}
                                className="rounded-md bg-neutral-800 px-3 py-2 text-sm hover:bg-neutral-700"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={saveClip}
                                disabled={!range || range.out - range.in < MIN_RANGE_S}
                                className="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold hover:bg-sky-500 disabled:opacity-40"
                            >
                                ✓ Save changes
                            </button>
                        </span>
                    )}
                </div>
            </div>

            {/* clips for the current video */}
            {clipsHere.length > 0 && (
                <div>
                    <h3 className="mb-2 text-sm font-medium text-neutral-400">
                        Clips from this video ({clipsHere.length})
                    </h3>
                    <ul className="space-y-1.5">
                        {clipsHere.map((clip) => (
                            <li
                                key={clip.id}
                                className={`flex items-center gap-3 rounded-md border px-3 py-2 text-sm ${
                                    clip.id === editingClipId ? 'border-sky-600 bg-sky-950/30' : 'border-neutral-800'
                                }`}
                            >
                                <button
                                    onClick={() => preview(clip.start_s, clip.end_s)}
                                    className="text-emerald-400 hover:text-emerald-300"
                                    title="Preview"
                                >
                                    ▶
                                </button>
                                <span className="font-mono">
                                    {fmtTime(clip.start_s)} → {fmtTime(clip.end_s)}
                                </span>
                                <span className="text-neutral-500">({(clip.end_s - clip.start_s).toFixed(1)}s)</span>
                                <button
                                    onClick={() => selectClip(clip)}
                                    className="ml-auto text-sky-400 hover:text-sky-300"
                                >
                                    ✎ Edit
                                </button>
                                <button
                                    onClick={() => removeClip(clip.id)}
                                    className="text-neutral-500 hover:text-red-400"
                                >
                                    ✕ Remove
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* summary + render */}
            <div className="rounded-lg border border-neutral-800 p-4">
                <div className="mb-3 flex items-center justify-between text-sm">
                    <span>
                        <strong>{project.segments.length}</strong> clips selected ·{' '}
                        <strong>{fmtTime(totalSelected)}</strong> total
                    </span>
                    <span className="flex items-center gap-2 text-neutral-400">
                        <span className="max-w-64 truncate">
                            ♪ {project.music_path.split('/').pop()} ({fmtTime(project.music_duration)})
                        </span>
                        <button
                            onClick={() => setMusicPicker(true)}
                            className="rounded bg-neutral-800 px-2 py-1 text-xs hover:bg-neutral-700"
                        >
                            Change
                        </button>
                    </span>
                </div>
                <div className="mb-4 h-1.5 overflow-hidden rounded bg-neutral-800">
                    <div
                        className={`h-full ${totalSelected > project.music_duration ? 'bg-amber-500' : 'bg-emerald-500'}`}
                        style={{ width: `${Math.min(100, (totalSelected / (project.music_duration || 1)) * 100)}%` }}
                    />
                </div>
                {totalSelected > project.music_duration && (
                    <p className="mb-3 text-xs text-amber-400">
                        Selection is longer than the music — clips at the end will be dropped.
                    </p>
                )}

                <div className="flex items-center gap-3">
                    <div className="flex overflow-hidden rounded-md border border-neutral-700">
                        {(['landscape', 'vertical'] as const).map((a) => (
                            <button
                                key={a}
                                onClick={() => setAspect(a)}
                                className={`px-4 py-2 text-sm font-medium ${
                                    aspect === a ? 'bg-emerald-600 text-white' : 'bg-neutral-900 text-neutral-300 hover:bg-neutral-800'
                                }`}
                            >
                                {a === 'landscape' ? '▭ Landscape 16:9' : '▯ Vertical 9:16'}
                            </button>
                        ))}
                    </div>
                    <button
                        onClick={render}
                        disabled={project.segments.length === 0}
                        className="ml-auto rounded-md bg-emerald-600 px-6 py-2.5 text-sm font-semibold hover:bg-emerald-500 disabled:opacity-40"
                    >
                        Render slideshow
                    </button>
                </div>
            </div>

            {musicPicker && (
                <FileBrowser
                    type="audio"
                    multiple={false}
                    initialSelected={[project.music_path]}
                    onConfirm={(paths) => {
                        setMusicPicker(false);
                        if (paths[0] && paths[0] !== project.music_path) {
                            router.post(`/projects/${project.id}/music`, { music_path: paths[0] }, { preserveScroll: true });
                        }
                    }}
                    onClose={() => setMusicPicker(false)}
                />
            )}
        </div>
    );
}

/**
 * Scrubber + range selector. No selection by default: drag across the empty
 * track to create one, then drag its edges/middle to adjust. Plain click
 * seeks. Saved clips show dimmed — click one to load it for editing.
 */
function Timeline({
    duration,
    range,
    currentTime,
    clips,
    onSeek,
    onSelectClip,
    onPreviewRange,
    onChange,
}: {
    duration: number;
    range: Range | null;
    currentTime: number;
    clips: SegmentData[];
    onSeek: (t: number) => void;
    onSelectClip: (clip: SegmentData) => void;
    onPreviewRange: () => void;
    onChange: (range: Range | null) => void;
}) {
    const trackRef = useRef<HTMLDivElement>(null);
    const dragRef = useRef<{
        mode: 'in' | 'out' | 'move' | 'create';
        anchor: number;
        startX: number;
        moved: boolean;
    } | null>(null);

    const pct = (t: number) => (duration > 0 ? Math.min(100, Math.max(0, (t / duration) * 100)) : 0);

    const timeAt = useCallback(
        (clientX: number): number => {
            const rect = trackRef.current?.getBoundingClientRect();
            if (!rect || duration <= 0) return 0;
            const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
            return ratio * duration;
        },
        [duration],
    );

    useEffect(() => {
        function onMove(e: PointerEvent) {
            const drag = dragRef.current;
            if (!drag) return;
            // ignore sub-4px jitter so a click doesn't count as a drag
            if (!drag.moved && Math.abs(e.clientX - drag.startX) <= 4) return;
            const t = timeAt(e.clientX);
            drag.moved = true;
            if (drag.mode === 'create') {
                onChange({ in: Math.min(drag.anchor, t), out: Math.max(drag.anchor, t) });
                onSeek(t);
            } else if (drag.mode === 'in' && range) {
                const next = Math.max(0, Math.min(t, range.out - MIN_RANGE_S));
                onChange({ in: next, out: range.out });
                onSeek(next);
            } else if (drag.mode === 'out' && range) {
                const next = Math.min(duration, Math.max(t, range.in + MIN_RANGE_S));
                onChange({ in: range.in, out: next });
                onSeek(next);
            } else if (drag.mode === 'move' && range) {
                const len = range.out - range.in;
                let nextIn = t - drag.anchor;
                nextIn = Math.min(Math.max(0, nextIn), duration - len);
                onChange({ in: nextIn, out: nextIn + len });
                onSeek(nextIn);
            }
        }
        function onUp() {
            const drag = dragRef.current;
            dragRef.current = null;
            if (!drag) return;
            // a create-drag that stayed tiny is just a click-to-seek
            if (drag.mode === 'create' && drag.moved && range && range.out - range.in < MIN_RANGE_S) {
                onChange(null);
            }
            // a click on the range (no drag) plays it
            if (drag.mode === 'move' && !drag.moved) {
                onPreviewRange();
            }
        }
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
        };
    }, [range, duration, onChange, onSeek, timeAt]);

    return (
        <div
            ref={trackRef}
            className="relative h-12 cursor-crosshair touch-none rounded-md bg-neutral-800 select-none"
            onPointerDown={(e) => {
                const t = timeAt(e.clientX);
                onSeek(t);
                dragRef.current = { mode: 'create', anchor: t, startX: e.clientX, moved: false };
            }}
        >
            {/* already-added clips — click to select and edit */}
            {clips.map((clip) => (
                <div
                    key={clip.id}
                    className="absolute top-0 z-[5] h-full cursor-pointer border-y border-emerald-700/60 bg-emerald-500/20 hover:bg-emerald-500/40"
                    style={{ left: `${pct(clip.start_s)}%`, width: `${pct(clip.end_s) - pct(clip.start_s)}%` }}
                    title={`saved clip ${fmtTime(clip.start_s)} → ${fmtTime(clip.end_s)} — click to edit`}
                    onPointerDown={(e) => {
                        e.stopPropagation();
                        onSelectClip(clip);
                    }}
                />
            ))}

            {range && (
                <>
                    {/* active range — drag middle to move */}
                    <div
                        className="absolute top-0 z-[8] h-full cursor-grab border-y-2 border-emerald-500 bg-emerald-500/35 active:cursor-grabbing"
                        style={{ left: `${pct(range.in)}%`, width: `${pct(range.out) - pct(range.in)}%` }}
                        onPointerDown={(e) => {
                            e.stopPropagation();
                            dragRef.current = { mode: 'move', anchor: timeAt(e.clientX) - range.in, startX: e.clientX, moved: false };
                        }}
                    />

                    {/* start handle */}
                    <div
                        className="absolute top-0 z-10 h-full w-3 -translate-x-1/2 cursor-ew-resize"
                        style={{ left: `${pct(range.in)}%` }}
                        onPointerDown={(e) => {
                            e.stopPropagation();
                            dragRef.current = { mode: 'in', anchor: 0, startX: e.clientX, moved: false };
                        }}
                    >
                        <div className="mx-auto h-full w-1.5 rounded bg-emerald-400" />
                    </div>

                    {/* end handle */}
                    <div
                        className="absolute top-0 z-10 h-full w-3 -translate-x-1/2 cursor-ew-resize"
                        style={{ left: `${pct(range.out)}%` }}
                        onPointerDown={(e) => {
                            e.stopPropagation();
                            dragRef.current = { mode: 'out', anchor: 0, startX: e.clientX, moved: false };
                        }}
                    >
                        <div className="mx-auto h-full w-1.5 rounded bg-emerald-400" />
                    </div>
                </>
            )}

            {/* playhead */}
            <div
                className="pointer-events-none absolute top-0 z-20 h-full w-0.5 bg-white"
                style={{ left: `${pct(currentTime)}%` }}
            />
        </div>
    );
}

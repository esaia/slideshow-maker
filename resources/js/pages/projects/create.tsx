import FileBrowser from '@/components/file-browser';
import Shell from '@/layouts/shell';
import { filterDropped, uploadFile } from '@/lib/upload';
import { Head, useForm } from '@inertiajs/react';
import { useState, type DragEvent, type FormEvent, type ReactNode } from 'react';

interface UploadJob {
    key: string;
    name: string;
    progress: number; // 0..1, -1 = failed
}

export default function Create() {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        music_path: string;
        video_paths: string[];
    }>({
        name: '',
        music_path: '',
        video_paths: [],
    });

    const [picker, setPicker] = useState<'video' | 'audio' | null>(null);
    const [uploads, setUploads] = useState<UploadJob[]>([]);

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/projects');
    }

    function patchUpload(key: string, progress: number) {
        setUploads((prev) => prev.map((u) => (u.key === key ? { ...u, progress } : u)));
    }

    async function handleDrop(files: File[], target: 'video' | 'audio') {
        for (const file of files) {
            const key = `${Date.now()}-${file.name}-${Math.random()}`;
            setUploads((prev) => [...prev, { key, name: file.name, progress: 0 }]);
            try {
                const path = await uploadFile(file, (p) => patchUpload(key, p));
                setUploads((prev) => prev.filter((u) => u.key !== key));
                if (target === 'video') {
                    setData((d) => ({ ...d, video_paths: [...d.video_paths, path] }));
                } else {
                    setData((d) => ({ ...d, music_path: path }));
                }
            } catch {
                patchUpload(key, -1);
            }
        }
    }

    const uploading = uploads.some((u) => u.progress >= 0 && u.progress < 1);

    return (
        <Shell>
            <Head title="New slideshow" />
            <h1 className="mb-2 text-2xl font-bold">New slideshow</h1>
            <p className="mb-8 text-sm text-neutral-400">
                Browse picks files straight from your disk; dragging & dropping copies them into the app first.
            </p>

            <form onSubmit={submit} className="max-w-2xl space-y-6">
                <div>
                    <label className="mb-1.5 block text-sm font-medium">Name</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="Kazbegi trip, day 2"
                        className="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                    />
                    {errors.name && <p className="mt-1 text-sm text-red-400">{errors.name}</p>}
                </div>

                <div>
                    <div className="mb-1.5 flex items-center justify-between">
                        <label className="text-sm font-medium">Videos ({data.video_paths.length})</label>
                        <button
                            type="button"
                            onClick={() => setPicker('video')}
                            className="rounded-md bg-neutral-800 px-3 py-1.5 text-sm hover:bg-neutral-700"
                        >
                            Browse…
                        </button>
                    </div>
                    <DropZone type="video" onDrop={(files) => handleDrop(files, 'video')}>
                        {data.video_paths.length === 0 ? (
                            <button
                                type="button"
                                onClick={() => setPicker('video')}
                                className="w-full p-8 text-sm text-neutral-500 hover:text-neutral-300"
                            >
                                Drop your hiking videos here, or click to browse
                            </button>
                        ) : (
                            <ul className="divide-y divide-neutral-800">
                                {data.video_paths.map((path) => (
                                    <li key={path} className="flex items-center gap-2 px-3 py-2 text-sm">
                                        <span className="flex-1 truncate font-mono text-xs">{path}</span>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setData('video_paths', data.video_paths.filter((p) => p !== path))
                                            }
                                            className="text-neutral-500 hover:text-red-400"
                                        >
                                            ✕
                                        </button>
                                    </li>
                                ))}
                                <li className="px-3 py-2 text-center text-xs text-neutral-500">
                                    drop more videos here
                                </li>
                            </ul>
                        )}
                    </DropZone>
                    {'video_paths' in errors && (
                        <p className="mt-1 text-sm text-red-400">{(errors as Record<string, string>).video_paths}</p>
                    )}
                </div>

                <div>
                    <div className="mb-1.5 flex items-center justify-between">
                        <label className="text-sm font-medium">Music</label>
                        <button
                            type="button"
                            onClick={() => setPicker('audio')}
                            className="rounded-md bg-neutral-800 px-3 py-1.5 text-sm hover:bg-neutral-700"
                        >
                            Browse…
                        </button>
                    </div>
                    <DropZone type="audio" onDrop={(files) => handleDrop(files.slice(0, 1), 'audio')}>
                        {data.music_path ? (
                            <div className="flex items-center gap-2 px-3 py-2 text-sm">
                                <span className="flex-1 truncate font-mono text-xs">{data.music_path}</span>
                                <button
                                    type="button"
                                    onClick={() => setData('music_path', '')}
                                    className="text-neutral-500 hover:text-red-400"
                                >
                                    ✕
                                </button>
                            </div>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setPicker('audio')}
                                className="w-full p-4 text-sm text-neutral-500 hover:text-neutral-300"
                            >
                                Drop a music track here, or click to browse
                            </button>
                        )}
                    </DropZone>
                    {errors.music_path && <p className="mt-1 text-sm text-red-400">{errors.music_path}</p>}
                </div>

                {uploads.length > 0 && (
                    <ul className="space-y-1.5">
                        {uploads.map((u) => (
                            <li key={u.key} className="flex items-center gap-3 rounded-md border border-neutral-800 px-3 py-2 text-sm">
                                <span className="flex-1 truncate">{u.name}</span>
                                {u.progress < 0 ? (
                                    <span className="text-xs text-red-400">failed</span>
                                ) : (
                                    <>
                                        <div className="h-1.5 w-32 overflow-hidden rounded bg-neutral-800">
                                            <div
                                                className="h-full bg-emerald-500 transition-all"
                                                style={{ width: `${u.progress * 100}%` }}
                                            />
                                        </div>
                                        <span className="w-10 text-right text-xs text-neutral-400">
                                            {Math.round(u.progress * 100)}%
                                        </span>
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <button
                    type="submit"
                    disabled={processing || uploading || data.video_paths.length === 0 || !data.music_path}
                    className="rounded-md bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                >
                    {processing ? 'Starting…' : uploading ? 'Uploading…' : 'Analyze & build slideshow'}
                </button>
            </form>

            {picker && (
                <FileBrowser
                    type={picker}
                    multiple={picker === 'video'}
                    initialSelected={picker === 'video' ? data.video_paths : data.music_path ? [data.music_path] : []}
                    onConfirm={(paths) => {
                        if (picker === 'video') setData('video_paths', paths);
                        else setData('music_path', paths[0] ?? '');
                        setPicker(null);
                    }}
                    onClose={() => setPicker(null)}
                />
            )}
        </Shell>
    );
}

function DropZone({
    type,
    onDrop,
    children,
}: {
    type: 'video' | 'audio';
    onDrop: (files: File[]) => void;
    children: ReactNode;
}) {
    const [over, setOver] = useState(false);

    function handleDrop(e: DragEvent) {
        e.preventDefault();
        setOver(false);
        const files = filterDropped(e.dataTransfer.files, type);
        if (files.length > 0) onDrop(files);
    }

    return (
        <div
            onDragOver={(e) => {
                e.preventDefault();
                setOver(true);
            }}
            onDragLeave={() => setOver(false)}
            onDrop={handleDrop}
            className={`rounded-md border border-dashed transition ${
                over ? 'border-emerald-500 bg-emerald-950/20' : 'border-neutral-700'
            }`}
        >
            {children}
        </div>
    );
}

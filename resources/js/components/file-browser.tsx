import { useCallback, useEffect, useState, type DragEvent } from 'react';
import { filterDropped, uploadFile } from '@/lib/upload';

interface Entry {
    name: string;
    path: string;
    type: 'dir' | 'file';
    size?: number;
}

interface Listing {
    path: string;
    parent: string | null;
    roots: { name: string; path: string }[];
    entries: Entry[];
}

export default function FileBrowser({
    type,
    multiple,
    initialSelected = [],
    onConfirm,
    onClose,
}: {
    type: 'video' | 'audio';
    multiple: boolean;
    initialSelected?: string[];
    onConfirm: (paths: string[]) => void;
    onClose: () => void;
}) {
    const [listing, setListing] = useState<Listing | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set(initialSelected));
    const [dragOver, setDragOver] = useState(false);
    const [uploading, setUploading] = useState<{ name: string; progress: number } | null>(null);

    async function handleDrop(e: DragEvent) {
        e.preventDefault();
        setDragOver(false);
        const files = filterDropped(e.dataTransfer.files, type);
        if (files.length === 0) return;

        const accepted = multiple ? files : files.slice(0, 1);
        const paths: string[] = [];
        for (const file of accepted) {
            setUploading({ name: file.name, progress: 0 });
            try {
                paths.push(await uploadFile(file, (p) => setUploading({ name: file.name, progress: p })));
            } catch {
                setUploading(null);
                return;
            }
        }
        setUploading(null);
        // a dropped file is an explicit choice — confirm immediately
        onConfirm(multiple ? [...new Set([...selected, ...paths])] : paths);
    }

    const load = useCallback(
        async (path?: string) => {
            const params = new URLSearchParams({ type, ...(path ? { path } : {}) });
            const res = await fetch(`/browse?${params}`);
            if (res.ok) setListing(await res.json());
        },
        [type],
    );

    useEffect(() => {
        load();
    }, [load]);

    const filesHere = listing?.entries.filter((e) => e.type === 'file') ?? [];
    const allHereSelected = filesHere.length > 0 && filesHere.every((f) => selected.has(f.path));

    function toggleAll() {
        setSelected((prev) => {
            const next = new Set(prev);
            if (allHereSelected) filesHere.forEach((f) => next.delete(f.path));
            else filesHere.forEach((f) => next.add(f.path));
            return next;
        });
    }

    function toggle(path: string) {
        setSelected((prev) => {
            if (!multiple) return new Set(prev.has(path) ? [] : [path]);
            const next = new Set(prev);
            if (next.has(path)) next.delete(path);
            else next.add(path);
            return next;
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" onClick={onClose}>
            <div
                className={`flex max-h-[80vh] w-full max-w-2xl flex-col rounded-xl border bg-neutral-900 shadow-2xl ${
                    dragOver ? 'border-emerald-500' : 'border-neutral-700'
                }`}
                onClick={(e) => e.stopPropagation()}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={handleDrop}
            >
                <div className="border-b border-neutral-800 p-4">
                    <div className="mb-2 flex items-center gap-2">
                        {listing?.roots.map((root) => (
                            <button
                                key={root.path}
                                onClick={() => load(root.path)}
                                className={`rounded px-2 py-1 text-xs font-medium ${
                                    listing.path.startsWith(root.path)
                                        ? 'bg-emerald-600/20 text-emerald-400'
                                        : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'
                                }`}
                            >
                                {root.name}
                            </button>
                        ))}
                    </div>
                    <div className="flex items-center gap-2 text-sm text-neutral-400">
                        {listing?.parent && (
                            <button
                                onClick={() => load(listing.parent!)}
                                className="rounded bg-neutral-800 px-2 py-0.5 hover:bg-neutral-700"
                            >
                                ← Up
                            </button>
                        )}
                        <span className="truncate font-mono text-xs">{listing?.path}</span>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-2">
                    {multiple && filesHere.length > 0 && (
                        <label className="flex w-full cursor-pointer items-center gap-2 rounded border-b border-neutral-800 px-3 py-2 text-sm font-medium hover:bg-neutral-800">
                            <input
                                type="checkbox"
                                checked={allHereSelected}
                                onChange={toggleAll}
                                className="accent-emerald-500"
                            />
                            Select all in this folder ({filesHere.length})
                        </label>
                    )}
                    {listing?.entries.length === 0 && (
                        <p className="p-6 text-center text-sm text-neutral-500">
                            No folders or {type} files here.
                        </p>
                    )}
                    <ul>
                        {listing?.entries.map((entry) => (
                            <li key={entry.path}>
                                {entry.type === 'dir' ? (
                                    <button
                                        onClick={() => load(entry.path)}
                                        className="flex w-full items-center gap-2 rounded px-3 py-2 text-left text-sm hover:bg-neutral-800"
                                    >
                                        <span>📁</span> {entry.name}
                                    </button>
                                ) : (
                                    <label className="flex w-full cursor-pointer items-center gap-2 rounded px-3 py-2 text-sm hover:bg-neutral-800">
                                        <input
                                            type={multiple ? 'checkbox' : 'radio'}
                                            checked={selected.has(entry.path)}
                                            onChange={() => toggle(entry.path)}
                                            className="accent-emerald-500"
                                        />
                                        <span className="flex-1 truncate">{entry.name}</span>
                                        <span className="text-xs text-neutral-500">{fmtSize(entry.size ?? 0)}</span>
                                    </label>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="flex items-center justify-between border-t border-neutral-800 p-4">
                    {uploading ? (
                        <span className="flex items-center gap-2 text-sm text-neutral-300">
                            <span className="max-w-48 truncate">↑ {uploading.name}</span>
                            <span className="h-1.5 w-24 overflow-hidden rounded bg-neutral-800">
                                <span
                                    className="block h-full bg-emerald-500 transition-all"
                                    style={{ width: `${uploading.progress * 100}%` }}
                                />
                            </span>
                            <span className="text-xs text-neutral-400">{Math.round(uploading.progress * 100)}%</span>
                        </span>
                    ) : (
                        <span className="text-sm text-neutral-400">
                            {selected.size} selected · or drop {type === 'audio' ? 'a file' : 'files'} here
                        </span>
                    )}
                    <div className="flex gap-2">
                        <button
                            onClick={onClose}
                            className="rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700"
                        >
                            Cancel
                        </button>
                        <button
                            onClick={() => onConfirm([...selected])}
                            disabled={selected.size === 0}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {multiple ? 'Add selected' : 'Choose'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

function fmtSize(bytes: number): string {
    if (bytes >= 1e9) return `${(bytes / 1e9).toFixed(1)} GB`;
    if (bytes >= 1e6) return `${(bytes / 1e6).toFixed(0)} MB`;
    return `${Math.max(1, Math.round(bytes / 1e3))} KB`;
}

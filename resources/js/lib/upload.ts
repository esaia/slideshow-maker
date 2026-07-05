const CHUNK_SIZE = 16 * 1024 * 1024; // 16 MB — raw PUT bodies, no PHP form limits

/** Stream a dropped file to the server in chunks; resolves to its stored path. */
export async function uploadFile(file: File, onProgress: (fraction: number) => void): Promise<string> {
    const initRes = await fetch('/uploads/init', { method: 'POST' });
    if (!initRes.ok) throw new Error('upload init failed');
    const { id } = (await initRes.json()) as { id: string };

    let sent = 0;
    while (sent < file.size) {
        const chunk = file.slice(sent, sent + CHUNK_SIZE);
        const res = await fetch(`/uploads/${id}/chunk`, { method: 'PUT', body: chunk });
        if (!res.ok) throw new Error('chunk upload failed');
        sent += chunk.size;
        onProgress(Math.min(1, sent / file.size));
    }

    const finishRes = await fetch(`/uploads/${id}/finish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: file.name }),
    });
    if (!finishRes.ok) throw new Error('upload finish failed');

    return ((await finishRes.json()) as { path: string }).path;
}

const VIDEO_EXT = ['mp4', 'mov', 'm4v', 'avi', 'mkv', 'mts', 'm2ts', 'webm'];
const AUDIO_EXT = ['mp3', 'm4a', 'wav', 'aac', 'flac', 'ogg'];

export function filterDropped(files: FileList | File[], type: 'video' | 'audio'): File[] {
    const allowed = type === 'video' ? VIDEO_EXT : AUDIO_EXT;
    return [...files].filter((f) => allowed.includes(f.name.split('.').pop()?.toLowerCase() ?? ''));
}

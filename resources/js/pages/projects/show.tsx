import FileBrowser from '@/components/file-browser';
import TrimStudio from '@/components/trim-studio';
import Shell from '@/layouts/shell';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { STEP_LABELS, fmtTime, type ProjectData, type StepData } from './types';

const BUSY = ['pending', 'preparing', 'rendering'];

export default function Show({ project: initial }: { project: ProjectData }) {
    const [project, setProject] = useState(initial);
    // null = follow the status (done → result, ready → trim); user clicks override it
    const [viewOverride, setViewOverride] = useState<'trim' | 'result' | null>(null);
    const [musicPicker, setMusicPicker] = useState(false);
    const prevStatus = useRef(initial.status);
    useEffect(() => {
        setProject(initial);
        // keep the user's chosen view across prop refreshes (adding clips etc.);
        // only snap back to the default view when the status itself changes
        if (initial.status !== prevStatus.current) {
            prevStatus.current = initial.status;
            setViewOverride(null);
        }
    }, [initial]);

    // Poll while preparing or rendering
    useEffect(() => {
        if (!BUSY.includes(project.status)) return;
        const timer = setInterval(async () => {
            const res = await fetch(`/projects/${project.id}/status`);
            if (res.ok) {
                const fresh: ProjectData = await res.json();
                setProject(fresh);
                if (!BUSY.includes(fresh.status)) router.reload();
            }
        }, 2000);
        return () => clearInterval(timer);
    }, [project.id, project.status]);

    const hasRender = project.outputs.length > 0;
    const defaultView = project.status === 'done' ? 'result' : 'trim';
    const view = viewOverride ?? defaultView;
    const showTrim = ['ready', 'done'].includes(project.status) && view === 'trim';
    const showResult = ['ready', 'done'].includes(project.status) && view === 'result' && hasRender;

    return (
        <Shell>
            <Head title={project.name} />
            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">{project.name}</h1>
                    <p className="flex items-center gap-2 text-sm text-neutral-400">
                        {project.source_videos.length} videos · ♪ {basename(project.music_path)}
                        {['ready', 'done'].includes(project.status) && (
                            <button
                                onClick={() => setMusicPicker(true)}
                                className="rounded bg-neutral-800 px-2 py-0.5 text-xs text-neutral-200 hover:bg-neutral-700"
                            >
                                Change music
                            </button>
                        )}
                    </p>
                </div>
                <button
                    onClick={() => confirm('Delete this slideshow?') && router.delete(`/projects/${project.id}`)}
                    className="text-sm text-neutral-500 hover:text-red-400"
                >
                    Delete
                </button>
            </div>

            {project.error && project.status !== 'done' && (
                <div className="mb-6 rounded-lg border border-red-800 bg-red-950/50 p-4 text-sm text-red-300">
                    <strong>Something went wrong:</strong> {project.error}
                </div>
            )}

            {BUSY.includes(project.status) && (
                <Progress
                    steps={project.steps.filter((s) =>
                        project.status === 'rendering' ? ['plan', 'render'].includes(s.name) : true,
                    )}
                    title={project.status === 'rendering' ? 'Rendering your slideshow…' : 'Preparing your videos…'}
                />
            )}

            {showTrim && hasRender && (
                <button
                    onClick={() => setViewOverride('result')}
                    className="mb-4 rounded-md bg-neutral-800 px-4 py-2 text-sm hover:bg-neutral-700"
                >
                    ▶ View last rendered video
                </button>
            )}

            {showTrim && <TrimStudio project={project} />}

            {showResult && (
                <Result
                    project={project}
                    stale={project.status !== 'done'}
                    onEdit={() => setViewOverride('trim')}
                />
            )}

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
        </Shell>
    );
}

function Progress({ steps, title }: { steps: StepData[]; title: string }) {
    return (
        <div className="rounded-lg border border-neutral-800 p-6">
            <h2 className="mb-4 font-semibold">{title}</h2>
            <ul className="space-y-3">
                {steps.map((step) => (
                    <li key={step.name} className="flex items-center gap-3">
                        <StepIcon status={step.status} />
                        <span className={step.status === 'pending' ? 'text-neutral-500' : ''}>
                            {STEP_LABELS[step.name] ?? step.name}
                        </span>
                        {step.status === 'running' && (
                            <div className="ml-auto flex items-center gap-3">
                                {step.log && <span className="max-w-56 truncate text-xs text-neutral-500">{step.log}</span>}
                                <div className="h-1.5 w-32 overflow-hidden rounded bg-neutral-800">
                                    <div
                                        className="h-full bg-emerald-500 transition-all"
                                        style={{ width: `${step.progress}%` }}
                                    />
                                </div>
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function StepIcon({ status }: { status: StepData['status'] }) {
    if (status === 'done') return <span className="text-emerald-400">✓</span>;
    if (status === 'failed') return <span className="text-red-400">✕</span>;
    if (status === 'running')
        return <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-emerald-500 border-t-transparent" />;
    return <span className="inline-block h-3 w-3 rounded-full border border-neutral-700" />;
}

function Result({ project, stale, onEdit }: { project: ProjectData; stale: boolean; onEdit: () => void }) {
    const aspect = project.outputs[0];
    if (!aspect) return null;

    const used = project.segments.filter((s) => s.used_in_render);

    return (
        <div className="space-y-6">
            {stale && (
                <p className="mx-auto max-w-3xl rounded-lg border border-amber-800 bg-amber-950/40 p-3 text-sm text-amber-300">
                    This is your last render — clips or music changed since, so re-render to get the updated version.
                </p>
            )}
            <div className="mx-auto max-w-3xl">
                <div className="mb-2 flex items-center justify-between">
                    <h2 className="font-semibold">{aspect === 'landscape' ? 'Landscape 16:9' : 'Vertical 9:16'}</h2>
                    <div className="flex gap-4 text-sm">
                        <button onClick={onEdit} className="text-neutral-300 hover:text-white">
                            ✂️ Edit clips & re-render
                        </button>
                        <a
                            href={`/projects/${project.id}/output/${aspect}?download=1`}
                            className="text-emerald-400 hover:underline"
                        >
                            ↓ Download
                        </a>
                    </div>
                </div>
                <video
                    controls
                    src={`/projects/${project.id}/output/${aspect}?v=${Date.parse(project.updated_at)}`}
                    className={`w-full rounded-lg bg-black ${aspect === 'vertical' ? 'max-h-[75vh]' : ''}`}
                />
            </div>

            <div className="mx-auto max-w-3xl text-sm text-neutral-400">
                {used.length} clips in this cut:{' '}
                {used.map((s, i) => (
                    <span key={s.id}>
                        {i > 0 && ' · '}
                        {s.video} {fmtTime(s.start_s)}
                    </span>
                ))}
            </div>
        </div>
    );
}

function basename(path: string): string {
    return path.split('/').pop() ?? path;
}

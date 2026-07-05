import Shell from '@/layouts/shell';
import { Head, Link } from '@inertiajs/react';

interface ProjectListItem {
    id: number;
    name: string;
    status: string;
    created_at: string;
    source_videos_count: number;
}

const statusStyle: Record<string, string> = {
    pending: 'bg-neutral-700 text-neutral-200',
    preparing: 'bg-amber-600/20 text-amber-400',
    ready: 'bg-violet-600/20 text-violet-400',
    rendering: 'bg-sky-600/20 text-sky-400',
    done: 'bg-emerald-600/20 text-emerald-400',
    failed: 'bg-red-600/20 text-red-400',
};

export default function Index({ projects }: { projects: ProjectListItem[] }) {
    return (
        <Shell>
            <Head title="Slideshows" />

            <h1 className="mb-6 text-2xl font-bold">Your slideshows</h1>

            {projects.length === 0 ? (
                <div className="rounded-lg border border-dashed border-neutral-700 p-12 text-center text-neutral-400">
                    <p className="mb-4">No slideshows yet.</p>
                    <Link
                        href="/projects/create"
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500"
                    >
                        Create your first one
                    </Link>
                </div>
            ) : (
                <ul className="divide-y divide-neutral-800 rounded-lg border border-neutral-800">
                    {projects.map((p) => (
                        <li key={p.id}>
                            <Link
                                href={`/projects/${p.id}`}
                                className="flex items-center justify-between px-5 py-4 hover:bg-neutral-900"
                            >
                                <div>
                                    <div className="font-medium">{p.name}</div>
                                    <div className="text-sm text-neutral-400">
                                        {p.source_videos_count} video{p.source_videos_count === 1 ? '' : 's'} ·{' '}
                                        {new Date(p.created_at).toLocaleString()}
                                    </div>
                                </div>
                                <span
                                    className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${statusStyle[p.status] ?? statusStyle.pending}`}
                                >
                                    {p.status}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Shell>
    );
}

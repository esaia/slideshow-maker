import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export default function Shell({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-neutral-950 text-neutral-100">
            <header className="border-b border-neutral-800">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                    <Link href="/" className="text-lg font-semibold tracking-tight">
                        ⛰️ Trail Cuts
                    </Link>
                    <Link
                        href="/projects/create"
                        className="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium hover:bg-emerald-500"
                    >
                        New slideshow
                    </Link>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-6 py-8">{children}</main>
        </div>
    );
}

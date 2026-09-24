import { Head, Link, router } from '@inertiajs/react';
import type React from 'react';

type Row = { id: string; state?: string; caption?: string; title?: string; reason?: string; updated_at?: string };
type Action = 'publish' | 'restore' | 'reject' | 'remove' | 'strike' | 'delete';
type Props = { reels: Row[]; reports: Row[]; live: Row[]; appeals: Row[]; sanctions: Row[]; brokenLinks: Row[]; recentTakedowns: Row[]; freshAt: string };

export default function Moderation({ reels, reports, live, appeals, sanctions, brokenLinks, recentTakedowns, freshAt }: Props) {
    const decide = async (id: string, action: Action) => {
        const reason = window.prompt(`Reason for ${action} (at least 10 characters)`);
        if (!reason || reason.length < 10) return;
        if (action === 'delete' && !window.confirm('Permanently delete this reel? This cannot be undone.')) return;
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const response = await fetch(`/api/admin/v1/reels/${id}/moderation`, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ?? '' }, body: JSON.stringify({ action, reason_code: `operator_${action}`, reason }) });
        if (response.ok) router.reload();
        else window.alert((await response.json()).error?.message ?? 'The moderation action failed.');
    };

    return (
        <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
            <Head title="Reel moderation" />
            <div className="mx-auto max-w-[1600px] space-y-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Operate / Reel moderation</nav>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Moderation command queue</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500">Reels publish automatically after processing. Use these actions to unpublish, restore, strike, or delete.</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-slate-500">Updated {new Date(freshAt).toLocaleString()}</span>
                        <Link href="/admin/reels-live" className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-teal-500">Open reel directory</Link>
                    </div>
                </header>
                <div className="grid gap-6 xl:grid-cols-3">
                    <Queue title="Reels" empty="No reels in the queue." rows={reels} render={(row) => (
                        <article key={row.id} className="admin-card rounded-xl p-4">
                            <div className="flex items-center justify-between gap-3">
                                <code className="break-all text-xs text-slate-500">{row.id}</code>
                                <Badge value={row.state ?? 'unknown'} />
                            </div>
                            <p className="my-4 line-clamp-3 text-sm">{row.caption || 'No caption'}</p>
                            <div className="flex flex-wrap gap-2">
                                {row.state !== 'published' && row.state !== 'processing' ? <Button onClick={() => decide(row.id, row.state === 'removed' || row.state === 'rejected' ? 'restore' : 'publish')}>{row.state === 'removed' || row.state === 'rejected' ? 'Restore' : 'Publish'}</Button> : null}
                                {row.state === 'published' ? <Button onClick={() => decide(row.id, 'remove')}>Unpublish</Button> : <Button onClick={() => decide(row.id, 'reject')}>Reject</Button>}
                                <Button onClick={() => decide(row.id, 'remove')}>Remove</Button>
                                <Button onClick={() => decide(row.id, 'strike')}>Strike</Button>
                                <Button danger onClick={() => decide(row.id, 'delete')}>Delete</Button>
                                <Link href={`/admin/records/reels/${row.id}`} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-teal-500">Open</Link>
                            </div>
                        </article>
                    )} />
                    <Queue title="Open reports" empty="No unresolved reports." rows={reports} render={(row) => (
                        <article key={row.id} className="admin-card rounded-xl p-4">
                            <div className="flex items-center justify-between gap-3">
                                <code className="break-all text-xs text-slate-500">{row.id}</code>
                                <Badge value={row.state ?? 'open'} />
                            </div>
                            <p className="mt-4 text-sm">{row.reason}</p>
                        </article>
                    )} />
                    <Queue title="Live now" empty="No active live sessions." rows={live} render={(row) => (
                        <article key={row.id} className="rounded-xl border border-red-200 bg-red-50 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <strong className="text-sm">{row.title}</strong>
                                <Badge value="live" />
                            </div>
                            <code className="mt-4 block break-all text-xs text-slate-500">{row.id}</code>
                        </article>
                    )} />
                    <Queue title="Appeals" empty="No open appeals." rows={appeals} render={(row) => <Evidence key={row.id} row={row} />} />
                    <Queue title="Active sanctions" empty="No active sanctions." rows={sanctions} render={(row) => <Evidence key={row.id} row={row} />} />
                    <Queue title="Broken episode links" empty="No broken links." rows={brokenLinks} render={(row) => <Evidence key={row.id} row={row} />} />
                    <Queue title="Live takedown history" empty="No live takedowns." rows={recentTakedowns} render={(row) => <Evidence key={row.id} row={row} />} />
                </div>
            </div>
        </main>
    );
}

function Queue({ title, empty, rows, render }: { title: string; empty: string; rows: Row[]; render: (row: Row) => React.ReactNode }) {
    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-lg font-semibold">{title}</h2>
                <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs tabular-nums">{rows.length}</span>
            </div>
            {rows.length ? rows.map(render) : <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{empty}</div>}
        </section>
    );
}

function Badge({ value }: { value: string }) {
    return <span className="rounded-full border border-teal-200 bg-teal-50 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-teal-700">{value.replaceAll('_', ' ')}</span>;
}

function Button({ children, onClick, danger }: { children: React.ReactNode; onClick: () => void; danger?: boolean }) {
    return <button onClick={onClick} className={`rounded-lg border px-3 py-2 text-xs font-semibold ${danger ? 'border-red-300 text-red-700 hover:border-red-500' : 'border-slate-300 hover:border-teal-500 hover:text-teal-700'}`}>{children}</button>;
}

function Evidence({ row }: { row: Row }) {
    return (
        <article className="admin-card rounded-xl p-4">
            <div className="flex justify-between gap-2">
                <code className="break-all text-xs text-slate-500">{row.id}</code>
                <Badge value={row.state ?? 'recorded'} />
            </div>
            <p className="mt-3 text-sm">{row.title || row.caption || row.reason || 'Operational evidence record'}</p>
        </article>
    );
}

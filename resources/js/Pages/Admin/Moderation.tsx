import { Head, router } from '@inertiajs/react';
import type React from 'react';

type Row = { id: string; state?: string; caption?: string; title?: string; reason?: string; updated_at?: string };
type Action = 'publish' | 'restore' | 'reject' | 'remove' | 'strike' | 'delete';
type Props = { reels: Row[]; reports: Row[]; live: Row[]; appeals: Row[]; sanctions: Row[]; brokenLinks: Row[]; recentTakedowns: Row[]; freshAt: string };

export default function Moderation({ reels, reports, live, appeals, sanctions, brokenLinks, recentTakedowns, freshAt }: Props) {
    const decide = async (id: string, action: Action) => {
        const reason = window.prompt(`Reason for ${action}`);
        if (!reason || reason.length < 10) return;
        if (action === 'delete' && !window.confirm('Permanently delete this reel? This cannot be undone.')) return;
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const response = await fetch(`/api/admin/v1/reels/${id}/moderation`, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf ?? '' }, body: JSON.stringify({ action, reason_code: `operator_${action}`, reason }) });
        if (response.ok) router.reload();
        else window.alert((await response.json()).error?.message ?? 'The moderation action failed.');
    };

    return <div className="min-h-screen bg-[#090b10] text-slate-100">
        <Head title="Moderation queue" />
        <header className="border-b border-slate-800 bg-slate-950/80 px-6 py-5 backdrop-blur md:px-10">
            <div className="mx-auto flex max-w-7xl items-center justify-between gap-4">
                <div><a href="/admin" className="text-xs font-bold tracking-[.2em] text-teal-300">PELEVO OPS</a><h1 className="mt-2 text-2xl font-semibold">Moderation command queue</h1></div>
                <div className="rounded-full border border-slate-700 px-3 py-1 text-xs text-slate-400">Updated {new Date(freshAt).toLocaleString()}</div>
            </div>
        </header>
        <main className="mx-auto grid max-w-7xl gap-6 px-6 py-8 md:px-10 xl:grid-cols-3">
            <Queue title="Reels" empty="No reels in the queue." rows={reels} render={(row) => <article key={row.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex items-center justify-between gap-3"><code className="text-xs text-slate-400">{row.id}</code><Badge value={row.state ?? 'unknown'} /></div><p className="my-4 line-clamp-3 text-sm text-slate-200">{row.caption || 'No caption'}</p><div className="flex flex-wrap gap-2">{row.state !== 'published' && row.state !== 'processing' ? <Button onClick={() => decide(row.id, row.state === 'removed' || row.state === 'rejected' ? 'restore' : 'publish')}>{row.state === 'removed' || row.state === 'rejected' ? 'Restore' : 'Publish'}</Button> : null}{row.state === 'published' ? <Button onClick={() => decide(row.id, 'remove')}>Unpublish</Button> : <Button onClick={() => decide(row.id, 'reject')}>Reject</Button>}<Button onClick={() => decide(row.id, 'remove')}>Remove</Button><Button onClick={() => decide(row.id, 'strike')}>Strike</Button><Button danger onClick={() => decide(row.id, 'delete')}>Delete</Button></div></article>} />
            <Queue title="Open reports" empty="No unresolved reports." rows={reports} render={(row) => <article key={row.id} className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex items-center justify-between gap-3"><code className="text-xs text-slate-400">{row.id}</code><Badge value={row.state ?? 'open'} /></div><p className="mt-4 text-sm text-slate-200">{row.reason}</p></article>} />
            <Queue title="Live now" empty="No active live sessions." rows={live} render={(row) => <article key={row.id} className="rounded-xl border border-red-900/60 bg-red-950/20 p-4"><div className="flex items-center justify-between gap-3"><strong className="text-sm">{row.title}</strong><Badge value="live" /></div><code className="mt-4 block text-xs text-slate-400">{row.id}</code></article>} />
            <Queue title="Appeals" empty="No open appeals." rows={appeals} render={(row) => <Evidence key={row.id} row={row}/>} />
            <Queue title="Active sanctions" empty="No active sanctions." rows={sanctions} render={(row) => <Evidence key={row.id} row={row}/>} />
            <Queue title="Broken episode links" empty="No broken links." rows={brokenLinks} render={(row) => <Evidence key={row.id} row={row}/>} />
            <Queue title="Live takedown history" empty="No live takedowns." rows={recentTakedowns} render={(row) => <Evidence key={row.id} row={row}/>} />
        </main>
    </div>;
}

function Queue({ title, empty, rows, render }: { title: string; empty: string; rows: Row[]; render: (row: Row) => React.ReactNode }) {
    return <section className="space-y-3"><div className="flex items-center justify-between"><h2 className="font-semibold">{title}</h2><span className="rounded-full bg-slate-800 px-2 py-1 text-xs">{rows.length}</span></div>{rows.length ? rows.map(render) : <div className="rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-500">{empty}</div>}</section>;
}
function Badge({ value }: { value: string }) { return <span className="rounded-full bg-slate-800 px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-teal-300">{value.replace('_', ' ')}</span>; }
function Button({ children, onClick, danger }: { children: React.ReactNode; onClick: () => void; danger?: boolean }) { return <button onClick={onClick} className={`rounded-lg border px-3 py-2 text-xs font-semibold hover:border-teal-400 hover:text-teal-300 ${danger ? 'border-red-800 text-red-300 hover:border-red-400 hover:text-red-200' : 'border-slate-700 text-slate-200'}`}>{children}</button>; }
function Evidence({row}:{row:Row}){return <article className="rounded-xl border border-slate-800 bg-slate-900/70 p-4"><div className="flex justify-between gap-2"><code className="text-xs text-slate-400">{row.id}</code><Badge value={row.state??'recorded'}/></div><p className="mt-3 text-sm">{row.title||row.caption||row.reason||'Operational evidence record'}</p></article>}

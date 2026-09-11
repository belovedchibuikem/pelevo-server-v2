import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import RecordFinancialActions from '../../components/admin/RecordFinancialActions';

type RecordData = Record<string, string | number | null>;
type Section = { label: string; rows: RecordData[]; total: number; next?: string | null; previous?: string | null };
type Props = { entity: string; heading: string; parent: string; record: RecordData; sections: Section[]; mediaUrl: string | null; freshAt: string };
const entityKeys: Record<string, string> = { reel_id: 'reels', show_id: 'shows', episode_id: 'episodes', creator_profile_id: 'creators', ledger_transaction_id: 'transactions', reverses_id: 'transactions' };

export default function CanonicalDetail({ entity, heading, parent, record, sections, mediaUrl, freshAt }: Props) {
  const [active, setActive] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'Overview');
  const current = sections.find(section => section.label === active);
  const pageHref = (url: string) => { const target = new URL(url, window.location.origin); target.searchParams.set('tab', active); return target.pathname + target.search; };
  const change = (tab: string) => { setActive(tab); window.history.replaceState({}, '', `${window.location.pathname}?tab=${encodeURIComponent(tab)}`); };
  const financial = ['transactions', 'withdrawals', 'payouts'].includes(entity);
  return <main className="mx-auto max-w-[1600px] p-5 sm:p-8"><Head title={`${heading} · ${record.title ?? record.reference ?? record.id}`} />
    <nav className="text-sm text-teal-300"><Link href={parent}>Back to workspace</Link><span className="px-2 text-slate-500">/</span>{heading}</nav>
    <header className="my-6 flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-teal-300">{heading}</p><h1 className="mt-2 break-words text-3xl font-semibold">{record.title ?? record.display_name ?? record.caption ?? record.reference ?? heading}</h1><p className="mt-3 font-mono text-xs text-slate-400">{record.id}</p></div><div className="flex items-center gap-3"><span className="rounded-full border border-slate-700 bg-slate-900 px-3 py-1 text-xs uppercase text-teal-300">{record.state ?? record.status ?? record.availability ?? 'Recorded'}</span><button onClick={() => router.reload()} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Refresh</button></div></header>
    <p className="mb-5 text-xs text-slate-400">Updated {new Date(freshAt).toLocaleString()}{financial && ' · Immutable financial evidence; amounts retain their recorded currency or coin unit.'}</p>
    {financial && <RecordFinancialActions key={String(record.id)} entity={entity} record={record} reversed={Boolean(sections.find(section => section.label === 'Reversals')?.total)} />}
    {mediaUrl && <section className="mb-5 rounded-xl border border-slate-800 p-4"><h2 className="mb-3 font-semibold">Media preview</h2>{entity === 'reels' ? <video controls preload="none" src={mediaUrl} className="max-h-96 max-w-full rounded-lg"><track kind="captions" label="Captions unavailable" /></video> : <audio controls preload="none" src={mediaUrl} className="max-w-full" />}<p className="mt-2 text-xs text-slate-400">Playback starts only when you press play.</p></section>}
    {financial && <p className="mb-4 rounded-xl border border-amber-900 bg-amber-950/20 p-4 text-sm text-amber-200 md:hidden">Use a desktop or tablet to review ledger entries and approvals together before taking a financial action.</p>}
    <div className="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]"><nav aria-label="Record sections" className="flex gap-2 overflow-x-auto lg:block lg:space-y-1">{['Overview', ...sections.map(section => section.label)].map(tab => <button key={tab} aria-current={active === tab ? 'page' : undefined} onClick={() => change(tab)} className={`whitespace-nowrap rounded-lg px-4 py-3 text-left text-sm lg:block lg:w-full ${active === tab ? 'bg-teal-950 text-teal-200' : 'text-slate-400 hover:bg-slate-900'}`}>{tab}</button>)}</nav>
    <section className="min-w-0 rounded-2xl border border-slate-800 bg-slate-900/40 p-5 sm:p-6"><h2 className="text-lg font-semibold">{current?.label ?? 'Overview'}</h2>{!current ? <dl className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{Object.entries(record).map(([key, value]) => <div key={key} className="min-w-0 rounded-xl bg-slate-950/70 p-4"><dt className="text-xs capitalize text-slate-400">{key.replaceAll('_', ' ')}</dt><dd className="mt-2 break-words text-sm"><Cell column={key} value={value} /></dd></div>)}</dl> : current.rows.length === 0 ? <div className="py-12 text-center"><p className="font-medium">No {current.label.toLowerCase()} recorded</p><p className="mt-2 text-sm text-slate-400">This record has no matching evidence in this section.</p></div> : <><p className="my-3 text-xs text-slate-400">{current.total} records{current.total > current.rows.length && ` · showing ${current.rows.length} on this page`}</p><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>{Object.keys(current.rows[0]).map(key => <th key={key} scope="col" className="whitespace-nowrap border-b border-slate-700 px-3 py-3 text-xs capitalize text-slate-400">{key.replaceAll('_', ' ')}</th>)}</tr></thead><tbody className="divide-y divide-slate-800">{current.rows.map((row, index) => <tr key={String(row.id ?? index)} className="hover:bg-slate-800/30">{Object.entries(row).map(([key, value]) => <td key={key} className="max-w-sm px-3 py-3"><Cell column={key} value={value} /></td>)}</tr>)}</tbody></table></div></>}</section></div>
    {current && (current.next || current.previous) && <nav aria-label="Section pagination" className="mt-4 flex justify-end gap-3">{current.previous && <Link preserveScroll href={pageHref(current.previous)} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Previous evidence</Link>}{current.next && <Link preserveScroll href={pageHref(current.next)} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Next evidence</Link>}</nav>}
  </main>;
}
function Cell({ column, value }: { column: string; value: string | number | null }) {
  if (value === null || value === '') return <span className="text-slate-500">—</span>;
  const entity = entityKeys[column];
  if (entity) return <Link className="break-all font-mono text-xs text-teal-300 hover:underline" href={`/admin/records/${entity}/${value}`}>{value}</Link>;
  if (column === 'user_id') return <Link className="break-all font-mono text-xs text-teal-300 hover:underline" href={`/admin/users/${value}`}>{value}</Link>;
  if (column === 'body' || column === 'content') return <p className="max-h-96 min-w-64 overflow-auto whitespace-pre-wrap leading-7">{String(value)}</p>;
  return <span className={column === 'id' || column.includes('reference') ? 'break-all font-mono text-xs text-slate-300' : 'break-words'}>{String(value)}</span>;
}

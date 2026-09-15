import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AdminDataTable from '../../components/admin/AdminDataTable';
import WorkspaceTools from '../../components/admin/WorkspaceTools';
import GovernedWorkspaceAction from '../../components/admin/GovernedWorkspaceAction';

type WorkspaceView = { key: string; label: string; count: number | null };
type Records = { data: Array<Record<string, unknown>>; columns: string[]; current_page: number; last_page: number; total: number; prev_page_url?: string | null; next_page_url?: string | null; degraded: boolean };
type Props = { module: string; title: string; description: string; views: WorkspaceView[]; activeView: string; records: Records; filters: { q: string; state: string; date_from: string; date_to: string; sort: string; direction: 'asc' | 'desc'; per_page: number }; freshAt: string };

export default function ModuleWorkspace({ module, title, description, views, activeView, records, filters, freshAt }: Props) {
  const [refreshing, setRefreshing] = useState(false);
  const active = views.find(view => view.key === activeView) ?? views[0];
  const navigate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries()) as Record<string, string>;
    router.get(`/admin/${module}`, { view: activeView, ...data }, { preserveState: true, preserveScroll: true });
  };
  const refresh = () => {
    setRefreshing(true);
    router.reload({ onFinish: () => setRefreshing(false) });
  };

  return <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
    <Head title={title} />
    <div className="mx-auto max-w-[1600px]">
      <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-300">Operations / {title}</nav>
      <header className="mt-3 flex flex-wrap items-end justify-between gap-4">
        <div><h1 className="text-3xl font-semibold tracking-tight">{title}</h1><p className="mt-2 max-w-3xl text-sm text-slate-400">{description}</p></div>
        <div className="flex items-center gap-3"><span className="text-xs text-slate-500">Updated {new Date(freshAt).toLocaleString()}</span><button disabled={refreshing} onClick={refresh} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-500 disabled:opacity-50">{refreshing ? 'Refreshing…' : 'Refresh'}</button></div>
      </header>

      <section aria-label={`${title} sections`} className="mt-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {views.map(view => <Link preserveScroll className={`rounded-xl p-4 transition ${view.key === activeView ? 'admin-card-active' : 'admin-card'}`} href={`/admin/${module}?view=${view.key}`} key={view.key}>
          <span className="block text-sm font-medium">{view.label}</span><strong className="mt-3 block text-2xl tabular-nums">{view.count === null ? '—' : view.count.toLocaleString()}</strong><small className="text-slate-500">records</small>
        </Link>)}
      </section>
      <GovernedWorkspaceAction module={module} view={activeView} />
      {module === 'communications' && <Link href="/admin/communications/compose" className="mt-6 inline-block rounded-lg bg-teal-400 px-4 py-3 text-sm font-semibold text-slate-950">Open notification composer & delivery overview</Link>}
      {module === 'support' && <Link href="/admin/support?view=contact" className="mt-6 inline-block rounded-lg bg-teal-400 px-4 py-3 text-sm font-semibold text-slate-950">Open website contact messages</Link>}
      {module === 'settings' && <div className="mt-6 flex flex-wrap gap-3">
        <Link href="/admin/settings/integrations" className="inline-block rounded-lg bg-teal-400 px-4 py-3 text-sm font-semibold text-slate-950">Open API, SMTP and provider credentials</Link>
        <Link href="/admin/settings/configuration" className="inline-block rounded-lg border border-slate-300 px-4 py-3 text-sm font-semibold">Open versioned product configuration</Link>
      </div>}
      <WorkspaceTools key={`${module}:${activeView}`} module={module} filters={{ ...filters, view: activeView }} />

      <section className="admin-panel mt-7 overflow-hidden rounded-2xl">
        <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 p-5"><div><h2 className="font-semibold">{active.label}</h2><p className="mt-1 text-xs text-slate-500">Server-filtered operational records · {records.total.toLocaleString()} total</p></div></div>
        <form onSubmit={navigate} className="grid gap-3 border-b border-slate-800 p-4 md:grid-cols-2 xl:grid-cols-7">
          <input name="q" defaultValue={filters.q} aria-label="Search records" placeholder="Search references and names" className="min-w-0 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm outline-none focus:border-teal-500 xl:col-span-2" />
          <input name="state" defaultValue={filters.state} aria-label="Filter by state" placeholder="State or status" className="min-w-0 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm outline-none focus:border-teal-500" />
          {records.columns.includes('unit') && <label className="grid gap-1 text-[10px] uppercase tracking-wide text-slate-500">Monetary unit<input name="unit" defaultValue={(filters as typeof filters & { unit?: string }).unit ?? ''} aria-label="Monetary unit" placeholder="All units" maxLength={3} className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200" /></label>}
          <label className="grid gap-1 text-[10px] uppercase tracking-wide text-slate-500"><span>From</span><input name="date_from" defaultValue={filters.date_from} type="date" className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 outline-none focus:border-teal-500" /></label>
          <label className="grid gap-1 text-[10px] uppercase tracking-wide text-slate-500"><span>To</span><input name="date_to" defaultValue={filters.date_to} type="date" className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 outline-none focus:border-teal-500" /></label>
          <select name="sort" defaultValue={filters.sort} aria-label="Sort column" className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm outline-none focus:border-teal-500"><option value="">Newest activity</option>{records.columns.map(column => <option key={column} value={column}>{column.replaceAll('_', ' ')}</option>)}</select>
          <select name="direction" defaultValue={filters.direction} aria-label="Sort direction" className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm outline-none focus:border-teal-500"><option value="desc">Descending</option><option value="asc">Ascending</option></select>
          <select name="per_page" defaultValue={filters.per_page} aria-label="Rows per page" className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm outline-none focus:border-teal-500"><option value="25">25 rows</option><option value="50">50 rows</option><option value="100">100 rows</option></select>
          <button className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950">Apply</button>
          <Link className="rounded-lg border border-slate-700 px-4 py-2 text-sm" href={`/admin/${module}?view=${activeView}`}>Clear all</Link>
        </form>

        {records.degraded ? <div className="m-5 rounded-xl border border-amber-800/70 bg-amber-950/20 p-5 text-sm text-amber-200"><strong>Limited view</strong><p className="mt-1 text-amber-300/70">This data source has no safe operator columns available. Record counts remain live; sensitive payloads are not exposed.</p></div>
          : records.data.length === 0 ? <div className="p-12 text-center"><div className="mx-auto grid size-12 place-items-center rounded-full bg-slate-800 text-xl">0</div><h3 className="mt-4 font-semibold">{filters.q || filters.state ? 'No matching records' : 'No records yet'}</h3><p className="mt-2 text-sm text-slate-500">{filters.q || filters.state ? 'Clear the active filters to restore the complete view.' : 'This workspace will populate when the corresponding product activity occurs.'}</p>{(filters.q || filters.state) && <Link className="mt-4 inline-block text-sm font-semibold text-teal-300" href={`/admin/${module}?view=${activeView}`}>Reset filters</Link>}</div>
            : <AdminDataTable columns={records.columns} module={module} rows={records.data} view={activeView} />}
        <footer className="flex items-center justify-between border-t border-slate-800 px-5 py-4 text-xs text-slate-500"><span>Page {records.current_page} of {records.last_page}</span><div className="flex gap-2">{records.prev_page_url && <Link className="rounded border border-slate-700 px-3 py-2 text-slate-300" href={records.prev_page_url}>Previous</Link>}{records.next_page_url && <Link className="rounded border border-slate-700 px-3 py-2 text-slate-300" href={records.next_page_url}>Next</Link>}</div></footer>
      </section>
      <p className="mt-4 text-xs text-slate-600">Personally identifiable information and provider secrets are excluded from this overview. Authorized detail workflows disclose only the minimum required context and preserve audit evidence.</p>
    </div>
  </main>;
}

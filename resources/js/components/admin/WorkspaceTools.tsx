import { router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';

type Layout = { columns: string[]; compact: boolean };
type SavedView = { id: string; name: string; shared: boolean; owned: boolean; filters: Record<string, string | number> & { layout?: Layout } };
type Export = { id: string; state: string; row_count: number; expires_at: string };
export default function WorkspaceTools({ module, filters }: { module: string; filters: Record<string, string | number> }) {
  const [views, setViews] = useState<SavedView[]>([]);
  const [exports, setExports] = useState<Export[]>([]);
  const [mode, setMode] = useState<'save' | 'export' | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const endpoint = `/api/admin/v1/workspaces/${module}`;
  const load = async () => {
    try {
      const response = await fetch(`${endpoint}/tools`, { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error('Saved views and exports could not be loaded. Use Refresh to retry.');
      const body = await response.json(); setViews(body.data.views); setExports(body.data.exports);
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Connection interrupted. Refresh to retry.'); }
  };
  useEffect(() => { void load(); }, [module]);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setBusy(true); setMessage('');
    const data = new FormData(event.currentTarget);
    try {
      const response = await fetch(`${endpoint}/${mode === 'save' ? 'views' : 'exports'}`, {
        method: 'POST', headers: headers(), body: JSON.stringify(mode === 'save' ? { filters, layout: readLayout(module, String(filters.view)), name: data.get('name'), shared: data.get('shared') === 'on' } : { filters, reason: data.get('reason') }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body.error?.message ?? 'Review the form and try again.');
      setMessage(mode === 'save' ? 'View saved.' : `Export queued. Audit reference: ${body.data.audit_reference}`);
      setMode(null); await load();
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Connection interrupted. Your form has been preserved.'); }
    finally { setBusy(false); }
  };
  const remove = async (id: string) => {
    try {
      const response = await fetch(`${endpoint}/views/${id}`, { method: 'DELETE', headers: headers() });
      if (!response.ok) throw new Error('The view could not be removed. Refresh and try again.');
      await load();
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Connection interrupted.'); }
  };
  return <section aria-label="Saved views and exports" className="admin-panel mt-6 rounded-xl p-4">
    <div className="flex flex-wrap items-center gap-3">
      <label className="flex items-center gap-2 text-sm text-slate-300">Saved view<select aria-label="Apply saved view" value="" onChange={event => { const view = views.find(item => item.id === event.target.value); if (view) { const { layout, ...savedFilters } = view.filters; if (layout) { try { localStorage.setItem(`pelevo.admin.table.${module}.${savedFilters.view}`, JSON.stringify(layout)); window.dispatchEvent(new Event('pelevo-table-preferences')); } catch { setMessage('Browser storage is unavailable. Filters will still be applied.'); } } router.get(`/admin/${module}`, savedFilters); } }} className="max-w-64 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"><option value="">Choose a private or shared view</option>{views.map(view => <option key={view.id} value={view.id}>{view.name} · {view.shared ? 'Shared' : 'Private'}</option>)}</select></label>
      <button onClick={() => setMode('save')} className="rounded-lg border border-slate-700 px-3 py-2 text-sm">Save current filters</button>
      <button onClick={() => setMode('export')} className="rounded-lg border border-teal-700 px-3 py-2 text-sm text-teal-300">Export all matching records</button>
      <button onClick={() => void load()} className="rounded-lg border border-slate-700 px-3 py-2 text-sm">Refresh exports</button>
    </div>
    {mode && <form onSubmit={submit} className="mt-4 grid max-w-xl gap-3 rounded-xl border border-slate-700 p-4">
      <h3 className="font-semibold">{mode === 'save' ? 'Save this filtered view' : 'Request an audited export'}</h3>
      {mode === 'save' ? <><label className="grid gap-2 text-sm">View name<input autoFocus name="name" required maxLength={80} className="rounded-lg border border-slate-600 bg-slate-950 p-2" /></label><label className="flex gap-2 text-sm"><input type="checkbox" name="shared" />Share with administrators who can access this module</label></> : <><p className="text-sm text-slate-400">Includes all records matching the applied filters, beyond the current page. Private downloads expire after 24 hours. A background worker prepares the file.</p><label className="grid gap-2 text-sm">Business reason<textarea autoFocus name="reason" required minLength={10} maxLength={2000} className="rounded-lg border border-slate-600 bg-slate-950 p-2" /></label></>}
      <div className="flex gap-3"><button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50">{busy ? 'Saving…' : mode === 'save' ? 'Save view' : 'Queue export'}</button><button type="button" disabled={busy} onClick={() => setMode(null)} className="rounded-lg border border-slate-600 px-4 py-2">Cancel</button></div>
    </form>}
    {message && <p role="status" className="mt-3 break-words text-sm text-amber-200">{message}</p>}
    {exports.length > 0 && <details className="mt-4" open><summary className="cursor-pointer text-sm text-slate-300">Recent exports</summary><ul className="mt-2 divide-y divide-slate-800">{exports.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-xs"><span className="font-mono text-slate-400">{item.id}</span><span>{item.state} · {item.row_count} records</span>{item.state === 'completed' && new Date(item.expires_at) > new Date() ? <a href={`${endpoint}/exports/${item.id}`} className="rounded-lg border border-teal-700 px-3 py-2 text-teal-300">Download CSV</a> : <span className="text-slate-400">{new Date(item.expires_at) <= new Date() ? 'Expired' : 'Refresh to check progress'}</span>}</li>)}</ul></details>}
    {views.some(view => view.owned) && <details className="mt-3"><summary className="cursor-pointer text-xs text-slate-400">Manage my saved views</summary>{views.filter(view => view.owned).map(view => <div key={view.id} className="mt-2 flex items-center justify-between text-sm"><span>{view.name}</span><button onClick={() => void remove(view.id)} className="rounded border border-slate-700 px-3 py-1 text-rose-300">Remove saved view</button></div>)}</details>}
  </section>;
}

function headers() { return { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }; }
function readLayout(module: string, view: string): Layout | undefined { try { const value = JSON.parse(localStorage.getItem(`pelevo.admin.table.${module}.${view}`) ?? 'null'); return value && Array.isArray(value.columns) && value.columns.length ? { columns: value.columns, compact: Boolean(value.compact) } : undefined; } catch { return undefined; } }

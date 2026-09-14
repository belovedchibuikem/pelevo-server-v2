import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Row = {
  id: string;
  title: string;
  author?: string;
  status: string;
  feed_state?: string;
  last_success_at?: string;
  last_failure_at?: string;
  consecutive_failures?: number;
  last_error?: string;
};
type Page<T> = { data: T[]; current_page: number; last_page: number; total: number; prev_page_url?: string; next_page_url?: string };
type Item = { id: number | string; name?: string; slug?: string; title?: string; term?: string; synonym?: string; active?: boolean; published?: boolean; position: number };
type HomeModule = { id: string; key: string; title: string; subtitle?: string; kind: string; source: string; active: boolean; position: number; starts_at?: string; ends_at?: string };
type Props = { shows: Page<Row>; feedHealth: { state: string; total: number }[]; categories: Item[]; playlists: Item[]; homeModules: HomeModule[]; searchSynonyms: Item[]; zeroResults: { query: string; searches: number }[]; filters: Record<string, string>; freshAt: string };

const field = 'w-full min-w-0 rounded-lg border border-slate-300 bg-transparent px-3 py-2 text-sm outline-none focus:border-teal-500';

export default function Catalog(props: Props) {
  const nav = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    router.get('/admin/catalog', Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>, { preserveState: true });
  };
  const refresh = async (id: string) => {
    await mutate(`/api/admin/v1/catalog/shows/${id}/refresh`, 'POST', {});
    router.reload();
  };

  return (
    <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
      <Head title="Catalog operations" />
      <div className="mx-auto max-w-7xl space-y-8">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Operate / Catalog</nav>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Catalog and discovery</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-500">RSS health, Home merchandising, editorial programming and search relevance.</p>
          </div>
          <span className="text-xs text-slate-500">Updated {new Date(props.freshAt).toLocaleString()}</span>
        </header>

        <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {props.feedHealth.map(item => (
            <article key={item.state} className="admin-panel rounded-2xl p-4">
              <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{item.state}</p>
              <p className="mt-2 text-3xl font-semibold tabular-nums">{item.total}</p>
            </article>
          ))}
        </section>

        <section className="admin-panel rounded-2xl p-6">
          <h2 className="text-lg font-semibold">Show directory and RSS health</h2>
          <form onSubmit={nav} className="mt-4 flex flex-wrap gap-2">
            <input aria-label="Search shows" name="q" defaultValue={props.filters.q} placeholder="Search title or publisher" className={`${field} max-w-xs`} />
            <select aria-label="Feed state" name="feed_state" defaultValue={props.filters.feed_state ?? ''} className={`${field} max-w-48`}>
              <option value="">All feed states</option>
              <option>healthy</option>
              <option>failed</option>
              <option>pending</option>
            </select>
            <select aria-label="Sort shows" name="sort" defaultValue={props.filters.sort ?? 'updated_at'} className={`${field} max-w-48`}>
              <option value="updated_at">Recently updated</option>
              <option value="title">Title</option>
              <option value="created_at">Created</option>
            </select>
            <button className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950">Apply</button>
            <Link href="/admin/catalog" className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Clear all</Link>
          </form>
          <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-slate-500">
                <tr>
                  <th className="p-3 font-semibold">Show</th>
                  <th className="p-3 font-semibold">Catalog</th>
                  <th className="p-3 font-semibold">Feed</th>
                  <th className="p-3 font-semibold">Last success</th>
                  <th className="p-3 font-semibold">Action</th>
                </tr>
              </thead>
              <tbody>
                {props.shows.data.map(show => (
                  <tr key={show.id} className="border-t border-slate-200">
                    <td className="p-3">
                      <a className="font-semibold text-teal-700 hover:underline" href={`/admin/records/shows/${show.id}`}>{show.title}</a>
                      <small className="block text-slate-500">{show.author || 'Unknown publisher'}</small>
                    </td>
                    <td className="p-3">{show.status}</td>
                    <td className="p-3">
                      <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{show.feed_state || 'unconfigured'}</span>
                      {show.last_error && <small className="mt-1 block max-w-xs truncate text-red-700" title={show.last_error}>{show.last_error}</small>}
                    </td>
                    <td className="p-3 text-slate-500">{show.last_success_at ? new Date(show.last_success_at).toLocaleString() : 'Never'}</td>
                    <td className="p-3">
                      <button type="button" onClick={() => void refresh(show.id)} className="rounded-lg border border-teal-700 px-2 py-1 text-xs font-semibold text-teal-800">Live refresh</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!props.shows.data.length && <p className="p-8 text-center text-slate-500">No shows match these filters. Clear the filters to restore the directory.</p>}
          </div>
          <div className="mt-3 flex justify-between text-sm text-slate-500">
            <span>{props.shows.total} shows</span>
            <div className="space-x-3">
              {props.shows.prev_page_url && <a className="text-teal-700" href={props.shows.prev_page_url}>Previous</a>}
              {props.shows.next_page_url && <a className="text-teal-700" href={props.shows.next_page_url}>Next</a>}
            </div>
          </div>
        </section>

        <MergeDesk />
        <HomeMerchandising modules={props.homeModules} />

        <section className="grid gap-6 lg:grid-cols-3">
          <SidePanel title="Categories and tags">
            {props.categories.map(item => <Line key={item.id} label={item.name || ''} value={item.active ? 'Active' : 'Hidden'} />)}
          </SidePanel>
          <SidePanel title="Editorial playlists">
            {props.playlists.map(item => <Line key={item.id} label={item.title || ''} value={item.published ? 'Published' : 'Draft'} />)}
          </SidePanel>
          <SidePanel title="Search relevance">
            <h3 className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Synonyms</h3>
            {props.searchSynonyms.map(item => <Line key={item.id} label={item.term || ''} value={item.synonym || ''} />)}
            <h3 className="mb-2 mt-4 text-xs font-bold uppercase tracking-wide text-slate-500">Zero-result searches</h3>
            {props.zeroResults.map(item => <Line key={item.query} label={item.query} value={String(item.searches)} />)}
          </SidePanel>
        </section>
      </div>
    </main>
  );
}

function SidePanel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="admin-panel rounded-2xl p-5">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {children || <p className="text-sm text-slate-500">No records before configuration.</p>}
    </section>
  );
}

function Line({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3 border-t border-slate-200 py-2 text-sm">
      <span className="min-w-0 truncate">{label}</span>
      <span className="shrink-0 text-slate-500">{value}</span>
    </div>
  );
}

function MergeDesk() {
  const [survivor, setSurvivor] = useState('');
  const [duplicate, setDuplicate] = useState('');
  const [preview, setPreview] = useState<Record<string, unknown> | null>(null);
  const inspect = async () => {
    try {
      setPreview((await mutate('/api/admin/v1/catalog/merges/preview', 'POST', { survivor_show_id: survivor, duplicate_show_id: duplicate })).data);
    } catch (error) {
      window.alert(String(error));
    }
  };
  const execute = async () => {
    const reason = window.prompt('Reason for this governed merge');
    if (!reason || reason.length < 10) return;
    try {
      const result = await mutate('/api/admin/v1/catalog/merges', 'POST', { survivor_show_id: survivor, duplicate_show_id: duplicate, confirmation: `MERGE ${duplicate}`, reason, idempotency_key: crypto.randomUUID() });
      window.alert(`Merge queued: ${result.data.merge_id}`);
    } catch (error) {
      window.alert(String(error));
    }
  };

  return (
    <section className="admin-panel rounded-2xl p-6">
      <h2 className="text-lg font-semibold">Duplicate merge</h2>
      <p className="mt-1 text-sm text-slate-500">Preview a survivor/duplicate pair, then execute with a recorded reason. This action requires a fresh MFA confirmation.</p>
      <div className="mt-4 grid gap-3 md:grid-cols-[1fr_1fr_auto_auto]">
        <input value={survivor} onChange={event => setSurvivor(event.target.value)} placeholder="Survivor show ID" className={field} />
        <input value={duplicate} onChange={event => setDuplicate(event.target.value)} placeholder="Duplicate show ID" className={field} />
        <button type="button" onClick={() => void inspect()} className="rounded-lg border border-teal-700 px-4 py-2 text-sm font-semibold text-teal-800">Preview</button>
        <button type="button" disabled={!preview} onClick={() => void execute()} className="rounded-lg bg-amber-400 px-4 py-2 text-sm font-bold text-slate-950 disabled:opacity-40">Merge with MFA</button>
      </div>
      {preview && <pre className="mt-4 max-h-56 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs text-slate-600">{JSON.stringify(preview, null, 2)}</pre>}
    </section>
  );
}

function HomeMerchandising({ modules }: { modules: HomeModule[] }) {
  return (
    <section className="admin-panel rounded-2xl p-6">
      <header className="max-w-3xl">
        <h2 className="text-lg font-semibold">Home merchandising</h2>
        <p className="mt-1 text-sm text-slate-500">Set order, titles, schedule and publish state for each Home rail. Source ranking logic stays system-owned and cannot be edited here.</p>
      </header>
      {modules.length === 0 ? (
        <p className="mt-6 text-sm text-slate-500">No Home rails are configured yet.</p>
      ) : (
        <div className="mt-5 grid gap-4">
          {modules.map(module => <HomeModuleCard key={module.id} module={module} />)}
        </div>
      )}
    </section>
  );
}

function HomeModuleCard({ module }: { module: HomeModule }) {
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);
  const [needsStepUp, setNeedsStepUp] = useState(false);

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    setNeedsStepUp(false);
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      const response = await send('PUT', `/api/admin/v1/catalog/home-modules/${module.id}`, {
        title: data.title,
        subtitle: data.subtitle || null,
        position: Number(data.position),
        active: data.active === 'on',
        starts_at: data.starts_at || null,
        ends_at: data.ends_at || null,
      });
      const body = await response.json();
      if (response.status === 403) {
        setNeedsStepUp(true);
        setMessage({ ok: false, text: body.error?.message || 'Fresh MFA is required before publishing Home rails.' });
        return;
      }
      if (!response.ok) {
        setMessage({ ok: false, text: body.error?.message || 'This rail could not be saved.' });
        return;
      }
      setMessage({ ok: true, text: 'Rail saved.' });
      router.reload({ only: ['homeModules', 'freshAt'] });
    } catch {
      setMessage({ ok: false, text: 'Connection interrupted. Your edits are still on this page.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={event => void save(event)} className="admin-card rounded-2xl p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="text-base font-semibold">{module.title}</h3>
          <p className="mt-1 flex flex-wrap gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{module.key.replaceAll('_', ' ')}</span>
            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">{module.kind.replaceAll('_', ' ')}</span>
            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1">source {module.source.replaceAll('_', ' ')}</span>
          </p>
        </div>
        <label className="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm">
          <input name="active" type="checkbox" defaultChecked={module.active} className="size-4 accent-teal-600" />
          Active
        </label>
      </div>

      <div className="mt-5 grid gap-4 md:grid-cols-6">
        <label className="grid gap-1.5 text-xs font-semibold text-slate-500 md:col-span-1">
          Position
          <input name="position" type="number" min={0} max={1000} defaultValue={module.position} required className={field} />
        </label>
        <label className="grid gap-1.5 text-xs font-semibold text-slate-500 md:col-span-5">
          Title
          <input name="title" required maxLength={150} defaultValue={module.title} className={field} />
        </label>
        <label className="grid gap-1.5 text-xs font-semibold text-slate-500 md:col-span-6">
          Subtitle
          <input name="subtitle" maxLength={250} defaultValue={module.subtitle} className={field} />
        </label>
        <label className="grid gap-1.5 text-xs font-semibold text-slate-500 md:col-span-3">
          Starts at
          <input name="starts_at" type="datetime-local" defaultValue={module.starts_at?.slice(0, 16)} className={field} />
        </label>
        <label className="grid gap-1.5 text-xs font-semibold text-slate-500 md:col-span-3">
          Ends at
          <input name="ends_at" type="datetime-local" defaultValue={module.ends_at?.slice(0, 16)} className={field} />
        </label>
      </div>

      {message && (
        <p role="status" className={`mt-4 rounded-lg px-3 py-2 text-sm ${message.ok ? 'bg-teal-50 text-teal-900' : 'bg-amber-50 text-amber-900'}`}>
          {message.text}
        </p>
      )}
      {needsStepUp && (
        <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="mt-2 inline-block text-sm text-teal-700">
          Confirm MFA in a new tab, then save again →
        </a>
      )}

      <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
        <p className="text-xs text-slate-500">Leave dates empty to keep the rail always available.</p>
        <button disabled={saving} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950 disabled:opacity-50">
          {saving ? 'Saving…' : 'Save rail'}
        </button>
      </div>
    </form>
  );
}

async function send(method: string, url: string, body: object) {
  return fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(body),
  });
}

async function mutate(url: string, method: string, body: object) {
  const response = await send(method, url, body);
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error?.message ?? 'Request failed');
  return payload;
}

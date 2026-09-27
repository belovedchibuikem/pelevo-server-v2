import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type ShowRow = {
  id: string;
  title: string;
  author?: string;
  status: string;
  earn_enabled: boolean | number;
  earn_category_id?: number | null;
  earn_position: number;
  niche?: string | null;
};
type Page<T> = { data: T[]; total: number; prev_page_url?: string; next_page_url?: string };
type Niche = { id: number; name: string };
type Props = {
  shows: Page<ShowRow>;
  categories: Niche[];
  counts: { earning: number; ordinary: number };
  filters: { q: string; listing: 'earning' | 'ordinary' | 'all'; niche: string };
  freshAt: string;
};

const field = 'w-full min-w-0 rounded-lg border border-slate-300 bg-transparent px-3 py-2 text-sm outline-none focus:border-teal-500';

export default function EarnCatalog(props: Props) {
  const search = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>;
    router.get('/admin/earn-podcasts', { ...data, listing: props.filters.listing }, { preserveState: true });
  };

  return (
    <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
      <Head title="Earn podcasts" />
      <div className="mx-auto max-w-7xl space-y-8">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Operate / Earn podcasts</nav>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Earn podcasts</h1>
            <p className="mt-2 max-w-3xl text-sm text-slate-500">Mark a show to place it on the Earn screen. Remove it to send it back to ordinary podcasts. A niche groups it with similar shows. Leave the niche empty and the app shuffles it with the other unmarked shows. A lower position appears first inside that niche.</p>
          </div>
          <span className="text-xs text-slate-500">Updated {new Date(props.freshAt).toLocaleString()}</span>
        </header>

        <section className="grid gap-3 sm:grid-cols-2">
          <article className="admin-panel rounded-2xl p-4">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Earning</p>
            <p className="mt-2 text-3xl font-semibold tabular-nums">{props.counts.earning}</p>
          </article>
          <article className="admin-panel rounded-2xl p-4">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Ordinary</p>
            <p className="mt-2 text-3xl font-semibold tabular-nums">{props.counts.ordinary}</p>
          </article>
        </section>

        <section className="admin-panel rounded-2xl p-6">
          <div className="flex flex-wrap gap-2">
            {([
              ['earning', `Earning podcasts (${props.counts.earning})`],
              ['ordinary', `Ordinary podcasts (${props.counts.ordinary})`],
              ['all', 'All podcasts'],
            ] as const).map(([id, label]) => (
              <Link
                key={id}
                href={`/admin/earn-podcasts?listing=${id}${props.filters.q ? `&q=${encodeURIComponent(props.filters.q)}` : ''}`}
                className={`rounded-full px-3 py-1.5 text-sm font-semibold ${props.filters.listing === id ? 'bg-teal-400 text-slate-950' : 'border border-slate-300'}`}
              >
                {label}
              </Link>
            ))}
          </div>
          <form onSubmit={search} className="mt-4 flex flex-wrap gap-2">
            <input aria-label="Search podcasts" name="q" defaultValue={props.filters.q} placeholder="Search title or publisher" className={`${field} max-w-xs`} />
            <select aria-label="Niche" name="niche" defaultValue={props.filters.niche} className={`${field} max-w-56`}>
              <option value="">All niches</option>
              {props.categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
            </select>
            <button className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950">Apply</button>
            <Link href={`/admin/earn-podcasts?listing=${props.filters.listing}`} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Clear</Link>
          </form>

          <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-slate-500">
                <tr>
                  <th className="p-3 font-semibold">Podcast</th>
                  <th className="p-3 font-semibold">Niche</th>
                  <th className="p-3 font-semibold">Position</th>
                  <th className="p-3 font-semibold">Listing</th>
                  <th className="p-3 font-semibold">Action</th>
                </tr>
              </thead>
              <tbody>
                {props.shows.data.map(show => (
                  <EarnRow key={show.id} show={show} categories={props.categories} />
                ))}
              </tbody>
            </table>
            {!props.shows.data.length && (
              <p className="p-8 text-center text-slate-500">
                {props.filters.listing === 'earning' ? 'No earning podcasts yet. Switch to Ordinary podcasts and mark one.' : 'No podcasts match this filter.'}
              </p>
            )}
          </div>
          <div className="mt-3 flex justify-between text-sm text-slate-500">
            <span>{props.shows.total} podcasts</span>
            <div className="space-x-3">
              {props.shows.prev_page_url && <a className="text-teal-700" href={props.shows.prev_page_url}>Previous</a>}
              {props.shows.next_page_url && <a className="text-teal-700" href={props.shows.next_page_url}>Next</a>}
            </div>
          </div>
        </section>
      </div>
    </main>
  );
}

function EarnRow({ show, categories }: { show: ShowRow; categories: Niche[] }) {
  const earning = Boolean(show.earn_enabled);
  const [niche, setNiche] = useState(show.earn_category_id ? String(show.earn_category_id) : '');
  const [position, setPosition] = useState(String(show.earn_position ?? 0));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const save = async (enabled: boolean) => {
    setBusy(true);
    setError('');
    try {
      await mutate(`/api/admin/v1/catalog/shows/${show.id}/earn`, {
        earn_enabled: enabled,
        earn_category_id: niche === '' ? null : Number(niche),
        earn_position: Number(position) || 0,
      });
      router.reload();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Could not save this podcast.');
      setBusy(false);
    }
  };

  return (
    <tr className="border-t border-slate-200 align-top">
      <td className="p-3">
        <a className="font-semibold text-teal-700 hover:underline" href={`/admin/records/shows/${show.id}`}>{show.title}</a>
        <small className="block text-slate-500">{show.author || 'Unknown publisher'}</small>
        {error && <small className="mt-1 block text-red-700">{error}</small>}
      </td>
      <td className="p-3">
        <select aria-label={`Niche for ${show.title}`} value={niche} onChange={event => setNiche(event.target.value)} className={`${field} max-w-48`}>
          <option value="">No niche · shuffled</option>
          {categories.map(category => <option key={category.id} value={category.id}>{category.name}</option>)}
        </select>
        {show.niche && <small className="mt-1 block text-slate-500">{earning ? `Grouped as ${show.niche}` : `Saved niche ${show.niche}`}</small>}
      </td>
      <td className="p-3">
        <input aria-label={`Position for ${show.title}`} type="number" min={0} max={10000} value={position} onChange={event => setPosition(event.target.value)} className={`${field} w-24`} />
      </td>
      <td className="p-3">
        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${earning ? 'bg-teal-100 text-teal-900' : 'bg-slate-100 text-slate-600'}`}>{earning ? 'Earning' : 'Ordinary'}</span>
      </td>
      <td className="p-3 space-y-2">
        <button type="button" disabled={busy} onClick={() => void save(true)} className="block rounded-lg bg-teal-400 px-2 py-1 text-xs font-bold text-slate-950 disabled:opacity-50">{earning ? 'Save arrangement' : 'Mark for Earn'}</button>
        {earning && <button type="button" disabled={busy} onClick={() => void save(false)} className="block rounded-lg border border-slate-400 px-2 py-1 text-xs font-semibold disabled:opacity-50">Remove from Earn</button>}
      </td>
    </tr>
  );
}

async function mutate(url: string, body: object) {
  const response = await fetch(url, {
    method: 'PUT',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    },
    body: JSON.stringify(body),
  });
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error?.message ?? 'Request failed');
  return payload;
}

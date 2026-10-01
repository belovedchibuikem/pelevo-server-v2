import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type ReactNode } from 'react';

import { useFocusScope } from '../../components/admin/useFocusScope';

type Option = { value: string; label: string };
type Application = {
  id: string;
  name: string;
  show_name: string;
  show_url: string;
  email: string;
  social_handle: string | null;
  publish_frequency: string | null;
  notes: string | null;
  state: string;
  admin_note: string | null;
  reviewed_at: string | null;
  submission_count: number;
  created_at: string;
  updated_at: string;
};
type Filters = { q: string; state: string; frequency: string; date_from: string; date_to: string; direction: string; per_page: number };
type Props = {
  applications: { data: Application[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null; prev_page_url: string | null; next_page_url: string | null };
  counts: Record<string, number>;
  total: number;
  filters: Filters;
  states: Option[];
  frequencies: Option[];
  freshAt: string;
};

const field = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-500';

export default function FoundingCreators({ applications, counts, total, filters, states, frequencies, freshAt }: Props) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const selected = applications.data.find((row) => row.id === selectedId) ?? null;
  const stateLabel = (value: string) => states.find((state) => state.value === value)?.label ?? value;
  const frequencyLabel = (value: string | null) => (value ? frequencies.find((item) => item.value === value)?.label ?? value : '—');

  const visit = (next: Partial<Filters>) => {
    const params = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, value]) => value !== '' && value !== null));
    router.get('/admin/founding-creators', params, { preserveState: true, preserveScroll: true, replace: true });
  };

  const exportParams = new URLSearchParams(
    Object.entries(filters)
      .filter(([key, value]) => value !== '' && key !== 'per_page')
      .map(([key, value]) => [key, String(value)]),
  ).toString();

  const submitFilters = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>;
    visit({ q: data.q ?? '', frequency: data.frequency ?? '', date_from: data.date_from ?? '', date_to: data.date_to ?? '' });
  };

  const hasFilters = Boolean(filters.q || filters.state || filters.frequency || filters.date_from || filters.date_to);

  return (
    <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
      <Head title="Founding creators" />
      <div className="mx-auto max-w-[1280px]">
        <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">
          <Link href="/admin/creators">Creators & Claims</Link> / Founding creators
        </nav>

        <header className="mt-3 flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="text-3xl font-semibold tracking-tight text-slate-900">Founding creators</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-500">
              Sign-ups from the public founding creator form. Review each show, record a decision, and export the list for outreach.
            </p>
            <p className="mt-1 text-xs text-slate-400">Last refreshed {new Date(freshAt).toLocaleString()}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <a
              href="/founding-creators"
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:border-teal-400"
            >
              View public form
            </a>
            <a
              href={`/api/admin/v1/founding-creators/export${exportParams ? `?${exportParams}` : ''}`}
              className="inline-flex items-center rounded-lg bg-teal-400 px-4 py-2.5 text-sm font-bold text-slate-950 hover:bg-teal-300"
            >
              Export CSV{hasFilters ? ` (${applications.total})` : ''}
            </a>
          </div>
        </header>

        <div className="mt-6 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Filter by status">
          <StateTab active={!filters.state} label="All" count={total} onClick={() => visit({ state: '' })} />
          {states.map((state) => (
            <StateTab key={state.value} active={filters.state === state.value} label={state.label} count={counts[state.value] ?? 0} onClick={() => visit({ state: state.value })} />
          ))}
        </div>

        <form onSubmit={submitFilters} className="admin-panel mt-4 grid gap-3 rounded-2xl p-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]" key={JSON.stringify(filters)}>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Search
            <input name="q" defaultValue={filters.q} placeholder="Name, show, email, handle or link" className={field} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Publishing
            <select name="frequency" defaultValue={filters.frequency} className={field}>
              <option value="">Any frequency</option>
              {frequencies.map((item) => (
                <option key={item.value} value={item.value}>{item.label}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Submitted from
            <input type="date" name="date_from" defaultValue={filters.date_from} className={field} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Submitted to
            <input type="date" name="date_to" defaultValue={filters.date_to} className={field} />
          </label>
          <div className="flex items-end gap-2">
            <button className="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Apply</button>
            {hasFilters ? (
              <button type="button" onClick={() => router.get('/admin/founding-creators')} className="rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold text-slate-600 hover:border-slate-400">
                Reset
              </button>
            ) : null}
          </div>
        </form>

        <section className="admin-panel mt-4 overflow-hidden rounded-2xl">
          {applications.data.length ? (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[860px] text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-5 py-3">Creator</th>
                    <th className="px-5 py-3">Show</th>
                    <th className="px-5 py-3">Handle</th>
                    <th className="px-5 py-3">Publishing</th>
                    <th className="px-5 py-3">
                      <button type="button" onClick={() => visit({ direction: filters.direction === 'desc' ? 'asc' : 'desc' })} className="uppercase">
                        Submitted {filters.direction === 'desc' ? '↓' : '↑'}
                      </button>
                    </th>
                    <th className="px-5 py-3">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {applications.data.map((row) => (
                    <tr
                      key={row.id}
                      tabIndex={0}
                      onClick={() => setSelectedId(row.id)}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                          event.preventDefault();
                          setSelectedId(row.id);
                        }
                      }}
                      className={`cursor-pointer align-top outline-none hover:bg-slate-50 focus-visible:bg-teal-50 ${selectedId === row.id ? 'bg-teal-50' : ''}`}
                    >
                      <td className="px-5 py-4">
                        <p className="font-semibold text-slate-900">{row.name}</p>
                        <p className="mt-0.5 break-all text-xs text-slate-500">{row.email}</p>
                      </td>
                      <td className="px-5 py-4">
                        <p className="font-medium text-slate-900">{row.show_name}</p>
                        <p className="mt-0.5 max-w-[260px] truncate text-xs text-slate-500">{prettyUrl(row.show_url)}</p>
                      </td>
                      <td className="px-5 py-4 text-slate-700">{row.social_handle || '—'}</td>
                      <td className="px-5 py-4 text-slate-700">{frequencyLabel(row.publish_frequency)}</td>
                      <td className="px-5 py-4 whitespace-nowrap text-slate-600">
                        {new Date(row.created_at).toLocaleDateString()}
                        {row.submission_count > 1 ? <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">×{row.submission_count}</span> : null}
                      </td>
                      <td className="px-5 py-4"><StatusChip value={row.state} label={stateLabel(row.state)} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="px-6 py-16 text-center">
              <p className="text-sm font-medium text-slate-700">{hasFilters ? 'No sign-ups match these filters' : 'No founding creator sign-ups yet'}</p>
              <p className="mt-1 text-sm text-slate-500">
                {hasFilters ? 'Try clearing a filter or widening the date range.' : 'Share the public form at /founding-creators to start collecting shows.'}
              </p>
            </div>
          )}
          {applications.total > 0 ? (
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm text-slate-600">
              <span>
                Showing {applications.from}–{applications.to} of {applications.total}
              </span>
              <div className="flex items-center gap-2">
                <select aria-label="Rows per page" value={filters.per_page} onChange={(event) => visit({ per_page: Number(event.target.value) })} className="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm">
                  {[25, 50, 100].map((size) => <option key={size} value={size}>{size} / page</option>)}
                </select>
                <PageLink href={applications.prev_page_url} label="Previous" />
                <span className="text-xs text-slate-500">Page {applications.current_page} of {applications.last_page}</span>
                <PageLink href={applications.next_page_url} label="Next" />
              </div>
            </div>
          ) : null}
        </section>
      </div>

      {selected ? (
        <ApplicationDrawer
          key={selected.id}
          application={selected}
          states={states}
          frequencyLabel={frequencyLabel(selected.publish_frequency)}
          stateLabel={stateLabel(selected.state)}
          onClose={() => setSelectedId(null)}
        />
      ) : null}
    </main>
  );
}

function ApplicationDrawer({ application, states, frequencyLabel, stateLabel, onClose }: { application: Application; states: Option[]; frequencyLabel: string; stateLabel: string; onClose: () => void }) {
  const panelRef = useRef<HTMLElement>(null);
  const returnRef = useRef<HTMLElement | null>(document.activeElement instanceof HTMLElement ? document.activeElement : null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const [copied, setCopied] = useState(false);
  useFocusScope(true, panelRef, onClose, returnRef);

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setResult(null);
    try {
      const response = await fetch(`/api/admin/v1/founding-creators/${application.id}`, {
        method: 'PUT',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))),
      });
      const body = await response.json();
      if (!response.ok) {
        throw new Error(Object.values(body.error?.fields ?? {}).flat().join(' ') || body.error?.message || 'Save failed.');
      }
      setResult({ tone: 'ok', text: 'Saved.' });
      router.reload({ only: ['applications', 'counts', 'total', 'freshAt'] });
    } catch (error) {
      setResult({ tone: 'error', text: error instanceof Error ? error.message : 'Connection interrupted. Try again.' });
    } finally {
      setBusy(false);
    }
  };

  const copyEmail = async () => {
    try {
      await navigator.clipboard.writeText(application.email);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  };

  const handleUrl = socialUrl(application.social_handle);

  return (
    <div className="fixed inset-0 z-40 flex justify-end bg-slate-900/40" onMouseDown={onClose}>
      <aside
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-label={`Founding creator: ${application.show_name}`}
        onMouseDown={(event) => event.stopPropagation()}
        className="admin-panel flex h-full w-full max-w-lg flex-col overflow-y-auto rounded-none border-l border-slate-200 bg-white shadow-2xl outline-none"
      >
        <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
          <div className="min-w-0">
            <StatusChip value={application.state} label={stateLabel} />
            <h2 className="mt-3 text-xl font-semibold text-slate-900">{application.show_name}</h2>
            <p className="mt-1 text-sm text-slate-500">Submitted {new Date(application.created_at).toLocaleString()}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="rounded-lg border border-slate-200 px-2.5 py-1 text-slate-500 hover:text-slate-900">✕</button>
        </div>

        <div className="space-y-6 px-6 py-6">
          <dl className="grid gap-4 text-sm">
            <Detail label="Name">{application.name}</Detail>
            <Detail label="Email">
              <a href={`mailto:${application.email}`} className="break-all font-medium text-teal-700 hover:underline">{application.email}</a>
            </Detail>
            <Detail label="Show link">
              <a href={application.show_url} target="_blank" rel="noreferrer noopener" className="break-all font-medium text-teal-700 hover:underline">{application.show_url}</a>
            </Detail>
            <Detail label="Instagram or X">
              {application.social_handle ? (
                handleUrl ? <a href={handleUrl} target="_blank" rel="noreferrer noopener" className="font-medium text-teal-700 hover:underline">{application.social_handle}</a> : application.social_handle
              ) : <span className="text-slate-400">Not provided</span>}
            </Detail>
            <Detail label="Publishing">{frequencyLabel}</Detail>
            {application.submission_count > 1 ? <Detail label="Submissions">{application.submission_count} (latest {new Date(application.updated_at).toLocaleString()})</Detail> : null}
          </dl>

          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Anything we should know</p>
            {application.notes ? (
              <blockquote className="mt-2 whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-800">{application.notes}</blockquote>
            ) : (
              <p className="mt-2 text-sm text-slate-400">Nothing added.</p>
            )}
          </div>

          <div className="flex flex-wrap gap-2">
            <a href={`mailto:${application.email}?subject=${encodeURIComponent(`Your Pelevo founding creator spot — ${application.show_name}`)}`} className="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">
              Email creator
            </a>
            <button type="button" onClick={() => void copyEmail()} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-teal-400">
              {copied ? 'Email copied' : 'Copy email'}
            </button>
            <a href={application.show_url} target="_blank" rel="noreferrer noopener" className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-teal-400">
              Open show
            </a>
          </div>

          <form onSubmit={save} className="grid gap-4 rounded-2xl border border-slate-200 p-5">
            <div>
              <h3 className="font-semibold text-slate-900">Review</h3>
              <p className="mt-1 text-xs text-slate-500">Internal only — the creator never sees this.</p>
            </div>
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Status
              <select name="state" defaultValue={application.state} className={field}>
                {states.map((state) => <option key={state.value} value={state.value}>{state.label}</option>)}
              </select>
            </label>
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Internal note
              <textarea name="admin_note" defaultValue={application.admin_note ?? ''} maxLength={5000} rows={4} placeholder="e.g. Verified RSS ownership, sent claim link on 12 Oct." className={`${field} min-h-24 resize-y`} />
            </label>
            {application.reviewed_at ? <p className="text-xs text-slate-500">Last reviewed {new Date(application.reviewed_at).toLocaleString()}</p> : null}
            {result ? (
              <p role="status" className={`rounded-lg border px-3 py-2 text-sm ${result.tone === 'ok' ? 'border-teal-200 bg-teal-50 text-teal-900' : 'border-rose-200 bg-rose-50 text-rose-900'}`}>{result.text}</p>
            ) : null}
            <button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-3 text-sm font-bold text-slate-950 hover:bg-teal-300 disabled:opacity-50">
              {busy ? 'Saving…' : 'Save review'}
            </button>
          </form>
        </div>
      </aside>
    </div>
  );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[120px_minmax(0,1fr)] gap-3">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-slate-900">{children}</dd>
    </div>
  );
}

function StateTab({ active, label, count, onClick }: { active: boolean; label: string; count: number; onClick: () => void }) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition ${active ? 'border-teal-400 bg-teal-50 text-slate-900' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
    >
      {label}
      <span className={`rounded-full px-2 py-0.5 text-xs ${active ? 'bg-teal-400 text-slate-950' : 'bg-slate-100 text-slate-600'}`}>{count}</span>
    </button>
  );
}

function PageLink({ href, label }: { href: string | null; label: string }) {
  return href ? (
    <Link href={href} preserveScroll className="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:border-teal-400">{label}</Link>
  ) : (
    <span className="rounded-lg border border-slate-100 px-3 py-1.5 text-sm text-slate-300">{label}</span>
  );
}

function StatusChip({ value, label }: { value: string; label: string }) {
  const tone =
    value === 'new'
      ? 'bg-teal-50 text-teal-800 border-teal-200'
      : value === 'reviewing'
        ? 'bg-amber-50 text-amber-800 border-amber-200'
        : value === 'approved'
          ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
          : value === 'invited'
            ? 'bg-sky-50 text-sky-800 border-sky-200'
            : 'bg-slate-100 text-slate-600 border-slate-200';
  return <span className={`inline-block whitespace-nowrap rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ${tone}`}>{label}</span>;
}

function prettyUrl(url: string): string {
  return url.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '');
}

function socialUrl(handle: string | null): string | null {
  if (!handle) return null;
  const trimmed = handle.trim();
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  return null;
}

import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { useUnsavedChanges } from '../../components/admin/useUnsavedChanges';

type Props = {
  inquiry: {
    id: string;
    name: string;
    email: string;
    audience: string;
    subject: string;
    message: string;
    state: string;
    created_at: string;
    updated_at: string;
  };
  matchedUser: { id: string; name: string; handle: string; status: string } | null;
  audit: { id: string; action: string; reason: string; created_at: string }[];
  freshAt: string;
};

const field =
  'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none focus:border-teal-500';

const states = [
  { value: 'new', label: 'New' },
  { value: 'open', label: 'Open' },
  { value: 'pending', label: 'Pending' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'closed', label: 'Closed' },
];

export default function ContactInquiry({ inquiry, matchedUser, audit, freshAt }: Props) {
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [result, setResult] = useState('');
  const [copied, setCopied] = useState<'email' | 'id' | null>(null);
  useUnsavedChanges(dirty);

  const mailto = `mailto:${inquiry.email}?subject=${encodeURIComponent(`Re: ${inquiry.subject}`)}`;
  const received = new Date(inquiry.created_at).toLocaleString();
  const initials = inquiry.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');

  const copy = async (value: string, kind: 'email' | 'id') => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(kind);
      window.setTimeout(() => setCopied(null), 1600);
    } catch {
      setCopied(null);
    }
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setResult('');
    try {
      const response = await fetch(`/api/admin/v1/support/contact/${inquiry.id}`, {
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
      setResult(`Status saved. Audit ${body.data.audit_reference}`);
      setDirty(false);
      router.reload();
    } catch (error) {
      setResult(error instanceof Error ? error.message : 'Connection interrupted. Your changes remain in the form.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
      <Head title={inquiry.subject} />
      <div className="mx-auto max-w-[1280px]">
        <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">
          <Link href="/admin/support?view=contact">Support / Website contact</Link>
        </nav>

        <header className="mt-3 flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <StatusChip value={inquiry.state} />
              <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-600">
                {inquiry.audience}
              </span>
            </div>
            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-900">{inquiry.subject}</h1>
            <p className="mt-2 text-sm text-slate-500">
              Received {received}
              <span className="mx-2 text-slate-300">·</span>
              Last refreshed {new Date(freshAt).toLocaleString()}
            </p>
          </div>
          <a
            href={mailto}
            className="inline-flex items-center rounded-lg bg-teal-400 px-4 py-2.5 text-sm font-bold text-slate-950 hover:bg-teal-300"
          >
            Reply by email
          </a>
        </header>

        <div className="mt-8 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
          <div className="min-w-0 space-y-6">
            <section className="admin-panel overflow-hidden rounded-2xl">
              <div className="flex items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
                <div>
                  <h2 className="font-semibold text-slate-900">Message</h2>
                  <p className="mt-0.5 text-xs text-slate-500">From the public contact form. Reply using the email on the right.</p>
                </div>
              </div>
              <div className="px-6 py-6">
                <blockquote className="rounded-xl border border-slate-200 bg-slate-50 px-5 py-5 text-[15px] leading-8 text-slate-800">
                  <p className="whitespace-pre-wrap break-words">{inquiry.message}</p>
                </blockquote>
              </div>
            </section>

            <section className="admin-panel overflow-hidden rounded-2xl">
              <div className="border-b border-slate-200 px-6 py-4">
                <h2 className="font-semibold text-slate-900">Activity</h2>
                <p className="mt-0.5 text-xs text-slate-500">Status changes made from this desk.</p>
              </div>
              {audit.length ? (
                <ol className="divide-y divide-slate-200">
                  {audit.map((item) => (
                    <li key={item.id} className="px-6 py-4">
                      <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="text-sm font-semibold text-slate-900">{labelAction(item.action)}</p>
                        <p className="text-xs text-slate-500">{new Date(item.created_at).toLocaleString()}</p>
                      </div>
                      <p className="mt-1 text-sm text-slate-600">{item.reason}</p>
                    </li>
                  ))}
                </ol>
              ) : (
                <div className="px-6 py-10 text-center">
                  <p className="text-sm font-medium text-slate-700">No status changes yet</p>
                  <p className="mt-1 text-sm text-slate-500">Save a triage update to start the history for this message.</p>
                </div>
              )}
            </section>
          </div>

          <aside className="space-y-6 xl:sticky xl:top-24">
            <section className="admin-panel overflow-hidden rounded-2xl">
              <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="font-semibold text-slate-900">Sender</h2>
              </div>
              <div className="px-5 py-5">
                <div className="flex items-start gap-3">
                  <div className="grid size-12 shrink-0 place-items-center rounded-full bg-teal-50 text-sm font-bold text-teal-800">
                    {initials || '•'}
                  </div>
                  <div className="min-w-0">
                    <p className="text-base font-semibold text-slate-900">{inquiry.name}</p>
                    <a className="mt-0.5 block break-all text-sm font-medium text-teal-700 hover:underline" href={mailto}>
                      {inquiry.email}
                    </a>
                  </div>
                </div>
                <dl className="mt-5 grid gap-3 text-sm">
                  <div className="flex items-center justify-between gap-3">
                    <dt className="text-slate-500">Audience</dt>
                    <dd className="font-medium capitalize text-slate-900">{inquiry.audience}</dd>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <dt className="text-slate-500">Pelevo account</dt>
                    <dd className="text-right">
                      {matchedUser ? (
                        <Link href={`/admin/users/${matchedUser.id}`} className="font-medium text-teal-700 hover:underline">
                          {matchedUser.name}
                        </Link>
                      ) : (
                        <span className="text-slate-600">Not registered</span>
                      )}
                    </dd>
                  </div>
                </dl>
                <div className="mt-5 flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => void copy(inquiry.email, 'email')}
                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-teal-400 hover:text-teal-800"
                  >
                    {copied === 'email' ? 'Email copied' : 'Copy email'}
                  </button>
                  <button
                    type="button"
                    onClick={() => void copy(inquiry.id, 'id')}
                    className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:border-teal-400 hover:text-teal-800"
                  >
                    {copied === 'id' ? 'ID copied' : 'Copy ID'}
                  </button>
                </div>
              </div>
            </section>

            <form onSubmit={submit} onChange={() => setDirty(true)} className="admin-panel grid gap-4 rounded-2xl p-5">
              <div>
                <h2 className="font-semibold text-slate-900">Triage</h2>
                <p className="mt-1 text-xs text-slate-500">Record why the status changed. This is internal only.</p>
              </div>
              <label className="grid gap-2 text-sm font-medium text-slate-700">
                State
                <select name="state" defaultValue={inquiry.state} className={field}>
                  {states.map((state) => (
                    <option key={state.value} value={state.value}>
                      {state.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Reason for change
                <textarea
                  name="reason"
                  required
                  minLength={10}
                  maxLength={2000}
                  rows={4}
                  placeholder="e.g. Replied by email and asked for their RSS URL."
                  className={`${field} min-h-28 resize-y`}
                />
              </label>
              {result ? (
                <p role="status" className="rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm text-teal-900">
                  {result}
                </p>
              ) : null}
              <button
                disabled={busy}
                className="rounded-lg bg-teal-400 px-4 py-3 text-sm font-bold text-slate-950 hover:bg-teal-300 disabled:opacity-50"
              >
                {busy ? 'Saving…' : 'Save status'}
              </button>
            </form>
          </aside>
        </div>
      </div>
    </main>
  );
}

function StatusChip({ value }: { value: string }) {
  const tone =
    value === 'new'
      ? 'bg-teal-50 text-teal-800 border-teal-200'
      : value === 'pending'
        ? 'bg-amber-50 text-amber-800 border-amber-200'
        : value === 'resolved'
          ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
          : value === 'closed'
            ? 'bg-slate-100 text-slate-600 border-slate-200'
            : 'bg-sky-50 text-sky-800 border-sky-200';
  return (
    <span className={`rounded-full border px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ${tone}`}>
      {value.replaceAll('_', ' ')}
    </span>
  );
}

function labelAction(action: string): string {
  if (action === 'support.contact_updated') return 'Status updated';
  return action.replaceAll('.', ' ');
}

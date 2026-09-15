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

export default function ContactInquiry({ inquiry, matchedUser, audit, freshAt }: Props) {
  const [busy, setBusy] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [result, setResult] = useState('');
  useUnsavedChanges(dirty);

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
      setResult(`Status updated. Audit reference: ${body.data.audit_reference}`);
      setDirty(false);
      router.reload();
    } catch (error) {
      setResult(error instanceof Error ? error.message : 'Connection interrupted. Your changes remain in the form.');
    } finally {
      setBusy(false);
    }
  };

  const input = 'rounded-lg border border-slate-600 bg-slate-950 p-3 text-sm text-slate-100 focus:border-teal-400 focus:outline-none';

  return (
    <main className="mx-auto max-w-[1100px] p-5 sm:p-8">
      <Head title={inquiry.subject} />
      <nav className="text-sm text-teal-300">
        <Link href="/admin/support?view=contact">Website contact</Link>
        <span className="px-2 text-slate-500">/</span>
        Message
      </nav>
      <header className="my-6">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-full bg-teal-950 px-3 py-1 text-xs uppercase text-teal-300">{inquiry.state}</span>
          <span className="rounded-full bg-slate-800 px-3 py-1 text-xs uppercase text-slate-300">{inquiry.audience}</span>
        </div>
        <h1 className="mt-3 text-3xl font-semibold">{inquiry.subject}</h1>
        <p className="mt-2 font-mono text-xs text-slate-400">{inquiry.id} · Received {new Date(inquiry.created_at).toLocaleString()} · Updated {new Date(freshAt).toLocaleString()}</p>
      </header>
      <div className="grid gap-6 xl:grid-cols-[1fr_320px]">
        <div className="min-w-0 space-y-6">
          <section className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6">
            <h2 className="font-semibold">Message</h2>
            <p className="mt-4 whitespace-pre-wrap break-words text-sm leading-7">{inquiry.message}</p>
          </section>
          <section className="rounded-2xl border border-slate-800 p-6">
            <h2 className="font-semibold">Audit history</h2>
            {audit.length ? (
              <ol className="mt-4 divide-y divide-slate-800">
                {audit.map((item) => (
                  <li key={item.id} className="py-3">
                    <p className="text-sm font-semibold">{item.action}</p>
                    <p className="mt-1 text-sm text-slate-400">{item.reason}</p>
                    <p className="mt-1 font-mono text-xs text-slate-500">{item.id} · {new Date(item.created_at).toLocaleString()}</p>
                  </li>
                ))}
              </ol>
            ) : (
              <p className="mt-4 text-sm text-slate-400">No administrator changes recorded yet.</p>
            )}
          </section>
        </div>
        <aside className="space-y-6">
          <section className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
            <h2 className="font-semibold">From</h2>
            <p className="mt-3 text-lg font-semibold">{inquiry.name}</p>
            <a className="mt-2 block break-all text-teal-300" href={`mailto:${inquiry.email}?subject=${encodeURIComponent('Re: '+inquiry.subject)}`}>{inquiry.email}</a>
            <p className="mt-3 text-sm text-slate-400">Audience: {inquiry.audience}</p>
            {matchedUser ? (
              <Link href={`/admin/users/${matchedUser.id}`} className="mt-4 block text-sm text-teal-300">
                Matched Pelevo account: {matchedUser.name} (@{matchedUser.handle}) → User 360
              </Link>
            ) : (
              <p className="mt-4 text-sm text-slate-400">No matching Pelevo account for this email.</p>
            )}
          </section>
          <form onSubmit={submit} onChange={() => setDirty(true)} className="grid gap-4 rounded-2xl border border-slate-800 p-5">
            <h2 className="font-semibold">Triage</h2>
            <label className="grid gap-2 text-sm">
              State
              <select name="state" defaultValue={inquiry.state} className={input}>
                {['new', 'open', 'pending', 'resolved', 'closed'].map((state) => <option key={state}>{state}</option>)}
              </select>
            </label>
            <label className="grid gap-2 text-sm">
              Reason for change
              <textarea name="reason" required minLength={10} maxLength={2000} className={input} rows={4} />
            </label>
            {result ? <p role="status" className="break-words text-sm text-amber-200">{result}</p> : null}
            <button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-3 font-semibold text-slate-950 disabled:opacity-50">
              {busy ? 'Saving…' : 'Save status'}
            </button>
          </form>
        </aside>
      </div>
    </main>
  );
}

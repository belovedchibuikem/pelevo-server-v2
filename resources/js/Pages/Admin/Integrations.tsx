import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

type Field = { name: string; label: string; type: string; options?: string[]; option_labels?: Record<string, string>; secret?: boolean; configured: boolean; hint: string | null; value: string };
type Integration = { provider: string; group: string; title: string; description: string; fields: Field[]; source: string; last_tested_at?: string | null; last_test_state?: string | null; last_test_message?: string | null };

export default function Integrations({ integrations, freshAt, adminEmail }: { integrations: Integration[]; freshAt: string; adminEmail?: string }) {
  const groups = useMemo(() => [...new Set(integrations.map(item => item.group))], [integrations]);

  return <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
    <Head title="API and SMTP settings" />
    <div className="mx-auto max-w-5xl">
      <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Platform / Settings</nav>
      <header className="mt-3 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">API, SMTP and providers</h1>
          <p className="mt-2 max-w-3xl text-sm text-slate-500">Credentials are encrypted at rest. Secret fields stay blank on this page — leave them empty to keep the stored value. Tests never echo the secret back.</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-slate-500">Updated {new Date(freshAt).toLocaleString()}</span>
          <Link href="/admin/settings" className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Back to settings</Link>
        </div>
      </header>
      {groups.map(group => <section key={group} className="mt-10">
        <h2 className="text-sm font-bold uppercase tracking-[.14em] text-slate-500">{group}</h2>
        <div className="mt-4 grid gap-5">{integrations.filter(item => item.group === group).map(item => <ProviderCard key={item.provider} item={item} adminEmail={adminEmail ?? ''} />)}</div>
      </section>)}
    </div>
  </main>;
}

function ProviderCard({ item, adminEmail }: { item: Integration; adminEmail: string }) {
  const [busy, setBusy] = useState<'save' | 'test' | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(item.last_test_message ? { ok: item.last_test_state === 'ok', text: item.last_test_message } : null);
  const [stepUp, setStepUp] = useState(false);
  const [testTo, setTestTo] = useState(adminEmail);
  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy('save'); setMessage(null);
    const form = new FormData(event.currentTarget);
    const values = Object.fromEntries([...form.entries()].filter(([key]) => key !== 'reason' && key !== 'test_to'));
    try {
      const response = await send('PUT', `/api/admin/v1/integrations/${item.provider}`, { values, reason: form.get('reason') });
      const body = await response.json();
      setStepUp(response.status === 403);
      if (response.ok) { setMessage({ ok: true, text: 'Saved. Secrets were not written to the page or logs.' }); router.reload({ only: ['integrations', 'freshAt', 'adminEmail'] }); }
      else setMessage({ ok: false, text: body.error?.message || 'Save failed. Confirm MFA and try again.' });
    } catch { setMessage({ ok: false, text: 'Connection interrupted. Your form is still on this page.' }); }
    finally { setBusy(null); }
  };
  const test = async () => {
    if (item.provider === 'smtp' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(testTo.trim())) {
      setMessage({ ok: false, text: 'Enter the email address that should receive the test message.' });
      return;
    }
    setBusy('test'); setMessage(null);
    try {
      const response = await send('POST', `/api/admin/v1/integrations/${item.provider}/test`, item.provider === 'smtp' ? { to: testTo.trim() } : {});
      let body: { data?: { ok?: boolean; message?: string }; error?: { message?: string; fields?: Record<string, string[]> }; message?: string } = {};
      try { body = await response.json(); } catch { body = {}; }
      setStepUp(response.status === 403);
      const result = body.data && typeof body.data === 'object' ? body.data : {};
      const fieldErrors = Object.values(body.error?.fields ?? {}).flat().join(' ');
      const text = result.message || body.error?.message || fieldErrors || (typeof body.message === 'string' ? body.message : '') || (
        response.status === 504
          ? 'The mail server did not answer before the web server gave up. Port 587 is often blocked from local WAMP.'
          : `Test failed (HTTP ${response.status}).`
      );
      setMessage({ ok: Boolean(response.ok && result.ok), text });
    } catch { setMessage({ ok: false, text: 'The probe could not run. Check network access from this server.' }); }
    finally { setBusy(null); }
  };

  return <article className="admin-panel rounded-2xl p-6">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 className="text-lg font-semibold">{item.title}</h3>
        <p className="mt-1 text-sm text-slate-500">{item.description}</p>
      </div>
      <span className="rounded-full border border-slate-200 px-3 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">{item.source === 'database' ? 'Admin override' : 'Environment default'}</span>
    </div>
    <form onSubmit={save} className="mt-5 grid gap-4 sm:grid-cols-2">
      {item.fields.map(field => <label className="grid gap-2 text-sm" key={field.name}>{field.label}
        {field.type === 'select' ? <select name={field.name} defaultValue={field.value} className={input}>{field.options?.map(option => <option value={option} key={option}>{field.option_labels?.[option] ?? (option === '' ? 'None' : option === '1' ? 'Enabled' : option === '0' ? 'Disabled' : option)}</option>)}</select>
          : <input name={field.name} type={field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : 'text'} defaultValue={field.type === 'password' ? '' : field.value} placeholder={field.hint ?? undefined} autoComplete="off" className={input} />}
        {field.hint && <span className="text-xs text-slate-500">{field.hint}</span>}
      </label>)}
      <label className="grid gap-2 text-sm sm:col-span-2">Change reason<textarea name="reason" required minLength={10} maxLength={2000} className={`${input} min-h-20`} placeholder="Why this credential is changing" /></label>
      {item.provider === 'smtp' && <label className="grid gap-2 text-sm sm:col-span-2">Send test email to
        <input name="test_to" type="email" value={testTo} onChange={event => setTestTo(event.target.value)} placeholder="you@example.com" autoComplete="off" className={input} />
        <span className="text-xs text-slate-500">A real message is sent to this address. Check inbox and spam after a green result.</span>
      </label>}
      {message && <p role="status" className={`sm:col-span-2 rounded-lg px-3 py-2 text-sm ${message.ok ? 'bg-teal-50 text-teal-900' : 'bg-amber-50 text-amber-900'}`}>{message.text}</p>}
      {stepUp && <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="sm:col-span-2 text-sm text-teal-700">Confirm MFA in a new tab, then retry →</a>}
      <div className="sm:col-span-2 flex flex-wrap gap-3">
        <button disabled={busy !== null} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950 disabled:opacity-50">{busy === 'save' ? 'Saving…' : 'Save credentials'}</button>
        <button type="button" disabled={busy !== null} onClick={() => void test()} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-50">{busy === 'test' ? (item.provider === 'smtp' ? 'Sending test email…' : 'Testing…') : (item.provider === 'smtp' ? 'Send test email' : 'Test connection')}</button>
      </div>
    </form>
  </article>;
}

const input = 'rounded-lg border border-slate-300 bg-transparent px-3 py-2 outline-none focus:border-teal-500';

async function send(method: string, url: string, body: object) {
  return fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify(body) });
}

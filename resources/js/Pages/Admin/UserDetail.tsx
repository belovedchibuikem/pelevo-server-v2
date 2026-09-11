import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

type UserSummary = { id: string; name: string; handle?: string | null; email?: string | null; phone?: string | null; country_code?: string | null; status: string; email_verified: boolean; onboarded_at?: string | null; created_at: string };
type Row = Record<string, unknown>;
type Props = { user: UserSummary; profile?: Row | null; devices: Row[]; activity: Record<string, number>; accounts: Row[]; premium: Row[]; referrals: Row[]; sanctions: Row[]; support: Row[]; privacy: { exports: Row[]; deletions: Row[] }; audit: Row[]; freshAt: string };

const tabs = ['overview', 'identity', 'devices', 'activity', 'money', 'premium', 'referrals', 'safety', 'support', 'privacy', 'audit'];

export default function UserDetail(props: Props) {
  const [action, setAction] = useState<'disable' | 'restore' | 'force_logout' | null>(null);
  const [reason, setReason] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string; audit?: string } | null>(null);
  const { adminAuth } = usePage<{ adminAuth?: { permissions: string[] } | null }>().props;
  const canSuspend = adminAuth?.permissions.includes('users.suspend') ?? false;
  const expectedConfirmation = props.user.handle || props.user.id;
  const submit = async () => {
    if (!action || reason.trim().length < 10 || (action === 'disable' && confirmation !== expectedConfirmation)) return;
    setBusy(true); setResult(null);
    const response = await fetch(`/api/admin/v1/users/${props.user.id}/access`, { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ action, reason, confirmation }) });
    if (response.status === 403) { window.location.assign('/admin/step-up'); return; }
    const body = await response.json();
    setBusy(false);
    if (response.ok) { setResult({ ok: true, message: 'The governed action completed successfully.', audit: body.data.audit_reference }); setAction(null); setReason(''); setConfirmation(''); router.reload(); }
    else setResult({ ok: false, message: body.error?.message ?? 'The action could not be completed safely.' });
  };

  return <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10"><Head title={`${props.user.name} · User 360`} /><div className="mx-auto max-w-[1500px]">
    <nav className="text-xs font-semibold uppercase tracking-[.16em] text-teal-300"><Link href="/admin/users">Users</Link> / User 360</nav>
    <header className="mt-4 flex flex-wrap items-start justify-between gap-5"><div className="flex items-center gap-4"><div className="grid size-14 place-items-center rounded-2xl bg-teal-400 text-xl font-black text-slate-950">{props.user.name.charAt(0).toUpperCase()}</div><div><div className="flex flex-wrap items-center gap-2"><h1 className="text-3xl font-semibold">{props.user.name}</h1><Status value={props.user.status} /></div><p className="mt-1 text-sm text-slate-400">@{props.user.handle || 'unset'} · {props.user.id}</p></div></div><div className="text-right text-xs text-slate-500">Updated {new Date(props.freshAt).toLocaleString()}</div></header>
    {result && <div className={`mt-5 rounded-xl border p-4 text-sm ${result.ok ? 'border-teal-800 bg-teal-950/20 text-teal-200' : 'border-red-800 bg-red-950/20 text-red-200'}`}>{result.message}{result.audit && <p className="mt-1 font-mono text-xs">Audit reference: {result.audit}</p>}</div>}
    <nav aria-label="User detail sections" className="mt-7 flex gap-2 overflow-x-auto border-b border-slate-800 pb-3">{tabs.map(tab => <a className="whitespace-nowrap rounded-lg border border-slate-800 px-3 py-2 text-xs capitalize text-slate-400 hover:border-teal-600 hover:text-white" href={`#${tab}`} key={tab}>{tab}</a>)}</nav>
    <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"><div className="grid min-w-0 gap-6 lg:grid-cols-2">
      <Panel id="overview" title="Overview"><Definition values={{ status: props.user.status, country: props.user.country_code, 'email verified': props.user.email_verified, onboarded: props.user.onboarded_at, created: props.user.created_at }} /></Panel>
      <Panel id="identity" title="Identity"><Definition values={{ email: props.user.email, phone: props.user.phone, locale: props.profile?.locale, timezone: props.profile?.timezone, bio: props.profile?.bio }} /><p className="mt-3 text-xs text-slate-600">PII is masked by default.</p></Panel>
      <Panel id="devices" title={`Devices & sessions (${props.devices.length})`}><Rows rows={props.devices} /></Panel>
      <Panel id="activity" title="Listening & library"><Definition values={props.activity} /></Panel>
      <Panel id="money" title={`Money surfaces (${props.accounts.length})`}><Rows rows={props.accounts} /><p className="mt-3 text-xs text-slate-600">Balances are read-only. Corrections require immutable ledger workflows.</p></Panel>
      <Panel id="premium" title={`Premium (${props.premium.length})`}><Rows rows={props.premium} /></Panel>
      <Panel id="referrals" title={`Referrals (${props.referrals.length})`}><Rows rows={props.referrals} /></Panel>
      <Panel id="safety" title={`Reports & sanctions (${props.sanctions.length})`}><Rows rows={props.sanctions} /></Panel>
      <Panel id="support" title={`Support history (${props.support.length})`}><Rows rows={props.support} /></Panel>
      <Panel id="privacy" title="Privacy requests"><h3 className="text-xs font-bold uppercase text-slate-500">Exports</h3><Rows rows={props.privacy.exports} /><h3 className="mt-5 text-xs font-bold uppercase text-slate-500">Deletions</h3><Rows rows={props.privacy.deletions} /></Panel>
      <Panel id="audit" title={`Immutable audit (${props.audit.length})`} className="lg:col-span-2"><Rows rows={props.audit} /></Panel>
    </div><aside className="space-y-4"><section className="sticky top-24 rounded-2xl border border-slate-800 bg-slate-900/70 p-5"><h2 className="font-semibold">Account controls</h2><p className="mt-2 text-xs leading-5 text-slate-500">High-risk controls require a reason, current MFA, and produce an immutable audit reference.</p>{canSuspend ? <div className="mt-5 grid gap-2"><Action danger={props.user.status === 'active'} onClick={() => setAction(props.user.status === 'active' ? 'disable' : 'restore')}>{props.user.status === 'active' ? 'Disable account' : 'Restore account'}</Action><Action onClick={() => setAction('force_logout')}>Force logout all devices</Action></div> : <p className="mt-4 rounded-lg bg-slate-950 p-3 text-xs text-amber-300">Your role does not include users.suspend.</p>}</section></aside></div>
    {action && <div className="fixed inset-0 z-50 grid place-items-center bg-black/75 p-4" role="dialog" aria-modal="true"><section className="w-full max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6"><h2 className="text-xl font-semibold capitalize">{action.replaceAll('_', ' ')}</h2><p className="mt-2 text-sm text-slate-400">This action affects active access immediately and will be permanently audited.</p><label className="mt-5 grid gap-2 text-sm">Reason<textarea className="min-h-28 rounded-lg border border-slate-700 bg-slate-950 p-3 outline-none focus:border-teal-500" value={reason} onChange={event => setReason(event.target.value)} /></label>{action === 'disable' && <label className="mt-4 grid gap-2 text-sm">Type <code>{expectedConfirmation}</code> to confirm<input className="rounded-lg border border-slate-700 bg-slate-950 p-3 outline-none focus:border-red-500" value={confirmation} onChange={event => setConfirmation(event.target.value)} /></label>}<div className="mt-6 flex justify-end gap-3"><button className="rounded-lg border border-slate-700 px-4 py-2 text-sm" onClick={() => setAction(null)}>Cancel</button><button disabled={busy || reason.trim().length < 10 || (action === 'disable' && confirmation !== expectedConfirmation)} className="rounded-lg bg-red-500 px-4 py-2 text-sm font-bold text-white disabled:opacity-40" onClick={submit}>{busy ? 'Applying…' : 'Confirm action'}</button></div></section></div>}
  </div></main>;
}

function Panel({ id, title, children, className = '' }: { id: string; title: string; children: ReactNode; className?: string }) { return <section id={id} className={`scroll-mt-24 rounded-2xl border border-slate-800 bg-slate-900/50 p-5 ${className}`}><h2 className="mb-4 font-semibold">{title}</h2>{children}</section>; }
function Definition({ values }: { values: Record<string, unknown> }) { return <dl className="grid grid-cols-[auto_1fr] gap-x-5 gap-y-3 text-sm">{Object.entries(values).map(([key, value]) => <div className="contents" key={key}><dt className="capitalize text-slate-500">{key}</dt><dd className="min-w-0 truncate text-right" title={display(value)}>{display(value)}</dd></div>)}</dl>; }
function Rows({ rows }: { rows: Row[] }) { return rows.length ? <div className="max-h-72 divide-y divide-slate-800 overflow-auto">{rows.map((row, index) => <article className="py-3 text-xs" key={String(row.id ?? index)}><Definition values={row} /></article>)}</div> : <p className="rounded-lg border border-dashed border-slate-700 p-5 text-center text-sm text-slate-500">No records.</p>; }
function Status({ value }: { value: string }) { return <span className="rounded-full bg-slate-800 px-2 py-1 text-[10px] font-bold uppercase text-teal-300">{value}</span>; }
function Action({ children, onClick, danger = false }: { children: ReactNode; onClick: () => void; danger?: boolean }) { return <button className={`rounded-lg border px-4 py-2 text-left text-sm font-semibold ${danger ? 'border-red-800 text-red-300 hover:bg-red-950/40' : 'border-slate-700 hover:border-teal-500 hover:text-teal-300'}`} onClick={onClick}>{children}</button>; }
function display(value: unknown): string { if (value === null || value === undefined || value === '') return '—'; if (typeof value === 'boolean') return value ? 'Yes' : 'No'; if (typeof value === 'object') return JSON.stringify(value); return String(value); }

import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { useUnsavedChanges } from '../../components/admin/useUnsavedChanges';

type Payload = { flags: Record<string, boolean>; limits: Record<string, number>; money: Record<string, number | string>; tabs: string[] };
type Version = { id: string; version: number; payload: Payload; reason: string; effective_at: string; created_at: string };
export default function Configuration({ versions, freshAt }: { versions: Version[]; freshAt: string }) {
  const latest = versions[0];
  const [draft, setDraft] = useState<Payload>(latest?.payload);
  const [dirty, setDirty] = useState(false);
  const [stepUp, setStepUp] = useState(false);
  useUnsavedChanges(dirty);
  const [preview, setPreview] = useState(false);
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  if (!latest) return <main className="p-8"><h1 className="text-2xl">Configuration unavailable</h1><p className="mt-3 text-slate-400">No baseline configuration is installed.</p></main>;
  const update = (group: 'flags' | 'limits' | 'money', key: string, value: boolean | number | string) => { setDraft(current => ({ ...current, [group]: { ...current[group], [key]: value } })); setPreview(false); setDirty(true); };
  const changes = (['flags', 'limits', 'money'] as const).flatMap(group => Object.entries(draft[group]).filter(([key, value]) => value !== latest.payload[group][key]).map(([key, value]) => ({ key: `${group}.${key}`, before: String(latest.payload[group][key]), after: String(value) })));
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); if (!preview) { setPreview(true); return; } setBusy(true); setMessage('');
    const data = new FormData(event.currentTarget);
    try {
      const response = await fetch('/api/admin/v1/configuration-versions', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ base_version: latest.version, payload: draft, effective_at: `${data.get('effective_at')}:00+00:00`, reason: data.get('reason') }) });
      const body = await response.json();
      if (!response.ok) { setStepUp(response.status === 403); throw new Error(Object.values(body.error?.fields ?? {}).flat().join(' ') || body.error?.message || 'Publishing failed. Your changes are still available.'); }
      setMessage(`Version ${body.data.version} published. Audit: ${body.data.audit_reference}`); setPreview(false); setDirty(false); setStepUp(false); router.reload();
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Connection interrupted. Retry when connected.'); }
    finally { setBusy(false); }
  };
  return <main className="mx-auto max-w-7xl p-5 sm:p-8"><Head title="Versioned configuration" /><Link href="/admin/settings" className="text-sm text-teal-300">Settings / Product configuration</Link><h1 className="mt-4 text-3xl font-semibold">Product configuration</h1><p className="mt-2 text-sm text-slate-400">Current editing baseline: v{latest.version} · Updated {new Date(freshAt).toLocaleString()}</p><p className="mt-4 rounded-xl border border-amber-900 bg-amber-950/20 p-4 text-sm text-amber-200">Changes apply from the effective time. Historical financial entries retain their recorded rates. Restoring an older payload publishes a new version.</p>
    <div className="mt-6 grid gap-6 xl:grid-cols-[1fr_300px]"><form onSubmit={submit} onChange={() => setDirty(true)} className="space-y-6">{(['flags', 'limits', 'money'] as const).map(group => <fieldset key={group} className="rounded-2xl border border-slate-800 p-5"><legend className="px-2 font-semibold capitalize">{group}</legend><div className="grid gap-4 sm:grid-cols-2">{Object.entries(draft[group]).map(([key, value]) => <label key={key} className="grid gap-2 text-sm text-slate-300"><span>{key.replaceAll('_', ' ')}</span>{typeof value === 'boolean' ? <select value={String(value)} onChange={event => update(group, key, event.target.value === 'true')} className="rounded-lg border border-slate-600 bg-slate-950 p-3"><option value="true">Enabled</option><option value="false">Disabled</option></select> : <input type="number" min={typeof value === 'number' ? 1 : 0.00000001} max="100000000" step={typeof value === 'number' ? '1' : 'any'} required value={value} onChange={event => update(group, key, typeof value === 'number' ? Number(event.target.value) : event.target.value)} className="rounded-lg border border-slate-600 bg-slate-950 p-3" />}</label>)}</div></fieldset>)}
    <section className="grid gap-4 rounded-2xl border border-slate-800 p-5"><h2 className="font-semibold">Review & publish</h2><label className="grid gap-2 text-sm">Effective time (UTC)<input type="datetime-local" name="effective_at" required className="rounded-lg border border-slate-600 bg-slate-950 p-3" /></label><label className="grid gap-2 text-sm">Reason<textarea name="reason" required minLength={10} maxLength={2000} className="rounded-lg border border-slate-600 bg-slate-950 p-3" /></label>{preview && <div className="rounded-xl bg-slate-950 p-4"><h3 className="font-semibold">Proposed changes</h3>{changes.map(change => <p key={change.key} className="mt-2 text-sm"><span className="text-slate-400">{change.key}</span> · {change.before} → <span className="text-teal-300">{change.after}</span></p>)}{!changes.length && <p className="mt-2 text-sm text-slate-400">Values are unchanged; this creates a new effective version.</p>}</div>}{stepUp && <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="text-sm text-teal-300">Confirm MFA in a new tab, then retry publication →</a>}{message && <p role="status" className="break-words text-sm text-amber-200">{message}</p>}<button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-3 font-semibold text-slate-950 disabled:opacity-50">{busy ? 'Publishing…' : preview ? 'Publish new version with MFA' : 'Preview changes'}</button></section></form>
    <aside className="space-y-3"><h2 className="font-semibold">Version history</h2>{versions.map(version => <article key={version.id} className="rounded-xl border border-slate-800 bg-slate-900/40 p-4"><h3 className="font-semibold">Version {version.version}</h3><p className="mt-2 text-xs text-slate-400">Effective {new Date(version.effective_at).toLocaleString()}</p><p className="mt-2 text-sm text-slate-300">{version.reason}</p><button type="button" onClick={() => { setDraft(version.payload); setPreview(false); setDirty(true); }} className="mt-3 text-sm text-teal-300">Use as proposed version</button></article>)}</aside></div>
  </main>;
}

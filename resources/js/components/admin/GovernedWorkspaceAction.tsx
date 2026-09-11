import { router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import { useFocusScope } from './useFocusScope';
import { useUnsavedChanges } from './useUnsavedChanges';

type Field = { name: string; label: string; type?: 'text' | 'number' | 'textarea' | 'select'; options?: string[]; min?: number; max?: number; defaultValue?: string | number };
type Spec = { title: string; description: string; endpoint: string; method: 'POST' | 'PUT'; fields: Field[] };

const specifications: Record<string, Spec> = {
  'communications:templates': { title: 'Create notification template version', description: 'Creates a governed reusable template. Existing versions remain in history.', endpoint: '/api/admin/v1/advanced/notification-templates', method: 'POST', fields: [{ name: 'key', label: 'Template key' }, { name: 'title', label: 'Notification title' }, { name: 'body', label: 'Notification body', type: 'textarea' }, { name: 'reason', label: 'Change reason', type: 'textarea' }] },
  'growth:programs': { title: 'Create referral program version', description: 'Activation retires the previous active version without rewriting historical rewards.', endpoint: '/api/admin/v1/advanced/referral-programs', method: 'POST', fields: [{ name: 'qualifying_seconds', label: 'Qualifying seconds', type: 'number', min: 60, defaultValue: 300 }, { name: 'referrer_reward', label: 'Referrer reward', type: 'number', min: 1 }, { name: 'referred_reward', label: 'Referred reward', type: 'number', min: 1 }, { name: 'daily_cap', label: 'Daily cap', type: 'number', min: 1 }, { name: 'state', label: 'State', type: 'select', options: ['draft', 'active', 'retired'] }, { name: 'reason', label: 'Change reason', type: 'textarea' }] },
  'cms:pages': { title: 'Create or update CMS page', description: 'Publishes a new immutable page version with an operator reason.', endpoint: '/api/admin/v1/advanced/cms', method: 'PUT', fields: [{ name: 'slug', label: 'Page slug' }, { name: 'title', label: 'Page title' }, { name: 'body', label: 'Content', type: 'textarea' }, { name: 'state', label: 'State', type: 'select', options: ['draft', 'published'] }, { name: 'reason', label: 'Change reason', type: 'textarea' }] },
  'ai:prompts': { title: 'Create prompt version', description: 'Controls rollout prospectively and preserves every prior prompt version.', endpoint: '/api/admin/v1/advanced/prompts', method: 'POST', fields: [{ name: 'type', label: 'Prompt type', type: 'select', options: ['summary', 'chapters', 'transcript'] }, { name: 'prompt', label: 'Prompt instructions', type: 'textarea' }, { name: 'rollout_percent', label: 'Rollout percent', type: 'number', min: 0, max: 100, defaultValue: 0 }, { name: 'state', label: 'State', type: 'select', options: ['draft', 'active', 'retired'] }, { name: 'reason', label: 'Change reason', type: 'textarea' }] },
};

export default function GovernedWorkspaceAction({ module, view }: { module: string; view: string }) {
  const spec = specifications[`${module}:${view}`];
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string; audit?: string } | null>(null);
  const scope = useRef<HTMLFormElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const [dirty, setDirty] = useState(false);
  const [stepUp, setStepUp] = useState(false);
  useFocusScope(open, scope, () => setOpen(false), trigger);
  useUnsavedChanges(dirty && open);
  if (!spec) return null;

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setBusy(true); setResult(null);
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
    const response = await fetch(spec.endpoint, { method: spec.method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify(payload) });
    setStepUp(response.status === 403);
    const body = await response.json(); setBusy(false);
    if (response.ok) { setResult({ ok: true, message: 'Saved successfully.', audit: body.data.audit_reference }); setOpen(false); setDirty(false); router.reload(); }
    else setResult({ ok: false, message: Object.values(body.error?.fields ?? {}).flat().join(' ') || body.error?.message || 'Validation failed. Review the fields and try again.' });
    } catch { setResult({ ok: false, message: 'Connection interrupted. Your form is preserved. Check the latest records before retrying.' }); }
    finally { setBusy(false); }
  };

  return <div className="mt-7 rounded-2xl border border-teal-900/70 bg-teal-950/10 p-5"><div className="flex flex-wrap items-center justify-between gap-4"><div><h2 className="font-semibold">{spec.title}</h2><p className="mt-1 text-sm text-slate-500">{spec.description}</p></div><button ref={trigger} onClick={() => setOpen(true)} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950">Open governed form</button></div>{result && <p className={`mt-4 rounded-lg p-3 text-sm ${result.ok ? 'bg-teal-950 text-teal-200' : 'bg-red-950 text-red-200'}`}>{result.message}{result.audit && <span className="ml-2 font-mono text-xs">Audit: {result.audit}</span>}</p>}
    {open && <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/75 p-4" role="dialog" aria-modal="true" aria-label={spec.title}><form ref={scope} onSubmit={submit} onChange={() => setDirty(true)} className="my-8 w-full max-w-xl rounded-2xl border border-slate-700 bg-slate-900 p-6"><h2 className="text-xl font-semibold">{spec.title}</h2><p className="mt-2 text-sm text-slate-400">All fields are validated by the server. Publishing requires fresh MFA and creates an audit event.</p><div className="mt-5 grid gap-4">{spec.fields.map(field => <label className="grid gap-2 text-sm" key={field.name}>{field.label}{field.type === 'textarea' ? <textarea name={field.name} required className="min-h-24 rounded-lg border border-slate-700 bg-slate-950 p-3 outline-none focus:border-teal-500" /> : field.type === 'select' ? <select name={field.name} required className="rounded-lg border border-slate-700 bg-slate-950 p-3">{field.options?.map(option => <option value={option} key={option}>{option}</option>)}</select> : <input name={field.name} required type={field.type ?? 'text'} min={field.min} max={field.max} defaultValue={field.defaultValue} className="rounded-lg border border-slate-700 bg-slate-950 p-3 outline-none focus:border-teal-500" />}</label>)}</div>{result && !result.ok && <p role="alert" className="mt-4 text-sm text-amber-200">{result.message}</p>}{stepUp && <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="mt-3 block text-sm text-teal-300">Confirm MFA in a new tab, then retry →</a>}<div className="mt-6 flex justify-end gap-3"><button type="button" className="rounded-lg border border-slate-700 px-4 py-2 text-sm" onClick={() => setOpen(false)}>Cancel</button><button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950 disabled:opacity-50">{busy ? 'Saving…' : 'Validate and save'}</button></div></form></div>}
  </div>;
}

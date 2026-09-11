import { router } from '@inertiajs/react';
import { useRef, useState, type FormEvent, type ReactNode } from 'react';

import { useFocusScope } from './useFocusScope';
import { useUnsavedChanges } from './useUnsavedChanges';

type Field = { name: string; label: string; type?: 'text' | 'number' | 'date' | 'datetime-local' | 'textarea' | 'select' | 'hidden'; options?: string[]; min?: number; max?: number; step?: number; required?: boolean; defaultValue?: string | number };

export default function FinanceAction({ title, description, endpoint, method, fields, trigger, idempotent = false }: { title: string; description: string; endpoint: string; method: 'POST' | 'PUT'; fields: Field[]; trigger: string; idempotent?: boolean }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [stepUp, setStepUp] = useState(false);
  const [dirty, setDirty] = useState(false);
  const scope = useRef<HTMLFormElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  useFocusScope(open, scope, () => setOpen(false), triggerRef);
  useUnsavedChanges(dirty && open);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    const payload = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      const response = await fetch(endpoint, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
          ...(idempotent ? { 'Idempotency-Key': crypto.randomUUID() } : {}),
        },
        body: JSON.stringify(payload),
      });
      setStepUp(response.status === 403);
      const body = await response.json();
      if (response.ok) {
        setOpen(false);
        setDirty(false);
        router.reload();
      } else {
        setError(Object.values(body.error?.fields ?? {}).flat().join(' ') || body.error?.message || 'Validation failed. Review the fields and try again.');
      }
    } catch {
      setError('Connection interrupted. Your form is preserved. Check the latest records before retrying.');
    } finally {
      setBusy(false);
    }
  };

  return <>
    <button ref={triggerRef} type="button" onClick={() => setOpen(true)} className="rounded-lg border border-teal-700 px-3 py-1.5 text-xs font-semibold text-teal-800 hover:border-teal-500">{trigger}</button>
    {open && <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/60 p-4" role="presentation" onMouseDown={() => setOpen(false)}>
      <form ref={scope} role="dialog" aria-modal="true" aria-label={title} onSubmit={submit} onChange={() => setDirty(true)} onMouseDown={event => event.stopPropagation()} className="admin-panel my-8 w-full max-w-xl rounded-2xl p-6">
        <h2 className="text-xl font-semibold">{title}</h2>
        <p className="mt-2 text-sm text-slate-500">{description}</p>
        <div className="mt-5 grid gap-4">{fields.map(field => field.type === 'hidden' ? <input key={field.name} type="hidden" name={field.name} defaultValue={field.defaultValue} /> : <label className="grid gap-2 text-sm" key={field.name}>{field.label}{field.type === 'textarea' ? <textarea name={field.name} required={field.required !== false} minLength={field.name === 'reason' || field.name === 'resolution' ? 10 : undefined} maxLength={2000} className="min-h-24 rounded-lg border border-slate-300 bg-transparent p-3 outline-none focus:border-teal-500" /> : field.type === 'select' ? <select name={field.name} required={field.required !== false} defaultValue={field.defaultValue} className="rounded-lg border border-slate-300 bg-transparent p-3">{field.options?.map(option => <option value={option} key={option}>{option}</option>)}</select> : <input name={field.name} required={field.required !== false} type={field.type ?? 'text'} min={field.min} max={field.max} step={field.step} defaultValue={field.defaultValue} className="rounded-lg border border-slate-300 bg-transparent p-3 outline-none focus:border-teal-500" />}</label>)}</div>
        {error && <p role="alert" className="mt-4 text-sm text-amber-800">{error}</p>}
        {stepUp && <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="mt-3 block text-sm text-teal-700">Confirm MFA in a new tab, then retry →</a>}
        <div className="mt-6 flex justify-end gap-3">
          <button type="button" className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => setOpen(false)}>Cancel</button>
          <button disabled={busy} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950 disabled:opacity-50">{busy ? 'Saving…' : 'Validate and save'}</button>
        </div>
      </form>
    </div>}
  </>;
}

export function EmptyQueue({ children }: { children: ReactNode }) {
  return <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">{children}</p>;
}

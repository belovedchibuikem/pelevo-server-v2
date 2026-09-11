import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

export default function RecordFinancialActions({ entity, record, reversed }: { entity: string; record: Record<string, string | number | null>; reversed: boolean }) {
  const permissions = usePage<{ adminAuth?: { permissions: string[] } }>().props.adminAuth?.permissions ?? [];
  const canReverse = entity === 'transactions' && !record.reverses_id && !reversed && permissions.includes('finance.adjust');
  const canReview = entity === 'withdrawals' && ['queued', 'approved'].includes(String(record.state)) && permissions.includes('payouts.approve');
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [stepUp, setStepUp] = useState(false);
  const [reversal, setReversal] = useState<string | null>(null);
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); const data = new FormData(event.currentTarget); setBusy(true); setMessage(''); setStepUp(false);
    if (data.get('confirmation') !== record.id) { setMessage('The confirmation must match the record ID exactly.'); setBusy(false); return; }
    try {
      const response = await fetch(`/api/admin/v1/finance/${canReverse ? `transactions/${record.id}/reversal` : `withdrawals/${record.id}`}`, { method: canReverse ? 'POST' : 'PUT', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ reason: data.get('reason'), ...(canReverse ? {} : { state: data.get('state') }) }) });
      const body = await response.json();
      if (!response.ok) { setStepUp(response.status === 403); throw new Error(Object.values(body.error?.fields ?? {}).flat().join(' ') || body.error?.message || 'The action did not complete. Refresh and review the current record before retrying.'); }
      setReversal(body.data.reversal_id ?? null); setOpen(false); setMessage('Financial action recorded. The evidence has been refreshed.'); router.reload();
    } catch (error) { setMessage(error instanceof Error ? error.message : 'Connection interrupted. Refresh the record to check its state before retrying.'); }
    finally { setBusy(false); }
  };
  if (!canReverse && !canReview && !message) return null;
  return <section aria-label="Governed financial actions" className="mb-5 rounded-xl border border-amber-900/70 bg-amber-950/10 p-5">
    <h2 className="font-semibold">Governed financial action</h2>
    <p className="mt-2 text-sm text-slate-300">{canReverse ? 'A reversal creates a new, balanced ledger transaction. Existing entries are never edited.' : 'Approval releases this withdrawal to the payout worker. Rejection releases the reserved funds through a reversing ledger transaction.'} A current MFA confirmation and an audit reason are required.</p>
    {!open && (canReverse || canReview) && <button onClick={() => setOpen(true)} className="mt-4 rounded-lg border border-amber-700 px-4 py-2 text-sm text-amber-200">{canReverse ? 'Review reversal' : 'Review withdrawal decision'}</button>}
    {open && <form onSubmit={submit} className="mt-4 grid max-w-xl gap-4">
      {!canReverse && <label className="grid gap-2 text-sm">Decision<select name="state" className="rounded-lg border border-slate-600 bg-slate-950 p-3">{record.state === 'queued' && <option value="approved">Approve payout of {record.coins} coins</option>}<option value="rejected">Reject and release reservation</option></select></label>}
      <label className="grid gap-2 text-sm">Business reason<textarea name="reason" required minLength={10} maxLength={2000} className="rounded-lg border border-slate-600 bg-slate-950 p-3" /></label>
      <label className="grid gap-2 text-sm">Type the record ID to confirm: <span className="break-all font-mono text-xs">{record.id}</span><input name="confirmation" required autoComplete="off" className="rounded-lg border border-slate-600 bg-slate-950 p-3" /></label>
      <div className="flex gap-3"><button disabled={busy} className="rounded-lg bg-amber-300 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50">{busy ? 'Applying…' : 'Confirm financial action'}</button><button type="button" disabled={busy} onClick={() => setOpen(false)} className="rounded-lg border border-slate-600 px-4 py-2">Cancel</button></div>
    </form>}
    {message && <p role="status" className="mt-3 text-sm text-amber-200">{message}</p>}
    {stepUp && <a href="/admin/step-up" target="_blank" rel="noopener noreferrer" className="mt-3 inline-block text-sm text-teal-300">Confirm MFA in a new tab, then return and retry →</a>}
    {reversal && <Link href={`/admin/records/transactions/${reversal}`} className="mt-3 inline-block text-sm text-teal-300">Open reversal evidence →</Link>}
  </section>;
}

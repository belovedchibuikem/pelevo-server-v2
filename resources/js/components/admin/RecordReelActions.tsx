import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Action = 'publish' | 'restore' | 'reject' | 'remove' | 'strike' | 'delete';
type RecordData = Record<string, string | number | null>;

export default function RecordReelActions({ record }: { record: RecordData }) {
  const permissions = usePage<{ adminAuth?: { permissions: string[] } }>().props.adminAuth?.permissions ?? [];
  const state = String(record.state ?? '');
  const [busy, setBusy] = useState<Action | null>(null);
  const [message, setMessage] = useState('');
  if (!permissions.includes('moderation.act')) return null;

  const decide = async (action: Action) => {
    const reason = window.prompt(`Reason for ${action} (at least 10 characters)`);
    if (!reason || reason.length < 10) return;
    if (action === 'delete' && !window.confirm('Permanently delete this reel? This cannot be undone.')) return;
    setBusy(action);
    setMessage('');
    try {
      const response = await fetch(`/api/admin/v1/reels/${record.id}/moderation`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ action, reason_code: `operator_${action}`, reason }),
      });
      const body = await response.json();
      if (!response.ok) throw new Error(body.error?.message ?? 'The moderation action failed.');
      setMessage(action === 'delete' ? 'Reel deleted.' : `Reel marked ${action}.`);
      if (action === 'delete') router.visit('/admin/moderation');
      else router.reload();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'The moderation action failed.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <section aria-label="Reel moderation actions" className="mb-5 rounded-xl border border-teal-900/70 bg-teal-950/10 p-5">
      <h2 className="font-semibold">Reel status</h2>
      <p className="mt-2 text-sm text-slate-300">
        Reels publish automatically after processing. Use these actions to restore, unpublish, strike, or delete.
      </p>
      <div className="mt-4 flex flex-wrap gap-2">
        {state !== 'published' && state !== 'processing' && (
          <button disabled={busy !== null} onClick={() => decide(state === 'removed' || state === 'rejected' ? 'restore' : 'publish')} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-400 disabled:opacity-50">
            {busy === 'publish' || busy === 'restore' ? 'Saving…' : state === 'removed' || state === 'rejected' ? 'Restore' : 'Publish'}
          </button>
        )}
        {state === 'published' ? (
          <button disabled={busy !== null} onClick={() => decide('remove')} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-400 disabled:opacity-50">
            {busy === 'remove' ? 'Saving…' : 'Unpublish'}
          </button>
        ) : (
          <button disabled={busy !== null} onClick={() => decide('reject')} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-400 disabled:opacity-50">
            {busy === 'reject' ? 'Saving…' : 'Reject'}
          </button>
        )}
        <button disabled={busy !== null} onClick={() => decide('remove')} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-400 disabled:opacity-50">
          Remove
        </button>
        <button disabled={busy !== null} onClick={() => decide('strike')} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold hover:border-teal-400 disabled:opacity-50">
          Strike
        </button>
        <button disabled={busy !== null} onClick={() => decide('delete')} className="rounded-lg border border-red-800 px-3 py-2 text-xs font-semibold text-red-300 hover:border-red-400 disabled:opacity-50">
          {busy === 'delete' ? 'Deleting…' : 'Delete'}
        </button>
      </div>
      {message && <p role="status" className="mt-3 text-sm text-teal-200">{message}</p>}
    </section>
  );
}

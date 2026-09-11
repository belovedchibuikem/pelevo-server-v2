import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Diagnostics = {
  healthy: boolean;
  scheduler: { healthy: boolean; last_heartbeat_at?: string | null; age_seconds?: number | null; maximum_age_seconds: number; host?: string | null };
  cache: { healthy: boolean };
  queues: { healthy: boolean; connection: string; depths: Record<string, number>; failed: number };
  horizon: { status: string };
  checked_at: string;
};
type Operation = { id: string; action: string; target?: string; reason: string; state: string; created_at: string };
type FeedSync = { id: string; show_id: string; state: string; new_episode_count: number; error?: string; started_at: string };
type FailedJob = { uuid: string; connection: string; queue: string; summary: string; failed_at: string };
type Props = {
  diagnostics: Diagnostics;
  manualTasks: Record<string, string>;
  recentOperations: Operation[];
  recentFeedSyncs: FeedSync[];
  failedJobs: FailedJob[];
  setup: { scheduler: string; horizon: string; verify: string };
};

export default function Operations({ diagnostics, manualTasks, recentOperations, recentFeedSyncs, failedJobs, setup }: Props) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const mutate = async (url: string) => {
    if (reason.trim().length < 10) {
      setMessage('Enter an operational reason of at least 10 characters.');
      return;
    }
    setBusy(url);
    setMessage(null);
    try {
      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ reason, idempotency_key: crypto.randomUUID() }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error?.message ?? 'Operation failed.');
      setMessage('Operation accepted and audited.');
      setReason('');
      router.reload();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : 'Operation failed.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <main className="mx-auto max-w-[1500px] p-5 sm:p-8">
      <Head title="Operations Center" />
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-xs font-bold tracking-[.18em] text-teal-300">PLATFORM / OPERATIONS</p>
          <h1 className="mt-2 text-3xl font-semibold">Scheduler and queue health</h1>
          <p className="mt-2 text-sm text-slate-400">Checked {new Date(diagnostics.checked_at).toLocaleString()}</p>
        </div>
        <Status healthy={diagnostics.healthy} label={diagnostics.healthy ? 'Platform healthy' : 'Action required'} />
      </header>

      <section aria-label="Operations diagnostics" className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Diagnostic title="Scheduler" healthy={diagnostics.scheduler.healthy} detail={diagnostics.scheduler.last_heartbeat_at ? `${diagnostics.scheduler.age_seconds}s ago on ${diagnostics.scheduler.host}` : 'No heartbeat recorded'} />
        <Diagnostic title="Horizon" healthy={diagnostics.horizon.status === 'running'} detail={diagnostics.horizon.status} />
        <Diagnostic title="Cache and locks" healthy={diagnostics.cache.healthy} detail={diagnostics.cache.healthy ? 'Atomic cache probe passed' : 'Cache probe failed'} />
        <Diagnostic title="Failed jobs" healthy={diagnostics.queues.failed === 0} detail={`${diagnostics.queues.failed} failed · ${diagnostics.queues.connection}`} />
      </section>

      <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
        <h2 className="font-semibold">Queue depth</h2>
        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Object.entries(diagnostics.queues.depths).map(([queue, depth]) => (
            <div key={queue} className="rounded-xl bg-slate-950/70 p-4">
              <span className="text-xs text-slate-400">{queue}</span>
              <strong className="mt-2 block text-2xl tabular-nums">{depth.toLocaleString()}</strong>
            </div>
          ))}
        </div>
      </section>

      <section className="mt-6 grid gap-5 xl:grid-cols-2">
        <article className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
          <h2 className="font-semibold">Linux host setup</h2>
          <p className="mt-2 text-sm text-slate-400">Install these on the Laravel host. The web application never edits the server crontab.</p>
          <SetupCommand label="Scheduler cron" value={setup.scheduler} />
          <SetupCommand label="Horizon service" value={setup.horizon} />
          <SetupCommand label="Verification" value={setup.verify} />
        </article>
        <article className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
          <h2 className="font-semibold">Governed task execution</h2>
          <label className="mt-4 grid gap-2 text-sm text-slate-300">
            Operational reason
            <textarea value={reason} onChange={(event) => setReason(event.target.value)} maxLength={2000} className="min-h-24 rounded-lg border border-slate-700 bg-slate-950 p-3" placeholder="Explain why this task must run now." />
          </label>
          {message && <p role="status" className="mt-3 rounded-lg border border-slate-700 p-3 text-sm">{message}</p>}
          <div className="mt-4 flex flex-wrap gap-2">
            {Object.entries(manualTasks).map(([task, label]) => (
              <button key={task} disabled={busy !== null} onClick={() => mutate(`/api/admin/v1/operations/tasks/${encodeURIComponent(task)}/run`)} className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-40">
                {busy?.includes(task) ? 'Running…' : label}
              </button>
            ))}
            <a href="/admin/horizon" className="rounded-lg border border-slate-600 px-4 py-2 text-sm">Open Horizon</a>
          </div>
        </article>
      </section>

      <section className="mt-6 rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
        <h2 className="font-semibold">Failed jobs</h2>
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-slate-400"><tr><th className="py-2">Queue</th><th>Failure</th><th>Failed</th><th>Action</th></tr></thead>
            <tbody>{failedJobs.map((job) => (
              <tr key={job.uuid} className="border-t border-slate-800">
                <td className="py-3">{job.connection} / {job.queue}</td>
                <td className="max-w-xl pr-4 text-slate-400">{job.summary}</td>
                <td>{new Date(job.failed_at).toLocaleString()}</td>
                <td><button disabled={busy !== null} onClick={() => mutate(`/api/admin/v1/operations/failed-jobs/${job.uuid}/retry`)} className="rounded border border-teal-700 px-3 py-1 text-xs">Retry</button></td>
              </tr>
            ))}</tbody>
          </table>
          {failedJobs.length === 0 && <p className="py-6 text-sm text-slate-400">No failed jobs.</p>}
        </div>
      </section>

      <section className="mt-6 grid gap-5 xl:grid-cols-2">
        <History title="Recent governed operations" rows={recentOperations.map((item) => ({ id: item.id, primary: `${item.action} · ${item.target ?? 'platform'}`, secondary: item.reason, state: item.state, at: item.created_at }))} />
        <History title="Recent RSS synchronization" rows={recentFeedSyncs.map((item) => ({ id: item.id, primary: `${item.show_id} · ${item.new_episode_count} new`, secondary: item.error ?? 'Feed synchronization completed without a recorded error.', state: item.state, at: item.started_at }))} />
      </section>
    </main>
  );
}

function Status({ healthy, label }: { healthy: boolean; label: string }) {
  return <span className={`rounded-full border px-3 py-1 text-xs font-bold uppercase ${healthy ? 'border-teal-700 bg-teal-950/20 text-teal-300' : 'border-amber-700 bg-amber-950/20 text-amber-300'}`}>{label}</span>;
}

function Diagnostic({ title, healthy, detail }: { title: string; healthy: boolean; detail: string }) {
  return <article className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"><Status healthy={healthy} label={healthy ? 'Healthy' : 'Attention'} /><h2 className="mt-4 font-semibold">{title}</h2><p className="mt-2 text-sm text-slate-400">{detail}</p></article>;
}

function SetupCommand({ label, value }: { label: string; value: string }) {
  return <div className="mt-4"><p className="text-xs font-bold uppercase text-slate-500">{label}</p><div className="mt-2 flex gap-2"><code className="min-w-0 flex-1 overflow-x-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-300">{value}</code><button onClick={() => navigator.clipboard.writeText(value)} className="rounded-lg border border-slate-700 px-3 text-xs">Copy</button></div></div>;
}

function History({ title, rows }: { title: string; rows: { id: string; primary: string; secondary: string; state: string; at: string }[] }) {
  return <article className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5"><h2 className="font-semibold">{title}</h2><ol className="mt-4 divide-y divide-slate-800">{rows.map((row) => <li key={row.id} className="py-3 text-sm"><div className="flex justify-between gap-3"><strong>{row.primary}</strong><span className="text-xs text-slate-400">{row.state}</span></div><p className="mt-1 text-slate-400">{row.secondary}</p><time className="mt-1 block text-xs text-slate-500">{new Date(row.at).toLocaleString()}</time></li>)}</ol>{rows.length === 0 && <p className="mt-4 text-sm text-slate-400">No activity recorded yet.</p>}</article>;
}

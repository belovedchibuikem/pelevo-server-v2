import { Head, router, useForm, usePage } from '@inertiajs/react';

type Session = { id: string; ip_address?: string; user_agent?: string; last_seen_at: string };
type Props = { admin: { name: string; email: string; notification_preferences?: { incidents?: boolean; tasks?: boolean } }; sessions: Session[]; currentSessionId?: string };

export default function Profile({ admin, sessions, currentSessionId }: Props) {
  const success = usePage<{ flash?: { success?: string } }>().props.flash?.success;
  const form = useForm({
    name: admin.name,
    current_password: '',
    password: '',
    password_confirmation: '',
    notification_preferences: { incidents: admin.notification_preferences?.incidents ?? true, tasks: admin.notification_preferences?.tasks ?? true },
  });

  return <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
    <Head title="Profile and security" />
    <div className="mx-auto max-w-4xl">
      <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Platform / Account</nav>
      <header className="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">Profile and security</h1>
          <p className="mt-2 text-sm text-slate-500">Signed in as {admin.email}. Password changes revoke nothing until you save a new password. Session revocation is immediate.</p>
        </div>
        <button type="button" onClick={() => router.post('/admin/logout')} className="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-800 hover:border-rose-400 hover:bg-rose-50">Sign out</button>
      </header>
      {success && <p role="status" className="mt-5 rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">{success}</p>}

      <form onSubmit={event => { event.preventDefault(); form.patch('/admin/profile'); }} className="admin-panel mt-8 rounded-2xl p-6">
        <h2 className="text-lg font-semibold">Operator profile</h2>
        <div className="mt-5 grid gap-5 sm:grid-cols-2">
          <label className="grid gap-2 text-sm sm:col-span-2">Display name<input className={field} value={form.data.name} onChange={event => form.setData('name', event.target.value)} autoComplete="name" /></label>
          <label className="grid gap-2 text-sm">Current password<input className={field} type="password" autoComplete="current-password" value={form.data.current_password} onChange={event => form.setData('current_password', event.target.value)} /></label>
          <label className="grid gap-2 text-sm">New password<input className={field} type="password" autoComplete="new-password" value={form.data.password} onChange={event => form.setData('password', event.target.value)} /></label>
          <label className="grid gap-2 text-sm sm:col-span-2">Confirm new password<input className={field} type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={event => form.setData('password_confirmation', event.target.value)} /></label>
        </div>
        {form.errors.current_password && <p className="mt-3 text-sm text-amber-800">{form.errors.current_password}</p>}
        {form.errors.password && <p className="mt-3 text-sm text-amber-800">{form.errors.password}</p>}
        <fieldset className="mt-8">
          <legend className="text-sm font-semibold">Email notifications</legend>
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm"><input className="mt-1 accent-teal-600" type="checkbox" checked={form.data.notification_preferences.incidents} onChange={event => form.setData('notification_preferences', { ...form.data.notification_preferences, incidents: event.target.checked })} /><span><strong className="block">Incident notifications</strong><span className="text-slate-500">Failed jobs, drift, and safety alerts.</span></span></label>
            <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm"><input className="mt-1 accent-teal-600" type="checkbox" checked={form.data.notification_preferences.tasks} onChange={event => form.setData('notification_preferences', { ...form.data.notification_preferences, tasks: event.target.checked })} /><span><strong className="block">Task notifications</strong><span className="text-slate-500">Queues that need an operator decision.</span></span></label>
          </div>
        </fieldset>
        <div className="mt-6 flex justify-end"><button disabled={form.processing} className="rounded-lg bg-teal-400 px-5 py-2.5 text-sm font-bold text-slate-950 disabled:opacity-50">{form.processing ? 'Saving…' : 'Save profile'}</button></div>
      </form>

      <section className="admin-panel mt-8 overflow-hidden rounded-2xl">
        <div className="border-b border-slate-200 p-6">
          <h2 className="text-lg font-semibold">Active sessions</h2>
          <p className="mt-1 text-sm text-slate-500">Revoke any session you do not recognize. Revoking this browser signs you out.</p>
        </div>
        {sessions.length === 0 ? <p className="p-8 text-sm text-slate-500">No tracked sessions.</p> : <ul className="divide-y divide-slate-200">{sessions.map(session => <li className="flex flex-wrap items-center justify-between gap-4 px-6 py-4" key={session.id}>
          <div>
            <p className="font-medium">{describeAgent(session.user_agent)}{currentSessionId === session.id ? <span className="ml-2 rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-bold uppercase text-teal-800">This browser</span> : null}</p>
            <p className="mt-1 text-xs text-slate-500">{session.ip_address || 'Unknown IP'} · Last seen {new Date(session.last_seen_at).toLocaleString()}</p>
          </div>
          <button type="button" onClick={() => router.delete(`/admin/sessions/${session.id}`)} className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:border-rose-400">Revoke</button>
        </li>)}</ul>}
      </section>
    </div>
  </main>;
}

const field = 'rounded-lg border border-slate-300 bg-transparent px-3 py-2 outline-none focus:border-teal-500';

function describeAgent(agent?: string) {
  if (!agent) return 'Unknown device';
  const browser = agent.includes('Edg/') ? 'Edge' : agent.includes('Chrome/') ? 'Chrome' : agent.includes('Firefox/') ? 'Firefox' : agent.includes('Safari/') ? 'Safari' : 'Browser';
  const os = agent.includes('Windows') ? 'Windows' : agent.includes('Mac OS') ? 'macOS' : agent.includes('Android') ? 'Android' : agent.includes('iPhone') ? 'iOS' : agent.includes('Linux') ? 'Linux' : 'device';
  return `${browser} on ${os}`;
}

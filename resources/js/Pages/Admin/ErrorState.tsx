import { Head, Link, router } from '@inertiajs/react';

export default function ErrorState({ status, title, message, retryable = false }: { status: number; title: string; message: string; retryable?: boolean }) {
  return (
    <main className="grid min-h-[calc(100vh-4.5rem)] place-items-center p-6">
      <Head title={title} />
      <section className="admin-card w-full max-w-xl rounded-2xl p-8 text-center">
        <span className="inline-grid size-14 place-items-center rounded-full border border-slate-200 bg-slate-50 font-mono text-lg font-bold text-teal-700">{status}</span>
        <h1 className="mt-5 text-2xl font-semibold">{title}</h1>
        <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-500">{message}</p>
        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <Link className="rounded-lg bg-teal-400 px-4 py-2 text-sm font-bold text-slate-950" href="/admin">Return to Command Center</Link>
          {retryable ? <button className="rounded-lg border border-slate-300 px-4 py-2 text-sm" onClick={() => router.reload()}>Try again</button> : null}
          <button type="button" className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold" onClick={() => router.post('/admin/logout')}>Sign out</button>
        </div>
      </section>
    </main>
  );
}

import { Head, router, useForm } from '@inertiajs/react';
import ThemeToggle from '../../components/admin/ThemeToggle';

export default function StepUp() {
  const form = useForm({ code: '' });

  return (
    <main className="auth">
      <Head title="Confirm it’s you" />
      <ThemeToggle className="auth-theme" />
      <form
        onSubmit={(event) => {
          event.preventDefault();
          form.post('/admin/step-up');
        }}
      >
        <div className="brand">
          PELEVO <span>HIGH ASSURANCE</span>
        </div>
        <h1>Confirm it’s you</h1>
        <p>Your admin session needs a fresh authenticator code before you can continue. Sign out if you want to leave.</p>
        <label>
          Authentication code
          <input
            inputMode="numeric"
            maxLength={6}
            autoFocus
            autoComplete="one-time-code"
            value={form.data.code}
            onChange={(event) => form.setData('code', event.target.value.replace(/\D/g, ''))}
          />
        </label>
        {form.errors.code ? <div className="error">{form.errors.code}</div> : null}
        <button disabled={form.processing}>{form.processing ? 'Confirming…' : 'Confirm identity'}</button>
        <button type="button" className="secondary" onClick={() => router.post('/admin/logout')}>
          Sign out
        </button>
      </form>
    </main>
  );
}

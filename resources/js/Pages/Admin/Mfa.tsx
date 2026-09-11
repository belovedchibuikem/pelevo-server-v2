import { Head, useForm } from '@inertiajs/react';

export default function Mfa() {
  const form = useForm({ code: '' });

  return <main className="auth">
    <Head title="MFA challenge" />
    <form onSubmit={event => {
      event.preventDefault();
      form.post('/admin/mfa');
    }}>
      <div className="brand">PELEVO <span>SECURE</span></div>
      <h1>Verify it’s you</h1>
      <p>Enter the six-digit code from your authenticator or a recovery code.</p>
      <label>
        Authentication or recovery code
        <input
          autoCapitalize="characters"
          autoComplete="one-time-code"
          autoFocus
          maxLength={20}
          placeholder="123456 or ABCDE-FGHIJ"
          value={form.data.code}
          onChange={event => form.setData('code', event.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, ''))}
        />
      </label>
      {form.errors.code && <div className="error">{form.errors.code}</div>}
      <button disabled={form.processing}>Verify and enter</button>
    </form>
  </main>;
}

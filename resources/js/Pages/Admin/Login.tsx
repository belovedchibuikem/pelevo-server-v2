import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ThemeToggle from '../../components/admin/ThemeToggle';

export default function Login() {
  const status = (usePage().props.flash as { status?: string } | undefined)?.status;
  const form = useForm({ email: '', password: '' });

  return (
    <main className="auth">
      <Head title="Admin sign in" />
      <ThemeToggle className="auth-theme" />
      <form
        onSubmit={(event) => {
          event.preventDefault();
          form.post('/admin/login');
        }}
      >
        <div className="brand">
          PELEVO <span>OPS</span>
        </div>
        <h1>Welcome back</h1>
        <p>Sign in with your administrator email and password.</p>
        {status ? (
          <div className="notice" role="status">
            {status}
          </div>
        ) : null}
        <label>
          Email
          <input
            type="email"
            name="email"
            autoComplete="username"
            value={form.data.email}
            onChange={(event) => form.setData('email', event.target.value)}
            autoFocus
            required
          />
        </label>
        <label>
          Password
          <input
            type="password"
            name="password"
            autoComplete="current-password"
            value={form.data.password}
            onChange={(event) => form.setData('password', event.target.value)}
            required
          />
        </label>
        <Link href="/admin/forgot-password">Forgot password?</Link>
        {Object.values(form.errors).map((error) => (
          <div className="error" role="alert" key={error}>
            {error}
          </div>
        ))}
        <button disabled={form.processing}>
          {form.processing ? 'Signing in…' : 'Continue securely'}
        </button>
      </form>
    </main>
  );
}

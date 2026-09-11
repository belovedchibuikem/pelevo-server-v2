import { Head, Link, useForm } from '@inertiajs/react';

export default function ForgotPassword() {
  const form = useForm({ email: '' });
  return <main className="auth"><Head title="Recover administrator account"/><form onSubmit={event => { event.preventDefault(); form.post('/admin/forgot-password'); }}><div className="brand">PELEVO <span>SECURE</span></div><h1>Reset your password</h1><p>Enter your administrator email. The response is intentionally identical whether or not an account exists.</p><label>Email<input type="email" autoFocus value={form.data.email} onChange={event => form.setData('email', event.target.value)}/></label>{form.errors.email && <div className="error">{form.errors.email}</div>}<button disabled={form.processing}>Send reset link</button><Link href="/admin/login">Back to sign in</Link></form></main>;
}

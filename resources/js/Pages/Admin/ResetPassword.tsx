import { Head, useForm } from '@inertiajs/react';

export default function ResetPassword({ token }: { token: string }) {
  const form = useForm({ token, password: '', password_confirmation: '' });
  return <main className="auth"><Head title="Choose a new password"/><form onSubmit={event => { event.preventDefault(); form.post('/admin/reset-password'); }}><div className="brand">PELEVO <span>SECURE</span></div><h1>Choose a new password</h1><p>All active administrator sessions will be revoked.</p><label>New password<input type="password" autoFocus value={form.data.password} onChange={event => form.setData('password', event.target.value)}/></label><label>Confirm password<input type="password" value={form.data.password_confirmation} onChange={event => form.setData('password_confirmation', event.target.value)}/></label>{Object.values(form.errors).map(error => <div className="error" key={error}>{error}</div>)}<button disabled={form.processing}>Reset password</button></form></main>;
}

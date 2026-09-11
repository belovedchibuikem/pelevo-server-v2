import { Head, useForm } from '@inertiajs/react';

export default function MfaEnrol({ secret, provisioningUri }: { secret: string; provisioningUri: string }) {
  const form = useForm({ code: '' });
  return <main className="auth"><Head title="Set up MFA"/><form onSubmit={event => { event.preventDefault(); form.post('/admin/mfa/enrol'); }}><div className="brand">PELEVO <span>SECURE</span></div><h1>Protect your account</h1><p>Add this key to your authenticator app, then enter the current code.</p><label>Setup key<input readOnly value={secret}/></label><a href={provisioningUri}>Open authenticator app</a><label>Authentication code<input inputMode="numeric" maxLength={6} value={form.data.code} onChange={event => form.setData('code', event.target.value.replace(/\D/g, ''))}/></label>{form.errors.code && <div className="error">{form.errors.code}</div>}<button disabled={form.processing}>Enable MFA</button></form></main>;
}

import { Head, useForm } from '@inertiajs/react';

export default function StepUp() {
  const form = useForm({ code: '' });
  return <main className="auth"><Head title="Confirm sensitive action"/><form onSubmit={event => { event.preventDefault(); form.post('/admin/step-up'); }}><div className="brand">PELEVO <span>HIGH ASSURANCE</span></div><h1>Confirm it’s you</h1><p>This sensitive operation requires an authenticator code issued within the last five minutes.</p><label>Authentication code<input inputMode="numeric" maxLength={6} autoFocus value={form.data.code} onChange={event => form.setData('code', event.target.value.replace(/\D/g, ''))}/></label>{form.errors.code && <div className="error">{form.errors.code}</div>}<button disabled={form.processing}>Confirm identity</button></form></main>;
}

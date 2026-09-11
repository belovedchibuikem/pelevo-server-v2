import { Head, useForm, usePage } from '@inertiajs/react';

type Flash = { status?: string };

export default function Contact() {
  const page = usePage<{ flash?: Flash }>();
  const status = page.props.flash?.status;
  const params = new URLSearchParams(page.url.includes('?') ? page.url.slice(page.url.indexOf('?')) : '');
  const audienceParam = params.get('audience');
  const subjectParam = params.get('subject');
  const audience = audienceParam && ['listener', 'creator', 'press', 'privacy', 'other'].includes(audienceParam)
    ? audienceParam
    : 'listener';

  const form = useForm({
    name: '',
    email: '',
    audience,
    subject: subjectParam ?? '',
    message: '',
    company_website: '',
  });

  return (
    <main className="mkt-section" style={{ paddingTop: 56 }}>
      <Head title="Contact Pelevo" />
      <p className="mkt-kicker">Support & press</p>
      <h1 style={{ fontSize: 'clamp(2.6rem, 6vw, 4.6rem)', margin: '12px 0 12px' }}>Contact us</h1>
      <p className="mkt-lede">
        Product questions, creator claims that need a human, press, and privacy requests. Do not include passwords, OTP codes, CVV, or full NUBAN details.
      </p>
      {status ? <p className="mkt-alert" role="status">{status}</p> : null}
      <form
        className="mkt-form"
        onSubmit={(event) => {
          event.preventDefault();
          form.post('/contact');
        }}
      >
        <label className="mkt-hp">
          Company website
          <input value={form.data.company_website} onChange={(event) => form.setData('company_website', event.target.value)} tabIndex={-1} autoComplete="off" />
        </label>
        <label>
          Name
          <input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" required />
        </label>
        <label>
          Email
          <input type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" required />
        </label>
        <label>
          I am
          <select value={form.data.audience} onChange={(event) => form.setData('audience', event.target.value)}>
            <option value="listener">A listener</option>
            <option value="creator">A creator</option>
            <option value="press">Press</option>
            <option value="privacy">Asking about privacy</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label>
          Subject
          <input value={form.data.subject} onChange={(event) => form.setData('subject', event.target.value)} required />
        </label>
        <label>
          Message
          <textarea value={form.data.message} onChange={(event) => form.setData('message', event.target.value)} required maxLength={5000} />
        </label>
        {Object.values(form.errors).map((error) => (
          <div className="mkt-error" role="alert" key={error}>{error}</div>
        ))}
        <button className="mkt-cta" type="submit" disabled={form.processing} style={{ border: 0, width: 'fit-content' }}>
          {form.processing ? 'Sending…' : 'Send message'}
        </button>
      </form>
    </main>
  );
}

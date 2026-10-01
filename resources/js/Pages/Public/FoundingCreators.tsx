import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';

type Option = { value: string; label: string };

const perks = [
  'Your show ready to claim the day Pelevo goes live',
  'A founding creator badge on your profile',
  'Featured in our launch posts if you are open to it',
  'Free, with no obligation and no spam',
];

export default function FoundingCreators({ frequencies }: { frequencies: Option[] }) {
  const [submitted, setSubmitted] = useState<string | null>(null);
  const form = useForm({
    name: '',
    show_name: '',
    show_url: '',
    email: '',
    social_handle: '',
    publish_frequency: '',
    notes: '',
    company_website: '',
  });

  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    form.post('/founding-creators', {
      preserveScroll: true,
      onSuccess: () => {
        setSubmitted(form.data.show_name);
        form.reset();
      },
    });
  };

  return (
    <main className="mkt-section mkt-fc">
      <Head title="Pelevo Founding Creators — Reserve your spot">
        <meta
          name="description"
          content="Pelevo is a podcast app built for African shows and listeners. Reserve a free founding creator spot and have your show ready to claim on launch day."
        />
      </Head>

      <div className="mkt-fc-intro">
        <p className="mkt-kicker">Founding creators</p>
        <h1>Reserve your spot.</h1>
        <p className="mkt-lede">
          Pelevo is a podcast app built for African shows and listeners, launching this fall. We&apos;re putting
          together a small group of founding creators to have their shows ready to claim the day we go live.
        </p>
        <ul className="mkt-fc-perks">
          {perks.map((perk) => (
            <li key={perk}>{perk}</li>
          ))}
        </ul>
        <p className="mkt-fc-note">
          Takes under a minute. We&apos;ll follow up closer to launch with your claim link.
        </p>
      </div>

      <div className="mkt-fc-card">
        {submitted ? (
          <div className="mkt-fc-done" role="status">
            <span className="mkt-fc-done-icon" aria-hidden="true">✓</span>
            <h2>You&apos;re on the list.</h2>
            <p>
              Thanks for reserving a spot for <b>{submitted}</b>. We sent a confirmation to your
              email and will send your claim link closer to launch.
            </p>
            <div className="mkt-actions">
              <button type="button" className="mkt-ghost" onClick={() => setSubmitted(null)}>
                Add another show
              </button>
              <Link className="mkt-cta" href="/how-it-works#creators">See how claiming works</Link>
            </div>
          </div>
        ) : (
          <form className="mkt-fc-form" onSubmit={submit} noValidate>
            <label className="mkt-hp" aria-hidden="true">
              Company website
              <input
                value={form.data.company_website}
                onChange={(event) => form.setData('company_website', event.target.value)}
                tabIndex={-1}
                autoComplete="off"
              />
            </label>

            <fieldset>
              <legend>About you</legend>
              <Field label="Your name" error={form.errors.name} required>
                <input
                  value={form.data.name}
                  onChange={(event) => form.setData('name', event.target.value)}
                  autoComplete="name"
                  maxLength={120}
                  required
                />
              </Field>
              <Field label="Email address" hint="Where we'll send your claim link before launch." error={form.errors.email} required>
                <input
                  type="email"
                  value={form.data.email}
                  onChange={(event) => form.setData('email', event.target.value)}
                  autoComplete="email"
                  inputMode="email"
                  maxLength={191}
                  required
                />
              </Field>
            </fieldset>

            <fieldset>
              <legend>Your show</legend>
              <Field label="Podcast/show name" error={form.errors.show_name} required>
                <input
                  value={form.data.show_name}
                  onChange={(event) => form.setData('show_name', event.target.value)}
                  maxLength={191}
                  required
                />
              </Field>
              <Field
                label="Where can people find your show?"
                hint="Spotify, Apple Podcasts, or your hosting platform link — whatever's easiest."
                error={form.errors.show_url}
                required
              >
                <input
                  value={form.data.show_url}
                  onChange={(event) => form.setData('show_url', event.target.value)}
                  inputMode="url"
                  autoComplete="url"
                  placeholder="open.spotify.com/show/…"
                  maxLength={500}
                  required
                />
              </Field>
              <div className="mkt-fc-field">
                <span className="mkt-fc-label">How often do you publish? <small>Optional</small></span>
                <div className="mkt-fc-pills" role="radiogroup" aria-label="How often do you publish?">
                  {frequencies.map((option) => (
                    <label key={option.value} className={form.data.publish_frequency === option.value ? 'is-active' : undefined}>
                      <input
                        type="radio"
                        name="publish_frequency"
                        value={option.value}
                        checked={form.data.publish_frequency === option.value}
                        onChange={() => form.setData('publish_frequency', option.value)}
                      />
                      {option.label}
                    </label>
                  ))}
                </div>
                {form.errors.publish_frequency ? <span className="mkt-fc-error">{form.errors.publish_frequency}</span> : null}
              </div>
            </fieldset>

            <fieldset>
              <legend>Stay in touch</legend>
              <Field
                label="Instagram or Twitter/X handle"
                hint="So we can follow you and tag your show at launch, if you're open to it."
                error={form.errors.social_handle}
              >
                <input
                  value={form.data.social_handle}
                  onChange={(event) => form.setData('social_handle', event.target.value)}
                  placeholder="@yourshow"
                  maxLength={120}
                />
              </Field>
              <Field label="Anything you want us to know?" error={form.errors.notes}>
                <textarea
                  value={form.data.notes}
                  onChange={(event) => form.setData('notes', event.target.value)}
                  maxLength={3000}
                  rows={4}
                />
              </Field>
            </fieldset>

            <div className="mkt-fc-submit">
              <button className="mkt-cta" type="submit" disabled={form.processing}>
                {form.processing ? 'Reserving…' : 'Reserve my spot'}
              </button>
              <p>
                By submitting you agree to our <Link href="/privacy">Privacy Policy</Link>. Never share passwords here.
              </p>
            </div>
          </form>
        )}
      </div>
    </main>
  );
}

function Field({ label, hint, error, required, children }: { label: string; hint?: string; error?: string; required?: boolean; children: ReactNode }) {
  return (
    <label className={error ? 'mkt-fc-field has-error' : 'mkt-fc-field'}>
      <span className="mkt-fc-label">
        {label}
        {required ? <em aria-hidden="true"> *</em> : <small>Optional</small>}
      </span>
      {hint ? <span className="mkt-fc-hint">{hint}</span> : null}
      {children}
      {error ? <span className="mkt-fc-error" role="alert">{error}</span> : null}
    </label>
  );
}

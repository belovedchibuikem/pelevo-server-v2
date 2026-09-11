import { Head } from '@inertiajs/react';

const listener = [
  { title: 'Create your space', body: 'Register, verify phone where required, pick interests, and grant only the permissions you understand. Denial never traps you in onboarding.' },
  { title: 'Find the next episode', body: 'Search with debounce and voice, browse categories, open shows even while hydration is pending, and follow without losing your place.' },
  { title: 'Build a real library', body: 'Playlists, collections, likes, downloads with Wi-Fi rules and a storage cap, plus progress that reconciles across devices.' },
  { title: 'Engage without noise', body: 'Comments, gifts after verified IAP, Premium from server entitlement, and notifications that respect quiet hours.' },
];

const creator = [
  { title: 'Claim with evidence', body: 'Email pending, description pending, in review, claimed, rejected, or disputed — the app only unlocks publish routes when the server says claimed.' },
  { title: 'Run more than one show', body: 'Studio membership is scoped per show. Audience, supporters, episodes, reels, and live sit behind actual capabilities, not a role label.' },
  { title: 'Ship a reel the right way', body: 'Request upload scope, transfer with progress, complete, create metadata, then wait for processing. Drafts survive until publish or you delete them.' },
  { title: 'Get paid like an adult', body: 'Payout destinations are verified. Batches, tax, currency, and schedule are operator-governed. The client never invents a balance.' },
];

export default function HowItWorks() {
  return (
    <main className="mkt-section" style={{ paddingTop: 56 }}>
      <Head title="How Pelevo works" />
      <p className="mkt-kicker">Journeys</p>
      <h1 style={{ fontSize: 'clamp(2.6rem, 6vw, 4.6rem)', margin: '12px 0 12px' }}>How Pelevo works</h1>
      <p className="mkt-lede">Two complete paths in one application. Listeners never see a fake creator switch. Creators never see a local wallet.</p>

      <h2 id="listeners">For listeners</h2>
      <p>Home, Search, Library, Earn, and Reels stay on the tab bar. Profile, Premium, AI Hub, and settings stay off-tab on purpose.</p>
      <div className="mkt-grid">
        {listener.map((item) => (
          <article className="mkt-tile" key={item.title}>
            <h3>{item.title}</h3>
            <p>{item.body}</p>
          </article>
        ))}
      </div>

      <h2 id="creators" style={{ marginTop: 48 }}>For creators</h2>
      <p>Verification first. Distribution second. Monetisation only when the claim, the media, and the payout rail all agree.</p>
      <div className="mkt-grid">
        {creator.map((item) => (
          <article className="mkt-tile" key={item.title}>
            <h3>{item.title}</h3>
            <p>{item.body}</p>
          </article>
        ))}
      </div>
    </main>
  );
}

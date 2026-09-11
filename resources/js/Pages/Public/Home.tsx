import { Head, usePage } from '@inertiajs/react';
import PhoneMock from '../../components/public/PhoneMock';
import { StoreButtons } from '../../layouts/public/MarketingLayout';

type Stores = { ios?: string | null; android?: string | null };

const pillars = [
  { chip: 'Home', color: '#ed1c2b', title: 'Discover that starts instantly', body: 'Continue Listening, Top Picks, charts, editorial playlists, and Live Now from a single home payload — cached first, then fresh.' },
  { chip: 'Player', color: '#287be0', title: 'Background audio you can trust', body: 'Lock-screen controls, queue, chapters, speed, sleep timer, and progress that restores across devices without jumping backwards.' },
  { chip: 'Library', color: '#ffbe16', title: 'Playlists and collections', body: 'Saved, liked, followed shows, downloads, and writable collections that stay distinct from playlists.' },
  { chip: 'Earn', color: '#2fa44f', title: 'Listen, complete, withdraw', body: 'Isolated Earn sessions, server heartbeats, 72-hour locks, and payouts only after verified destinations. Never a local coin balance.' },
  { chip: 'Reels', color: '#e05ca8', title: 'Sixty-second studio energy', body: 'For You, Following, and Trending — plus claim-gated capture, drafts, and resumable upload for verified creators.' },
  { chip: 'Studio', color: '#9b5cff', title: 'Claim. Publish. Get paid.', body: 'Email or RSS description claims, multiple shows, audience, gifts, live, tax, and payout schedule from Creator Studio.' },
];

export default function Home() {
  const { stores } = usePage<{ stores?: Stores }>().props;

  return (
    <main>
      <Head title="Pelevo — Listen. Create. Earn.">
        <meta name="description" content="The award-ready home for African podcasts, creator reels, Earn, gifts, and Premium. Built Nigeria-first." />
      </Head>
      <section className="mkt-hero">
        <div className="mkt-hero-copy">
          <div className="mkt-proof-pill">
            <span className="mkt-proof-avatars" aria-hidden="true"><i /><i /><i /></span>
            <span>Made for Africa&apos;s next wave of audio</span>
          </div>
          <p className="mkt-kicker">Nigeria first · globally fluent</p>
          <h1>Your world<br /><em>sounds better</em><br />on Pelevo.</h1>
          <p className="mkt-lede">
            Discover voices that move culture. Listen without limits, turn moments into reels, and build an audience that truly belongs to you.
          </p>
          <StoreButtons stores={stores} id="download" />
          <p className="mkt-microcopy">Free to download · Built for iOS and Android</p>
          <div className="mkt-stats">
            <article><strong>One app</strong><span>Listen, watch, create and earn</span></article>
            <article><strong>Always ready</strong><span>Offline playback and seamless sync</span></article>
            <article><strong>Built on trust</strong><span>Secure sessions and verified payouts</span></article>
          </div>
        </div>
        <div className="mkt-hero-art">
          <span className="mkt-orbit mkt-orbit-one">NEW<br />VOICES</span>
          <span className="mkt-orbit mkt-orbit-two">PLAY<br />ANYWHERE</span>
          <PhoneMock />
          <div className="mkt-floating-card mkt-floating-live"><span />LIVE NOW <b>1.2K</b></div>
          <div className="mkt-floating-card mkt-floating-earn">Earn verified <b>+120</b></div>
        </div>
      </section>

      <section className="mkt-trust-strip" aria-label="Pelevo highlights">
        <span>DISCOVER</span><i />
        <span>LISTEN</span><i />
        <span>CREATE</span><i />
        <span>CONNECT</span><i />
        <span>EARN</span>
      </section>

      <section className="mkt-section">
        <p className="mkt-kicker">Everything in rhythm</p>
        <h2>One beautiful place for every way you listen.</h2>
        <p>Thoughtful discovery, dependable playback and creator tools — designed as one effortless experience.</p>
        <div className="mkt-grid">
          {pillars.map((item) => (
            <article className="mkt-tile" key={item.title}>
              <span className="mkt-chip" style={{ background: `${item.color}22`, color: item.color }}>{item.chip}</span>
              <h3>{item.title}</h3>
              <p>{item.body}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="mkt-section">
        <p className="mkt-kicker">Two journeys. One community.</p>
        <h2>Made for listeners.<br />Built with creators.</h2>
        <p>One product, two journeys — listeners who come for audio, creators who stay for proof of ownership and payouts.</p>
        <div className="mkt-split">
          <div className="mkt-panel">
            <span className="mkt-chip" style={{ background: '#ed1c2b22', color: '#ed1c2b' }}>Listeners</span>
            <h3 style={{ marginTop: 16 }}>From first play to a library that travels.</h3>
            <ol className="mkt-steps">
              <li><b style={{ background: '#ed1c2b22', color: '#ed1c2b' }}>01</b><div><h3>Open Home</h3><p>See cached rails immediately, then live recommendations, charts, and Live Now.</p></div></li>
              <li><b style={{ background: '#287be022', color: '#287be0' }}>02</b><div><h3>Play anywhere</h3><p>Background audio, miniplayer across tabs, chapters, transcripts, and honest buffering.</p></div></li>
              <li><b style={{ background: '#2fa44f22', color: '#2fa44f' }}>03</b><div><h3>Earn or gift</h3><p>Complete eligible episodes online, send gifts after IAP verification, or go Premium from the server entitlement.</p></div></li>
            </ol>
          </div>
          <div className="mkt-panel" id="creators">
            <span className="mkt-chip" style={{ background: '#9b5cff22', color: '#9b5cff' }}>Creators</span>
            <h3 style={{ marginTop: 16 }}>Claim the show. Keep the audience.</h3>
            <ol className="mkt-steps">
              <li><b style={{ background: '#9b5cff22', color: '#9b5cff' }}>01</b><div><h3>Verify ownership</h3><p>Email or RSS description codes, review states, and claimed access — never a local “I’m a creator” switch.</p></div></li>
              <li><b style={{ background: '#e05ca822', color: '#e05ca8' }}>02</b><div><h3>Publish reels</h3><p>Record or upload up to 60 seconds, resume failed transfers, and wait for processing and moderation.</p></div></li>
              <li><b style={{ background: '#ffbe1622', color: '#ffbe16' }}>03</b><div><h3>Collect what you earned</h3><p>Gifts, reel monetisation, and creator payouts with destination checks, tax, and schedule in Studio.</p></div></li>
            </ol>
          </div>
        </div>
      </section>

      <section className="mkt-section">
        <p className="mkt-kicker">Trust by design</p>
        <h2>Privacy is a product feature.</h2>
        <p>Tokens stay in secure storage. Analytics drop search content, receipts, and payout identifiers. You can export or delete an account without theatre.</p>
        <div className="mkt-grid">
          <article className="mkt-tile"><h3>Secure sessions</h3><p>Rotating refresh tokens, device revoke, and sign-out-all. Compromised reuse routes you back to sign-in with the deep link kept.</p></article>
          <article className="mkt-tile"><h3>Honest money</h3><p>No demo wallets in release. IAP credits only after server verification. Withdrawals use a persisted idempotency key.</p></article>
          <article className="mkt-tile"><h3>Your library</h3><p>Downloads live in the app sandbox. Logout clears secrets and lets you decide what happens to public offline files.</p></article>
        </div>
      </section>

      <section className="mkt-section">
        <h2>Questions, answered.</h2>
        <div className="mkt-faq">
          <details open>
            <summary>Is Earn free money for any podcast?</summary>
            <p>No. Earn is an online, policy-gated programme. Sessions, heartbeats, locks, and awards are decided by Pelevo. Eligibility, country, and episode locks can change without an app update.</p>
          </details>
          <details>
            <summary>Do I need to be a creator to use Pelevo?</summary>
            <p>Listeners get the full Home, Search, Library, Reels, and optional Earn or Premium experience. Creator Studio, reel upload, and payouts unlock only after a claimed show.</p>
          </details>
          <details>
            <summary>Where is my data processed?</summary>
            <p>Pelevo is Nigeria-first and uses contracted processors for media, messaging, and crash reporting. Read the Privacy Policy for retention, export, and deletion.</p>
          </details>
        </div>
      </section>

      <section className="mkt-band">
        <p className="mkt-kicker">Ready when you are</p>
        <h2 style={{ marginTop: 8 }}>Install Pelevo. Keep listening when the network blinks.</h2>
        <p className="mkt-lede">iOS and Android share one visual system, one API contract, and one standard for money: the server is the source of truth.</p>
        <StoreButtons stores={stores} />
      </section>
    </main>
  );
}

import { Head, Link, usePage } from '@inertiajs/react';
import PhoneMock from '../../components/public/PhoneMock';
import { StoreButtons } from '../../layouts/public/MarketingLayout';

type Stores = { ios?: string | null; android?: string | null };

const story = [
  {
    id: 'problem',
    kicker: 'The problem',
    title: 'You know the feeling.',
    body: 'Someone sends you a voice note with "listen to this episode," and twenty minutes later you\'re three apps deep and still haven\'t found it. Global platforms weren\'t built to help you find what\'s actually good here — they weren\'t built for here at all.',
    accent: '#ed1c2b',
  },
  {
    id: 'discovery',
    kicker: 'Discovery',
    title: 'Trending, for real.',
    body: 'See what\'s actually rising in Nigeria right now — not a global chart that treats Lagos like a rounding error. Rate shows, follow creators, and get recommendations that learn what you\'re into, not what\'s popular in a market you\'re not part of.',
    accent: '#287be0',
  },
  {
    id: 'reels',
    kicker: 'Reels',
    title: 'Clips that don\'t leave you hanging.',
    body: 'You\'ve seen the clip. You want the episode. Pelevo\'s Reels take you straight from a 60-second moment to the full show — no searching, no losing it, no forgetting the name by the time you open another app.',
    accent: '#e05ca8',
  },
  {
    id: 'support',
    kicker: 'Support',
    title: 'Show love that actually lands.',
    body: 'Gift coins to the creators you can\'t stop listening to — paid out through Flutterwave and Paystack, built around how creators here actually get paid. Real support, no friction.',
    accent: '#ffbe16',
  },
];

export default function Home() {
  const { stores } = usePage<{ stores?: Stores }>().props;

  return (
    <main>
      <Head title="Pelevo — African podcasts, finally home.">
        <meta
          name="description"
          content="Discover the shows Nigerians are actually talking about, support the creators behind them, and never lose a good episode in a WhatsApp forward again."
        />
      </Head>

      <section className="mkt-hero">
        <div className="mkt-hero-copy">
          <div className="mkt-proof-pill">
            <span className="mkt-proof-avatars" aria-hidden="true"><i /><i /><i /></span>
            <span>First 500 founders circle · Nigeria</span>
          </div>
          <p className="mkt-kicker">African podcasts, finally home</p>
          <h1>African podcasts,<br /><em>finally home.</em></h1>
          <p className="mkt-lede">
            Discover the shows Nigerians are actually talking about, support the creators behind them,
            and never lose a good episode in a WhatsApp forward again.
          </p>
          <div className="mkt-actions" id="download">
            <a className="mkt-cta" href="#join">Get Pelevo — Join the first 500</a>
            <Link className="mkt-ghost" href="#creators">I&apos;m a podcast creator →</Link>
          </div>
          <p className="mkt-microcopy">iOS and Android · Verified early sign-ups get a welcome coin bonus</p>
        </div>
        <div className="mkt-hero-art">
          <span className="mkt-orbit mkt-orbit-one">LAGOS<br />RISING</span>
          <span className="mkt-orbit mkt-orbit-two">FULL<br />EPISODE</span>
          <PhoneMock />
          <div className="mkt-floating-card mkt-floating-live"><span />TRENDING <b>NG</b></div>
          <div className="mkt-floating-card mkt-floating-earn">Clip → episode <b>now</b></div>
        </div>
      </section>

      <section className="mkt-trust-strip" aria-label="Pelevo pillars">
        <span>DISCOVER</span><i />
        <span>REELS</span><i />
        <span>SUPPORT</span><i />
        <span>CREATE</span><i />
        <span>GET PAID</span>
      </section>

      {story.map((item) => (
        <section className="mkt-section" id={item.id} key={item.id}>
          <p className="mkt-kicker" style={{ color: item.accent }}>{item.kicker}</p>
          <h2>{item.title}</h2>
          <p>{item.body}</p>
        </section>
      ))}

      <section className="mkt-section" id="creators">
        <p className="mkt-kicker" style={{ color: '#9b5cff' }}>For creators</p>
        <h2>Your show. Your audience. Your dashboard.</h2>
        <p>
          Claim your podcast, see who&apos;s really listening, reply to comments, and turn your best moments
          into Reels that pull new listeners straight into your full episodes. Payouts that work the way you
          actually get paid — no chasing a system built for somewhere else.
        </p>
        <div className="mkt-actions" style={{ marginTop: 28 }}>
          <Link className="mkt-cta" href="/contact?audience=creator">Claim your podcast →</Link>
          <Link className="mkt-ghost" href="/how-it-works#creators">See how claiming works</Link>
        </div>
      </section>

      <section className="mkt-band" id="join">
        <p className="mkt-kicker">Launch</p>
        <h2 style={{ marginTop: 8 }}>The first 500 get in early.</h2>
        <p className="mkt-lede">
          Verified sign-ups in our first wave get a welcome coin bonus — a small thank-you for being here
          before everyone else is.
        </p>
        <div className="mkt-actions">
          {(stores?.ios || stores?.android) ? (
            <>
              {stores.ios ? <a className="mkt-cta" href={stores.ios}>Claim your spot</a> : <a className="mkt-cta" href={stores.android!}>Claim your spot</a>}
              {stores.ios && stores.android ? <a className="mkt-ghost" href={stores.android}>Get it on Google Play</a> : null}
            </>
          ) : (
            <Link className="mkt-cta" href="/contact?audience=listener&subject=First%20500">Claim your spot</Link>
          )}
        </div>
        <div style={{ marginTop: 22 }}>
          <StoreButtons stores={stores} />
        </div>
      </section>
    </main>
  );
}

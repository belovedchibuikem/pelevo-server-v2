import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';

type Flash = { status?: string };
type Stores = { ios?: string | null; android?: string | null };

const links = [
  { href: '/#discovery', label: 'Discover' },
  { href: '/#creators', label: 'Creators' },
  { href: '/how-it-works', label: 'How it works' },
  { href: '/privacy', label: 'Privacy' },
  { href: '/contact', label: 'Contact' },
];

export default function MarketingLayout({ children }: { children: ReactNode }) {
  const page = usePage<{ flash?: Flash; stores?: Stores }>();
  const [open, setOpen] = useState(false);
  const path = page.url.split('#')[0];

  useEffect(() => {
    const hash = window.location.hash;
    if (hash) document.querySelector(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, [page.url]);

  return (
    <div className="mkt">
      <Head>
        <meta head-key="og:site" property="og:site_name" content="Pelevo" />
      </Head>
      <div className="mkt-aurora" aria-hidden="true" />
      <div className="mkt-grain" aria-hidden="true" />
      <header className="mkt-nav">
        <Link className="mkt-logo mkt-logo-nav" href="/" aria-label="Pelevo home">
          <img className="mkt-logo-img mkt-logo-img-nav" src="/images/brand/logo-nav.png" alt="Pelevo" width={40} height={48} />
        </Link>
        <button className="mkt-menu" type="button" aria-expanded={open} aria-controls="mkt-nav" onClick={() => setOpen((value) => !value)}>
          {open ? 'Close' : 'Menu'}
        </button>
        <nav id="mkt-nav" className={open ? 'mkt-links is-open' : 'mkt-links'} aria-label="Marketing">
          {links.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              aria-current={path === item.href.split('#')[0] ? 'page' : undefined}
              onClick={() => setOpen(false)}
            >
              {item.label}
            </Link>
          ))}
          <Link className="mkt-cta" href="/#join" onClick={() => setOpen(false)}>
            Get Pelevo
          </Link>
        </nav>
      </header>
      {children}
      <footer className="mkt-footer">
        <div>
          <Link className="mkt-logo mkt-logo-footer" href="/" aria-label="Pelevo home">
            <img className="mkt-logo-img mkt-logo-img-footer" src="/images/brand/logo-footer.png" alt="Pelevo" width={148} height={148} />
          </Link>
          <p style={{ marginTop: 14, maxWidth: 360 }}>
            Pelevo — built for African podcasts, and the people who make them worth listening to.
          </p>
        </div>
        <div>
          <h4>Product</h4>
          <ul>
            <li><Link href="/#discovery">For listeners</Link></li>
            <li><Link href="/#creators">For creators</Link></li>
            <li><Link href="/#join">Join the first 500</Link></li>
          </ul>
        </div>
        <div>
          <h4>Company</h4>
          <ul>
            <li><Link href="/contact">Contact us</Link></li>
            <li><Link href="/privacy">Privacy Policy</Link></li>
            <li><Link href="/terms">Terms of Use</Link></li>
          </ul>
        </div>
        <div>
          <h4>Trust</h4>
          <ul>
            <li>No client-side wallets</li>
            <li>Secure sessions</li>
            <li>Moderated community</li>
          </ul>
        </div>
      </footer>
    </div>
  );
}

export function StoreButtons({ stores, id }: { stores?: Stores; id?: string }) {
  const ios = stores?.ios;
  const android = stores?.android;

  return (
    <div className="mkt-actions" id={id}>
      {ios ? (
        <a className="mkt-cta" href={ios}>Download on the App Store</a>
      ) : (
        <span className="mkt-cta" aria-disabled="true">App Store · coming soon</span>
      )}
      {android ? (
        <a className="mkt-ghost" href={android}>Get it on Google Play</a>
      ) : (
        <span className="mkt-ghost" aria-disabled="true">Google Play · coming soon</span>
      )}
    </div>
  );
}

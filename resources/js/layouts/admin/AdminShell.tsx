import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import ThemeToggle from '../../components/admin/ThemeToggle';
import { useFocusScope } from '../../components/admin/useFocusScope';

type NavItem = { label: string; href: string; group: 'Operate' | 'Engage' | 'Platform'; keywords?: string; permission?: string };
type SearchGroup = { label: string; items: Array<{ title: string; subtitle: string; href: string }> };

const navigation: NavItem[] = [
  { label: 'Command Center', href: '/admin', group: 'Operate', keywords: 'dashboard pulse health incidents' },
  { label: 'Operations', href: '/admin/operations', group: 'Operate', keywords: 'scheduler cron horizon workers queue failed jobs', permission: 'audit.view' },
  { label: 'Users', href: '/admin/users', group: 'Operate', keywords: 'people sessions devices wallets sanctions', permission: 'users.view' },
  { label: 'Catalog', href: '/admin/catalog', group: 'Operate', keywords: 'shows episodes rss podcast index discovery', permission: 'catalog.write' },
  { label: 'Creators & Claims', href: '/admin/creators', group: 'Operate', keywords: 'studios verification disputes monetization', permission: 'claims.decide' },
  { label: 'Claim Queue', href: '/admin/claims', group: 'Operate', keywords: 'review verification rss ownership', permission: 'claims.decide' },
  { label: 'Reels & Live', href: '/admin/reels-live', group: 'Operate', keywords: 'processing reports appeals livestream', permission: 'moderation.act' },
  { label: 'Community', href: '/admin/community', group: 'Operate', keywords: 'comments reports sanctions sla', permission: 'moderation.act' },
  { label: 'Finance', href: '/admin/finance', group: 'Operate', keywords: 'ledger iap earn withdrawals payouts reconciliation', permission: 'finance.view' },
  { label: 'Communications', href: '/admin/communications', group: 'Engage', keywords: 'notifications audiences templates delivery', permission: 'broadcast.send' },
  { label: 'Referrals & Growth', href: '/admin/growth', group: 'Engage', keywords: 'programs funnel rewards fraud caps', permission: 'settings.write' },
  { label: 'CMS & Discovery', href: '/admin/cms', group: 'Engage', keywords: 'pages help rails editorial announcements', permission: 'settings.write' },
  { label: 'AI Desk', href: '/admin/ai', group: 'Platform', keywords: 'jobs cost prompts providers quotas safety', permission: 'ai.manage' },
  { label: 'Support', href: '/admin/support', group: 'Platform', keywords: 'tickets feedback sla assignment', permission: 'users.view' },
  { label: 'Analytics', href: '/admin/analytics', group: 'Platform', keywords: 'retention listening search funnels exports', permission: 'audit.view' },
  { label: 'Settings', href: '/admin/settings', group: 'Platform', keywords: 'flags limits providers locales storage security smtp api podcast mux', permission: 'settings.write' },
  { label: 'API & SMTP', href: '/admin/settings/integrations', group: 'Platform', keywords: 'podcast index mux ai smtp paystack paypal flutterwave ffmpeg s3 credentials', permission: 'settings.write' },
  { label: 'Audit & Security', href: '/admin/audit', group: 'Platform', keywords: 'admin logins approvals events history', permission: 'audit.view' },
  { label: 'Profile & Security', href: '/admin/profile', group: 'Platform', keywords: 'account password mfa sessions notifications' },
];

const groups: NavItem['group'][] = ['Operate', 'Engage', 'Platform'];

export default function AdminShell({ children }: { children: ReactNode }) {
  const { adminAuth } = usePage<{ adminAuth?: { name: string; email: string; permissions: string[]; environment: string } | null }>().props;
  const paletteRef = useRef<HTMLElement>(null);
  const drawerRef = useRef<HTMLElement>(null);
  const searchTrigger = useRef<HTMLButtonElement>(null);
  const drawerTrigger = useRef<HTMLButtonElement>(null);
  const [desktop, setDesktop] = useState(() => window.matchMedia('(min-width: 1024px)').matches);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(() => localStorage.getItem('pelevo.admin.nav.collapsed') === '1');
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [searchGroups, setSearchGroups] = useState<SearchGroup[]>([]);
  const [searching, setSearching] = useState(false);

  useFocusScope(paletteOpen, paletteRef, () => setPaletteOpen(false), searchTrigger);
  useFocusScope(drawerOpen && !desktop, drawerRef, () => setDrawerOpen(false), drawerTrigger);
  useEffect(() => { const media = window.matchMedia('(min-width: 1024px)'); const sync = () => { setDesktop(media.matches); if (media.matches) setDrawerOpen(false); }; media.addEventListener('change', sync); return () => media.removeEventListener('change', sync); }, []);

  useEffect(() => {
    const listener = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setPaletteOpen(value => !value);
      }
      if (event.key === 'Escape') setPaletteOpen(false);
    };
    window.addEventListener('keydown', listener);
    return () => window.removeEventListener('keydown', listener);
  }, []);

  useEffect(() => {
    if (!paletteOpen || query.trim().length < 2) { setSearchGroups([]); setSearching(false); return; }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setSearching(true);
      try {
        const response = await fetch(`/api/admin/v1/search?q=${encodeURIComponent(query.trim())}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
        const body = await response.json();
        if (response.ok) setSearchGroups(body.data.groups);
      } catch (error) {
        if (!controller.signal.aborted) setSearchGroups([]);
      } finally {
        if (!controller.signal.aborted) setSearching(false);
      }
    }, 250);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [paletteOpen, query]);

  const allowedNavigation = useMemo(() => navigation.filter(item => !item.permission || adminAuth?.permissions.includes(item.permission)), [adminAuth]);
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle ? allowedNavigation.filter(item => `${item.label} ${item.keywords ?? ''}`.toLowerCase().includes(needle)) : allowedNavigation;
  }, [allowedNavigation, query]);

  const toggleCollapsed = () => setCollapsed(value => {
    localStorage.setItem('pelevo.admin.nav.collapsed', value ? '0' : '1');
    return !value;
  });

  return <div className={`pelevo-admin min-h-screen bg-slate-50 text-slate-900 lg:grid ${collapsed ? 'lg:grid-cols-[84px_1fr]' : 'lg:grid-cols-[268px_1fr]'}`}>
    {drawerOpen && <button aria-label="Close navigation" className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" onClick={() => setDrawerOpen(false)} />}
    <aside ref={drawerRef} inert={paletteOpen || (!desktop && !drawerOpen)} role={!desktop && drawerOpen ? 'dialog' : undefined} aria-modal={!desktop && drawerOpen ? true : undefined} aria-label="Administrative navigation" className={`fixed inset-y-0 left-0 z-40 flex w-[268px] flex-col border-r border-slate-200 bg-white transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 ${drawerOpen ? 'translate-x-0' : '-translate-x-full'} ${collapsed ? 'lg:w-[84px]' : ''}`}>
      <div className="flex h-18 items-center justify-between border-b border-slate-200 px-5">
        <Link href="/admin" className="overflow-hidden whitespace-nowrap font-black tracking-[.16em]">{collapsed ? 'P' : <>PELEVO <span className="text-xs text-teal-700">OPS</span></>}</Link>
        <button aria-label="Collapse navigation" className="hidden rounded-lg border border-slate-200 px-2 py-1 text-slate-500 hover:text-slate-900 lg:block" onClick={toggleCollapsed}>{collapsed ? '›' : '‹'}</button>
      </div>
      <nav aria-label="Administration" className="flex-1 overflow-y-auto p-3">
        {groups.map(group => <section className="mb-5" key={group}>
          {!collapsed && <p className="px-3 pb-2 text-[10px] font-bold uppercase tracking-[.18em] text-slate-500">{group}</p>}
          <div className="grid gap-1">{allowedNavigation.filter(item => item.group === group).map(item => {
            const active = window.location.pathname === item.href || (item.href !== '/admin' && window.location.pathname.startsWith(`${item.href}/`));
            return <Link title={item.label} onClick={() => setDrawerOpen(false)} className={`min-h-10 rounded-lg px-3 py-2 text-sm transition ${active ? 'bg-teal-50 text-slate-900 shadow-[inset_3px_0_#14b8a6]' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'} ${collapsed ? 'text-center lg:px-1 lg:text-[10px]' : ''}`} href={item.href} key={item.label}>{collapsed ? item.label.split(' ').map(word => word[0]).join('').slice(0, 2) : item.label}</Link>;
          })}</div>
        </section>)}
      </nav>
    </aside>
    <div className="min-w-0" inert={paletteOpen || (!desktop && drawerOpen)}>
      <header className="sticky top-0 z-20 flex h-18 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
        <button ref={drawerTrigger} aria-label="Open navigation" className="rounded-lg border border-slate-200 px-3 py-2 lg:hidden" onClick={() => setDrawerOpen(true)}>Menu</button>
        <button ref={searchTrigger} className="flex min-w-0 flex-1 items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-left text-sm text-slate-500 hover:border-slate-300" onClick={() => setPaletteOpen(true)}><span className="truncate">Search operations and routes</span><kbd className="ml-3 hidden rounded border border-slate-200 px-2 py-0.5 text-[10px] sm:inline">Ctrl K</kbd></button>
        <ThemeToggle />
        <span className="hidden rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-[10px] font-bold text-amber-800 sm:inline">{adminAuth?.environment?.toUpperCase() ?? 'UNKNOWN'}</span>
        <Link aria-label="Profile and security" title={adminAuth?.email} className="grid size-9 place-items-center rounded-full bg-teal-400 font-bold text-slate-950" href="/admin/profile">{adminAuth?.name?.charAt(0).toUpperCase() || 'A'}</Link>
      </header>
      <div className="min-w-0">{children}</div>
    </div>
    {paletteOpen && <div className="fixed inset-0 z-50 grid place-items-start bg-slate-900/40 px-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Command palette" onMouseDown={() => setPaletteOpen(false)}>
      <section ref={paletteRef} className="w-full max-w-2xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" onMouseDown={event => event.stopPropagation()}>
        <input aria-label="Search admin routes and records" autoFocus className="w-full border-0 border-b border-slate-200 bg-transparent px-5 py-4 text-base outline-none placeholder:text-slate-400" placeholder="Search admin routes…" value={query} onChange={event => setQuery(event.target.value)} />
        <div className="max-h-[55vh] overflow-y-auto p-2">{filtered.length > 0 && <p className="px-4 pb-1 pt-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">Admin routes</p>}{filtered.map(item => <Link className="flex justify-between rounded-xl px-4 py-3 text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900" href={item.href} key={item.label} onClick={() => setPaletteOpen(false)}><span>{item.label}</span><small className="text-slate-500">{item.group}</small></Link>)}{searching && <p className="px-4 py-3 text-sm text-slate-500">Searching authorized records…</p>}{searchGroups.map(group => <section key={group.label}><p className="px-4 pb-1 pt-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">{group.label}</p>{group.items.map(item => <Link className="flex items-center justify-between gap-4 rounded-xl px-4 py-3 text-sm hover:bg-slate-100" href={item.href} key={`${group.label}-${item.href}`} onClick={() => setPaletteOpen(false)}><span className="min-w-0 truncate">{item.title}</span><small className="shrink-0 text-slate-500">{item.subtitle}</small></Link>)}</section>)}{!searching && filtered.length === 0 && searchGroups.length === 0 && <p className="p-8 text-center text-sm text-slate-500">No authorized route or record matches this search.</p>}</div>
        <div className="flex justify-between border-t border-slate-200 px-4 py-3 text-[11px] text-slate-500"><span>Permission-filtered route and entity search</span><button onClick={() => { setPaletteOpen(false); router.post('/admin/logout'); }}>Sign out</button></div>
      </section>
    </div>}
  </div>;
}

import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import FinanceAction, { EmptyQueue } from '../../components/admin/FinanceAction';

type Row = Record<string, string | number | null | undefined>;
type Desk = { key: string; title: string; blurb: string; count: number };
type Attention = { label: string; count: number; desk: string; severity: 'info' | 'warning' };
type Products = { fees: Row[]; fx: Row[]; gifts: Row[]; packs: Row[]; minWithdrawCoins: number; dailyEarnCap: number; reelQualifiedViewPcn: number };
type Earn = { liability: number; issuedToday: number; review: Row[]; campaigns: Row[] };
type Premium = { mrrMinor: number; active: number; churnedThisMonth: number; failedInvoices: number; refunds: number; plans: Row[]; subscriptions: Row[]; invoices: Row[] };
type Props = {
  desk: string;
  desks: Desk[];
  attention: Attention[];
  products: Products;
  iap: { unmatched: Row[] };
  earn: Earn;
  withdrawals: { queued: Row[]; processing: Row[]; failed: Row[] };
  payouts: { batches: Row[]; revenue: Row[] };
  premium: Premium;
  ledger: { accounts: Row[]; transactions: Row[] };
  exceptions: Row[];
  reconciliation: Row[];
  settlements: Row[];
  freshAt: string;
};

const desksOrder = ['fx', 'iap', 'earn', 'withdrawals', 'payouts', 'premium', 'ledger'] as const;

export default function Finance(props: Props) {
  const [refreshing, setRefreshing] = useState(false);
  const active = desksOrder.includes(props.desk as typeof desksOrder[number]) ? props.desk : 'overview';
  const openCount = props.attention.reduce((sum, item) => sum + item.count, 0);
  const refresh = () => { setRefreshing(true); router.reload({ onFinish: () => setRefreshing(false) }); };

  return <main className="min-w-0 px-4 py-6 sm:px-7 xl:px-10">
    <Head title="Finance & fraud" />
    <div className="mx-auto max-w-[1600px]">
      <nav aria-label="Breadcrumb" className="text-xs font-semibold uppercase tracking-[.16em] text-teal-700">Operations / Finance</nav>
      <header className="mt-3 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-semibold tracking-tight">Finance and fraud desk</h1>
          <p className="mt-2 max-w-3xl text-sm text-slate-500">Priority queues first, then the seven money surfaces from the product spec. Ledger rows are never edited — corrections are reversing entries with a reason and fresh MFA.</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-xs text-slate-500">Updated {new Date(props.freshAt).toLocaleString()}</span>
          <Link href="/admin/finance-records" className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Open record explorer</Link>
          <button disabled={refreshing} onClick={refresh} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold hover:border-teal-500 disabled:opacity-50">{refreshing ? 'Refreshing…' : 'Refresh'}</button>
        </div>
      </header>

      <section aria-label="Attention required" className="mt-7">
        <h2 className="text-sm font-bold uppercase tracking-[.14em] text-slate-500">Attention required</h2>
        {openCount === 0 ? <p className="admin-panel mt-3 rounded-xl p-5 text-sm text-slate-500">No withdrawal, earn-fraud, IAP, drift, or payout queues need action.</p>
          : <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">{props.attention.filter(item => item.count > 0).map(item => <Link key={item.label} href={`/admin/finance?desk=${item.desk}`} className={`rounded-xl p-4 ${item.severity === 'warning' ? 'border border-amber-300 bg-amber-50' : 'admin-card'}`}><span className="block text-sm font-medium">{item.label}</span><strong className="mt-3 block text-2xl tabular-nums">{item.count.toLocaleString()}</strong><small className="text-slate-500">Open desk →</small></Link>)}</div>}
      </section>

      <section aria-label="Finance desks" className="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {props.desks.map(desk => <Link preserveScroll key={desk.key} href={`/admin/finance?desk=${desk.key}`} className={`rounded-xl p-4 transition ${active === desk.key ? 'admin-card-active' : 'admin-card'}`}>
          <span className="block text-sm font-medium">{desk.title}</span>
          <strong className="mt-3 block text-2xl tabular-nums">{desk.count.toLocaleString()}</strong>
          <small className="mt-1 block text-slate-500">{desk.blurb}</small>
        </Link>)}
      </section>

      <section className="admin-panel mt-8 overflow-hidden rounded-2xl">
        <div className="border-b border-slate-200 p-5">
          <h2 className="font-semibold">{props.desks.find(desk => desk.key === active)?.title ?? 'Choose a desk'}</h2>
          <p className="mt-1 text-sm text-slate-500">{props.desks.find(desk => desk.key === active)?.blurb ?? 'Select one of the seven finance surfaces to review queues and governed actions.'}</p>
        </div>
        <div className="p-5">{active === 'fx' ? <FxDesk products={props.products} accounts={props.ledger.accounts} /> : active === 'iap' ? <IapDesk unmatched={props.iap.unmatched} /> : active === 'earn' ? <EarnDesk earn={props.earn} /> : active === 'withdrawals' ? <WithdrawalDesk withdrawals={props.withdrawals} /> : active === 'payouts' ? <PayoutDesk payouts={props.payouts} /> : active === 'premium' ? <PremiumDesk premium={props.premium} /> : active === 'ledger' ? <LedgerDesk ledger={props.ledger} exceptions={props.exceptions} reconciliation={props.reconciliation} settlements={props.settlements} /> : <p className="text-sm text-slate-500">Open a desk above. Start with any amber attention card when a queue is non-zero.</p>}</div>
      </section>
    </div>
  </main>;
}

function FxDesk({ products, accounts }: { products: Products; accounts: Row[] }) {
  return <div className="grid gap-8 lg:grid-cols-2">
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold">Platform fee versions</h3>
        <FinanceAction title="Publish fee version" description="Inserts a new effective-dated fee. Existing gifts and reel accruals keep the fee version recorded on those ledger rows." trigger="Publish fee version" method="POST" endpoint="/api/admin/v1/finance/fees" fields={[{ name: 'type', label: 'Fee type', type: 'select', options: ['gift_platform', 'reel_platform'] }, { name: 'percent', label: 'Platform fee percent', type: 'number', min: 0, max: 100, step: 0.01, defaultValue: 10 }, { name: 'effective_at', label: 'Effective from', type: 'datetime-local' }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </div>
      <List rows={products.fees} empty="No fee versions are stored.">{row => <Item key={String(row.id)}><div><b>{String(row.type)}</b><small className="block text-slate-500">Effective {formatDate(row.effective_at)}{row.reason ? ` · ${String(row.reason)}` : ''}</small></div><strong>{((Number(row.basis_points) || 0) / 100).toFixed(2)}%</strong></Item>}</List>
    </div>
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold">FX versions</h3>
        <FinanceAction title="Publish FX version" description="Stores a dated FX rate used by future payout conversions. Historical payouts keep the rate attached at reservation." trigger="Publish FX rate" method="POST" endpoint="/api/admin/v1/finance/fx" fields={[{ name: 'base_unit', label: 'Base unit', defaultValue: 'PCN' }, { name: 'quote_currency', label: 'Quote currency', defaultValue: 'NGN' }, { name: 'rate', label: 'Rate', type: 'number', min: 0, step: 0.00000001 }, { name: 'source', label: 'Source', defaultValue: 'manual' }, { name: 'effective_at', label: 'Effective from', type: 'datetime-local' }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </div>
      <List rows={products.fx} empty="No FX rates stored yet.">{row => <Item key={String(row.id)}><div><b>{row.base_unit} → {row.quote_currency}</b><small className="block text-slate-500">{String(row.source)} · {formatDate(row.effective_at)}</small></div><strong className="tabular-nums">{Number(row.rate).toLocaleString()}</strong></Item>}</List>
    </div>
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold">Coin packs</h3>
        <FinanceAction title="Publish coin pack" description="Maps an App Store or Play SKU to PCN. Coin amounts cannot change after a receipt exists — retire the SKU and add a new product id." trigger="Publish coin pack" method="POST" endpoint="/api/admin/v1/finance/coin-products" fields={[{ name: 'store', label: 'Store', type: 'select', options: ['apple', 'google'] }, { name: 'product_id', label: 'Store product id' }, { name: 'coins', label: 'Coins', type: 'number', min: 1 }, { name: 'unit', label: 'Unit', type: 'select', options: ['PCN'] }, { name: 'active', label: 'Active', type: 'select', options: ['1', '0'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </div>
      <List rows={products.packs} empty="No store coin products configured.">{row => <Item key={String(row.id)}>
        <div><b>{String(row.product_id)}</b><small className="block text-slate-500">{String(row.store)} · {row.active === 0 || row.active === false ? 'retired' : 'active'}</small></div>
        <div className="flex items-center gap-2">
          <strong>{Number(row.coins).toLocaleString()} {row.unit}</strong>
          <FinanceAction title={row.active === 0 || row.active === false ? 'Restore coin pack' : 'Retire coin pack'} description="Retiring hides the SKU from new purchases. Existing receipts stay credited at the original coin amount." trigger={row.active === 0 || row.active === false ? 'Restore' : 'Retire'} method="PUT" endpoint={`/api/admin/v1/finance/coin-products/${row.id}`} fields={[{ name: 'active', label: 'Active', type: 'select', options: row.active === 0 || row.active === false ? ['1'] : ['0'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
        </div>
      </Item>}</List>
    </div>
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold">Gift types</h3>
        <FinanceAction title="Publish gift type" description="Adds a catalog gift. Changing coins increments the catalog version; gifts already sent keep their ledger amounts." trigger="Publish gift type" method="POST" endpoint="/api/admin/v1/finance/gift-types" fields={[{ name: 'slug', label: 'Slug' }, { name: 'name', label: 'Name' }, { name: 'coins', label: 'Coins', type: 'number', min: 1 }, { name: 'active', label: 'Active', type: 'select', options: ['1', '0'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </div>
      <List rows={products.gifts} empty="No gift types configured.">{row => <Item key={String(row.id)}>
        <div><b>{String(row.name)}</b><small className="block text-slate-500">{String(row.slug)} · v{String(row.version ?? 1)}{row.active === 0 || row.active === false ? ' · retired' : ''}</small></div>
        <div className="flex items-center gap-2">
          <strong>{Number(row.coins).toLocaleString()} coins</strong>
          <FinanceAction title="Update gift type" description="Publishes a new catalog version for this slug. Completed gifts are not rewritten." trigger="Update" method="PUT" endpoint={`/api/admin/v1/finance/gift-types/${row.id}`} fields={[{ name: 'name', label: 'Name', defaultValue: String(row.name ?? '') }, { name: 'coins', label: 'Coins', type: 'number', min: 1, defaultValue: Number(row.coins) }, { name: 'active', label: 'Active', type: 'select', options: ['1', '0'], defaultValue: row.active === 0 || row.active === false ? '0' : '1' }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
        </div>
      </Item>}</List>
    </div>
    <div className="lg:col-span-2 grid gap-3 sm:grid-cols-3">
      <Stat label="Withdrawal minimum" value={`${products.minWithdrawCoins.toLocaleString()} ECN`} />
      <Stat label="Daily earn completion cap" value={String(products.dailyEarnCap)} />
      <Stat label="Qualified reel view" value={`${products.reelQualifiedViewPcn} PCN`} />
    </div>
    <p className="lg:col-span-2 text-sm text-slate-500">Withdrawal minimum and reel eligibility also live in versioned product configuration. Publish those from <Link className="font-semibold text-teal-700" href="/admin/settings/configuration">Settings → Product configuration</Link>. Env caps still apply until a configuration version overrides them.</p>
    <div className="lg:col-span-2">
      <h3 className="font-semibold">Balance sheet</h3>
      <List rows={accounts} empty="No financial accounts posted yet.">{row => <Item key={String(row.id)}><div><b>{String(row.type)}</b><small className="block text-slate-500">{String(row.unit)}</small></div><strong className="tabular-nums">{Number(row.balance).toLocaleString()}</strong></Item>}</List>
    </div>
  </div>;
}

function IapDesk({ unmatched }: { unmatched: Row[] }) {
  return <>
    <p className="mb-4 text-sm text-slate-500">Only receipts that are not verified or credited. Full history lives in the record explorer.</p>
    <List rows={unmatched} empty="No unmatched or failed IAP receipts.">{row => <Item key={String(row.id)}><div><b>{String(row.store)}</b><code className="block text-xs text-slate-500">{String(row.original_transaction_id)}</code></div><Badge value={String(row.state ?? 'received')} /></Item>}</List>
    <div className="mt-4"><Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=receipts">All IAP receipts →</Link></div>
  </>;
}

function EarnDesk({ earn }: { earn: Earn }) {
  return <>
    <div className="mb-6 grid gap-3 sm:grid-cols-2">
      <Stat label="Earn coins outstanding" value={earn.liability.toLocaleString()} />
      <Stat label="Issued today" value={earn.issuedToday.toLocaleString()} />
    </div>
    <h3 className="font-semibold">Suspected farms / review queue</h3>
    <List rows={earn.review} empty="No earn sessions are in fraud review.">{row => <Item key={String(row.id)}>
      <code className="text-xs">{String(row.id)}</code>
      <div className="flex flex-wrap gap-2">
        <FinanceAction title="Clear earn session" description="Clears the fraud hold and returns the session to active earning. Requires a reason and fresh MFA." trigger="Clear" method="PUT" endpoint={`/api/admin/v1/finance/earn/${row.id}`} fields={[{ name: 'decision', label: 'Decision', type: 'select', options: ['clear'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
        <FinanceAction title="Reject earn session" description="Rejects the session for farming or integrity failure. Requires a reason and fresh MFA." trigger="Reject" method="PUT" endpoint={`/api/admin/v1/finance/earn/${row.id}`} fields={[{ name: 'decision', label: 'Decision', type: 'select', options: ['rejected'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </div>
    </Item>}</List>
    <h3 className="mt-8 font-semibold">Earn campaigns</h3>
    <List rows={earn.campaigns} empty="No earn campaigns configured.">{row => <Item key={String(row.id)}><div><b>{String(row.name)}</b><small className="block text-slate-500">v{String(row.config_version)}</small></div><Badge value={String(row.state)} /></Item>}</List>
    <div className="mt-4"><Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=earn">Earn awards ledger →</Link></div>
  </>;
}

function WithdrawalDesk({ withdrawals }: { withdrawals: Props['withdrawals'] }) {
  return <div className="grid gap-8">
    <Queue title="Queued (approve or reject)" rows={withdrawals.queued} empty="No listener withdrawals are waiting in the 1st–5th window." action={row => row.state === 'queued' ? <div className="flex flex-wrap gap-2">
      <FinanceAction title="Approve withdrawal" description="Dispatches the reserved payout to the verified destination. Requires a reason and fresh MFA." trigger="Approve" method="PUT" endpoint={`/api/admin/v1/finance/withdrawals/${row.id}`} fields={[{ name: 'state', label: 'State', type: 'select', options: ['approved'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      <FinanceAction title="Reject withdrawal" description="Rejects the request and releases the earn reservation. Requires a reason and fresh MFA." trigger="Reject" method="PUT" endpoint={`/api/admin/v1/finance/withdrawals/${row.id}`} fields={[{ name: 'state', label: 'State', type: 'select', options: ['rejected'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
    </div> : null} />
    <Queue title="Processing (mark paid or failed)" rows={withdrawals.processing} empty="No payouts are currently with a gateway." action={row => <div className="flex flex-wrap gap-2">
      <FinanceAction title="Mark withdrawal paid" description="Records a successful gateway settlement against this reserved payout." trigger="Mark paid" method="PUT" endpoint={`/api/admin/v1/finance/withdrawals/${row.id}`} fields={[{ name: 'state', label: 'State', type: 'select', options: ['paid'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
      <FinanceAction title="Mark withdrawal failed" description="Records gateway failure and releases the reservation. The listener must submit a new withdrawal to retry." trigger="Mark failed" method="PUT" endpoint={`/api/admin/v1/finance/withdrawals/${row.id}`} fields={[{ name: 'state', label: 'State', type: 'select', options: ['failed'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
    </div>} />
    <Queue title="Failed (reservation released)" rows={withdrawals.failed} empty="No failed gateway payouts." action={() => <span className="text-xs text-slate-500">Retry requires a new listener request.</span>} />
    <Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=withdrawals">All withdrawals →</Link>
  </div>;
}

function PayoutDesk({ payouts }: { payouts: Props['payouts'] }) {
  return <>
    <div className="mb-6">
      <FinanceAction title="Prepare monthly creator payout batch" description="Selects verified creators at or above their minimum (default ₦5,000 equivalent in PCN). A different finance administrator must approve." trigger="Prepare payout batch" method="POST" endpoint="/api/admin/v1/finance/creator-payout-batches" idempotent fields={[{ name: 'period_start', label: 'Period start', type: 'date' }, { name: 'period_end', label: 'Period end', type: 'date' }, { name: 'unit', label: 'Unit', type: 'select', options: ['PCN'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
    </div>
    <h3 className="font-semibold">Payout batches</h3>
    <List rows={payouts.batches} empty="No creator payout batches have been prepared.">{row => <Item key={String(row.id)}>
      <div><b>{Number(row.payout_count).toLocaleString()} payouts</b><small className="block text-slate-500">{Number(row.total_amount).toLocaleString()} {row.unit} · {String(row.period_start)} – {String(row.period_end)}</small></div>
      <div className="flex items-center gap-2">
        <Badge value={String(row.state)} />
        {row.state === 'draft' && <FinanceAction title="Approve payout batch" description="Maker-checker: the preparing administrator cannot approve. Approval reserves creator balances and dispatches payouts." trigger="Approve" method="POST" endpoint={`/api/admin/v1/finance/creator-payout-batches/${row.id}/approval`} fields={[{ name: 'reason', label: 'Reason', type: 'textarea' }]} />}
      </div>
    </Item>}</List>
    <h3 className="mt-8 font-semibold">Recent creator revenue</h3>
    <List rows={payouts.revenue} empty="No creator revenue events yet.">{row => <Item key={String(row.id)}><div><b>{String(row.source_type ?? row.type)}</b><code className="block text-xs text-slate-500">{String(row.id)}</code></div><strong>{Number(row.net_amount).toLocaleString()} {row.unit}</strong></Item>}</List>
    <div className="mt-4"><Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=payouts">Creator payout records →</Link></div>
  </>;
}

function PremiumDesk({ premium }: { premium: Premium }) {
  return <>
    <div className="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <Stat label="Estimated MRR" value={`${premium.mrrMinor.toLocaleString()} minor`} />
      <Stat label="Active subscriptions" value={premium.active.toLocaleString()} />
      <Stat label="Churned this month" value={premium.churnedThisMonth.toLocaleString()} />
      <Stat label="Failed charges / refunds" value={`${premium.failedInvoices.toLocaleString()} / ${premium.refunds.toLocaleString()}`} />
    </div>
    <div className="mb-6">
      <FinanceAction title="Save premium plan" description="Creates or updates a plan. Existing entitlements keep historical invoice amounts." trigger="Save plan" method="PUT" endpoint="/api/admin/v1/finance/premium/plans" fields={[{ name: 'slug', label: 'Slug' }, { name: 'name', label: 'Name' }, { name: 'price_minor', label: 'Price (minor units)', type: 'number', min: 1 }, { name: 'currency', label: 'Currency', defaultValue: 'NGN' }, { name: 'interval', label: 'Interval', type: 'select', options: ['month', 'year'] }, { name: 'active', label: 'Active', type: 'select', options: ['1', '0'] }, { name: 'reason', label: 'Reason', type: 'textarea' }]} />
    </div>
    <h3 className="font-semibold">Plans</h3>
    <List rows={premium.plans} empty="No premium plans.">{row => <Item key={String(row.id)}><b>{String(row.name)}</b><strong>{Number(row.price_minor).toLocaleString()} {row.currency} / {row.interval}</strong></Item>}</List>
    <h3 className="mt-8 font-semibold">Subscriptions</h3>
    <List rows={premium.subscriptions} empty="No premium subscriptions.">{row => <Item key={String(row.id)}><code className="text-xs">{String(row.id)}</code><Badge value={String(row.state)} /></Item>}</List>
    <h3 className="mt-8 font-semibold">Invoices</h3>
    <List rows={premium.invoices} empty="No premium invoices.">{row => <Item key={String(row.id)}><div><code className="text-xs">{String(row.id)}</code><small className="block text-slate-500">{Number(row.total_minor).toLocaleString()} {row.currency}</small></div><Badge value={String(row.state)} /></Item>}</List>
    <div className="mt-4"><Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=premium">Premium records →</Link></div>
  </>;
}

function LedgerDesk({ ledger, exceptions, reconciliation, settlements }: { ledger: Props['ledger']; exceptions: Row[]; reconciliation: Row[]; settlements: Row[] }) {
  return <div className="grid gap-8">
    <div>
      <div className="mb-4 flex flex-wrap gap-2">
        <FinanceAction title="Run daily reconciliation" description="Compares store settlements and gateway payouts to the ledger for one business date and opens exceptions on drift." trigger="Run reconciliation" method="POST" endpoint="/api/admin/v1/finance/reconciliation" fields={[{ name: 'business_date', label: 'Business date', type: 'date' }]} />
        <FinanceAction title="Import provider settlement" description="Replay-safe settlement import. Gross minus fees must equal net." trigger="Import settlement" method="POST" endpoint="/api/admin/v1/finance/settlements" fields={[{ name: 'provider', label: 'Provider' }, { name: 'provider_settlement_id', label: 'Provider settlement id' }, { name: 'business_date', label: 'Business date', type: 'date' }, { name: 'currency', label: 'Currency', defaultValue: 'NGN' }, { name: 'gross_minor', label: 'Gross (minor)', type: 'number' }, { name: 'fees_minor', label: 'Fees (minor)', type: 'number', min: 0 }, { name: 'net_minor', label: 'Net (minor)', type: 'number' }]} />
      </div>
      <h3 className="font-semibold">Recent ledger (immutable)</h3>
      <List rows={ledger.transactions} empty="No ledger transactions yet.">{row => <Item key={String(row.id)}>
        <div><Link className="font-semibold text-teal-700 hover:underline" href={`/admin/records/transactions/${row.id}`}>{String(row.event_type)}</Link><code className="block text-xs text-slate-500">{String(row.reference)}</code></div>
        <FinanceAction title="Post reversing entry" description="Never edits the original row. Posts a linked reversal with an operator reason." trigger="Reverse" method="POST" endpoint={`/api/admin/v1/finance/transactions/${row.id}/reversal`} fields={[{ name: 'reason', label: 'Reason', type: 'textarea' }]} />
      </Item>}</List>
    </div>
    <div>
      <h3 className="font-semibold">Open finance exceptions</h3>
      <List rows={exceptions} empty="No open finance exceptions.">{row => <Item key={String(row.id)}>
        <div><b>{String(row.type)}</b><code className="block text-xs text-slate-500">{String(row.reference)}</code></div>
        <FinanceAction title="Resolve exception" description="Closes the exception with an immutable resolution note." trigger="Resolve" method="PUT" endpoint={`/api/admin/v1/finance/exceptions/${row.id}`} fields={[{ name: 'resolution', label: 'Resolution', type: 'textarea' }]} />
      </Item>}</List>
    </div>
    <div>
      <h3 className="font-semibold">Reconciliation runs</h3>
      <List rows={reconciliation} empty="No reconciliation runs.">{row => <Item key={String(row.id)}><div><b>{String(row.business_date)}</b><small className="block text-slate-500">{Number(row.drift_count)} drift items</small></div><Badge value={String(row.state)} /></Item>}</List>
    </div>
    <div>
      <h3 className="font-semibold">Provider settlements</h3>
      <List rows={settlements} empty="No provider settlements imported.">{row => <Item key={String(row.id)}><div><b>{String(row.provider)}</b><code className="block text-xs text-slate-500">{String(row.provider_settlement_id)}</code></div><Badge value={String(row.state)} /></Item>}</List>
    </div>
    <Link className="text-sm font-semibold text-teal-700" href="/admin/finance-records?view=ledger">Full ledger explorer →</Link>
  </div>;
}

function Queue({ title, rows, empty, action }: { title: string; rows: Row[]; empty: string; action: (row: Row) => ReactNode }) {
  return <div>
    <h3 className="font-semibold">{title}</h3>
    <List rows={rows} empty={empty}>{row => <Item key={String(row.id)}><div><Link className="font-semibold text-teal-700 hover:underline" href={`/admin/records/withdrawals/${row.id}`}>{Number(row.coins).toLocaleString()} ECN</Link><small className="block text-slate-500">{String(row.state)}</small></div>{action(row)}</Item>}</List>
  </div>;
}

function List({ rows, empty, children }: { rows: Row[]; empty: string; children: (row: Row) => ReactNode }) {
  if (!rows.length) return <EmptyQueue>{empty}</EmptyQueue>;
  return <div className="mt-3 grid gap-3">{rows.map(children)}</div>;
}

function Item({ children }: { children: ReactNode }) {
  return <article className="admin-card flex flex-wrap items-center justify-between gap-4 rounded-xl p-4">{children}</article>;
}

function Badge({ value }: { value: string }) {
  return <span className="rounded-full bg-slate-100 px-2 py-1 text-xs uppercase text-teal-800">{value}</span>;
}

function Stat({ label, value }: { label: string; value: string }) {
  return <div className="admin-card rounded-xl p-4"><span className="text-xs text-slate-500">{label}</span><strong className="mt-2 block text-xl tabular-nums">{value}</strong></div>;
}

function formatDate(value: string | number | null | undefined) {
  if (!value) return '—';
  const date = new Date(String(value));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}

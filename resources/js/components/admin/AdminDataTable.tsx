import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Row = Record<string, unknown>;

type Props = {
  columns: string[];
  module: string;
  rows: Row[];
  view: string;
};

export default function AdminDataTable({ columns, module, rows, view }: Props) {
  const preferenceKey = `pelevo.admin.table.${module}.${view}`;
  const [visibleColumns, setVisibleColumns] = useState<string[]>(columns);
  const [compact, setCompact] = useState(false);

  useEffect(() => {
    const restore = () => { try {
      const stored = JSON.parse(localStorage.getItem(preferenceKey) ?? '{}') as { columns?: string[]; compact?: boolean };
      const valid = stored.columns?.filter(column => columns.includes(column)) ?? [];
      setVisibleColumns(valid.length ? valid : columns);
      setCompact(Boolean(stored.compact));
    } catch {
      setVisibleColumns(columns);
    } };
    restore(); window.addEventListener('pelevo-table-preferences', restore);
    return () => window.removeEventListener('pelevo-table-preferences', restore);
  }, [preferenceKey, columns]);

  useEffect(() => {
    try { localStorage.setItem(preferenceKey, JSON.stringify({ columns: visibleColumns, compact })); } catch { /* Table controls still work when browser storage is unavailable. */ }
  }, [compact, preferenceKey, visibleColumns]);

  const moveColumn = (column: string, offset: number) => setVisibleColumns(current => {
    const index = current.indexOf(column); const target = index + offset;
    if (index < 0 || target < 0 || target >= current.length) return current;
    const next = [...current]; [next[index], next[target]] = [next[target], next[index]]; return next;
  });
  const toggleColumn = (column: string) => {
    setVisibleColumns(current => current.includes(column)
      ? (current.length === 1 ? current : current.filter(item => item !== column))
      : columns.filter(item => [...current, column].includes(item)));
  };


  return <>
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 bg-slate-950/35 px-4 py-3">
      <p aria-live="polite" className="text-xs text-slate-400">{`${rows.length} records on this page`}</p>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={() => setCompact(value => !value)} className="rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 hover:border-teal-500">{compact ? 'Comfortable rows' : 'Compact rows'}</button>
        <details className="relative">
          <summary className="cursor-pointer list-none rounded-lg border border-slate-700 px-3 py-2 text-xs font-semibold text-slate-300 hover:border-teal-500">Columns <span aria-hidden="true">▾</span></summary>
          <fieldset className="absolute right-0 z-30 mt-2 min-w-56 rounded-xl border border-slate-700 bg-slate-950 p-3 shadow-2xl">
            <legend className="sr-only">Visible columns</legend>
            {columns.map(column => <label key={column} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-slate-800"><input checked={visibleColumns.includes(column)} onChange={() => toggleColumn(column)} type="checkbox" className="accent-teal-400" /><span>{label(column)}</span></label>)}
            {visibleColumns.map((column, index) => <div key={column} className="mt-2 flex items-center gap-2 text-xs"><span className="flex-1">{label(column)}</span><button type="button" aria-label={`Move ${label(column)} left`} disabled={index === 0} onClick={() => moveColumn(column, -1)} className="rounded border border-slate-700 px-2 disabled:opacity-30">←</button><button type="button" aria-label={`Move ${label(column)} right`} disabled={index === visibleColumns.length - 1} onClick={() => moveColumn(column, 1)} className="rounded border border-slate-700 px-2 disabled:opacity-30">→</button></div>)}
            <button type="button" onClick={() => setVisibleColumns(columns)} className="mt-2 w-full rounded border border-slate-700 px-2 py-1.5 text-xs text-teal-300">Reset columns</button>
          </fieldset>
        </details>
      </div>
    </div>
    <div className="overflow-x-auto">
      <table className="w-full min-w-[760px] text-left text-sm">
        <thead className="bg-slate-100 text-xs uppercase tracking-wide text-slate-500"><tr>
          {visibleColumns.map(column => <th className="whitespace-nowrap border-b border-slate-200 px-4 py-3 font-semibold" key={column} scope="col">{label(column)}</th>)}
        </tr></thead>
        <tbody className="divide-y divide-slate-200">{rows.map((record, index) => {
          const key = String(record.id ?? record.reference ?? index);
          return <tr className="transition hover:bg-slate-50" key={key}>
            {visibleColumns.map(column => <td className={`max-w-xs truncate px-4 ${compact ? 'py-2' : 'py-3'} ${isIdentifier(column) ? 'font-mono text-xs text-slate-400' : ''}`} title={format(record[column])} key={column}>
              {module === 'users' && ['directory', 'user-360', 'access'].includes(view) && column === 'id' ? <Link className="font-semibold text-teal-300 hover:underline" href={`/admin/users/${record.id}`}>{format(record[column])}</Link> : module === 'support' && view === 'contact' && column === 'id' ? <Link className="font-semibold text-teal-300 hover:underline" href={`/admin/support/contact/${record.id}`}>{format(record[column])}</Link> : module === 'support' && ['inbox', 'tickets', 'sla'].includes(view) && column === 'id' ? <Link className="font-semibold text-teal-300 hover:underline" href={`/admin/support/tickets/${record.id}`}>{format(record[column])}</Link> : module === 'reels-live' && ['reels', 'review'].includes(view) && column === 'id' ? <Link href={`/admin/records/reels/${record.id}`} className="text-teal-300 hover:underline">{format(record[column])}</Link> : module === 'finance-records' && ['ledger', 'withdrawals', 'payouts'].includes(view) && column === 'id' ? <Link href={`/admin/records/${view === 'ledger' ? 'transactions' : view}/${record.id}`} className="text-teal-300 hover:underline">{format(record[column])}</Link> : isState(column) ? <Status value={format(record[column])} /> : format(record[column])}
            </td>)}
          </tr>;
        })}</tbody>
      </table>
    </div>
  </>;
}

function label(column: string): string { return column.replaceAll('_', ' '); }
function isIdentifier(column: string): boolean { return column === 'id' || column === 'reference' || column.endsWith('_id'); }
function isState(column: string): boolean { return column === 'state' || column === 'status' || column === 'priority'; }
function format(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return 'Structured data';
  return String(value);
}
function Status({ value }: { value: string }) {
  const warning = ['failed', 'rejected', 'blocked', 'urgent', 'suspended'].some(state => value.toLowerCase().includes(state));
  return <span className={`inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide ${warning ? 'bg-rose-950/70 text-rose-300' : 'bg-slate-800 text-teal-300'}`}>{value.replaceAll('_', ' ')}</span>;
}

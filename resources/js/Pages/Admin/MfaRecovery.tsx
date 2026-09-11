import { Head, Link } from '@inertiajs/react';

export default function MfaRecovery({ codes }: { codes: string[] }) {
  return <main className="auth"><Head title="Recovery codes"/><section><div className="brand">PELEVO <span>SECURE</span></div><h1>Save recovery codes</h1><p>Each code works once. Store them offline before continuing.</p><ul>{codes.map(code => <li key={code}><code>{code}</code></li>)}</ul><Link href="/admin/login">Return to sign in</Link></section></main>;
}

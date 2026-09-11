import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8019', APP_KEY: `base64:${randomBytes(32).toString('base64')}`, APP_CONFIG_CACHE: 'bootstrap/cache/browser-config-unused.php', DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: '3306', DB_DATABASE: 'pelevo_browser_test', DB_USERNAME: 'root', DB_PASSWORD: '', DB_URL: '', SESSION_DRIVER: 'file', SESSION_COOKIE: 'pelevo_browser_test_session', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', BCRYPT_ROUNDS: '4' };
for (const args of [['artisan', 'migrate', '--force', '--no-interaction'], ['artisan', 'db:seed', '--class=BrowserTestSeeder', '--force', '--no-interaction']]) {
  const result = spawnSync('php', args, { env, stdio: 'inherit', timeout: 300000 });
  if (result.status !== 0) process.exit(result.status ?? 1);
}
const server = spawn('php', ['-d', 'max_execution_time=120', '-S', '127.0.0.1:8019', '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], { cwd: 'public', env, stdio: ['ignore', 'ignore', 'pipe'] });
server.stderr.on('data', chunk => { if (/Fatal error|Parse error/.test(String(chunk))) process.stderr.write(chunk); });
const worker = spawn('php', ['artisan', 'queue:work', 'database', '--queue=admin-exports', '--sleep=1', '--tries=3', '--timeout=90'], { env, stdio: 'inherit' });
const stop = () => { worker.kill(); server.kill(); };
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
server.on('exit', code => { worker.kill(); process.exit(code ?? 0); });

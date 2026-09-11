import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';

function totp(): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...'JBSWY3DPEHPK3PXP'].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g)!.map(byte => parseInt(byte, 2)));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac('sha1', key).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
}

test.beforeEach(async ({ page }) => {
  await page.goto('/admin/login');
  await page.getByLabel('Email', { exact: true }).fill('browser-admin@example.test');
  await page.getByLabel('Password', { exact: true }).fill('Browser-test-only-9!');
  await page.getByRole('button', { name: 'Continue securely' }).click();
  await page.getByLabel('Authentication or recovery code').fill(totp());
  await page.getByRole('button', { name: 'Verify and enter' }).click();
  await expect(page.getByRole('heading', { name: 'Platform pulse' })).toBeVisible();
});

test('document workspaces retain navigation and pass accessibility checks', async ({ page }) => {
  test.setTimeout(240000);
  const errors: string[] = [];
  page.on('pageerror', error => errors.push(error.message));
  for (const path of ['/admin', '/admin/users', '/admin/catalog', '/admin/creators', '/admin/claims', '/admin/reels-live', '/admin/community', '/admin/moderation', '/admin/finance', '/admin/communications', '/admin/growth', '/admin/cms', '/admin/ai', '/admin/support', '/admin/analytics', '/admin/settings', '/admin/settings/integrations', '/admin/audit', '/admin/profile', '/admin/support/tickets/01M20000000000000000000001', '/admin/communications/compose', '/admin/settings/configuration', '/admin/finance-records']) {
    await page.goto(path);
    await expect(page.getByRole('navigation', { name: 'Administration', exact: true })).toBeVisible();
    await expect(page.locator('h1')).toBeVisible();
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect.soft(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) })), path).toEqual([]);
  }
  expect(errors).toEqual([]);
});

test('saved views survive navigation and show server filters', async ({ page }) => {
  const viewName = `Browser listener view ${Date.now()}`;
  await page.goto('/admin/users?view=directory&q=Amina&per_page=50');
  const compact = page.getByRole('button', { name: 'Compact rows', exact: true });
  if (await compact.isVisible()) await compact.click();
  await page.getByRole('button', { name: 'Save current filters' }).click();
  await page.getByLabel('View name', { exact: true }).fill(viewName);
  await page.getByRole('button', { name: 'Save view', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('View saved');
  await page.evaluate(() => localStorage.removeItem('pelevo.admin.table.users.directory'));
  await page.goto('/admin/users?view=directory');
  await expect(page.getByRole('button', { name: 'Compact rows', exact: true })).toBeVisible();
  await page.getByLabel('Apply saved view').selectOption({ label: `${viewName} · Private` });
  await expect(page).toHaveURL(/q=Amina/);
  await expect(page.getByRole('button', { name: 'Comfortable rows', exact: true })).toBeVisible();
  await expect(page.getByLabel('Rows per page')).toHaveValue('50');
});

test('governed template form remains accessible and preserves failed submissions', async ({ page }) => {
  await page.goto('/admin/communications?view=templates');
  await page.getByRole('button', { name: 'Open governed form' }).click();
  const dialog = page.getByRole('dialog', { name: 'Create notification template version' });
  await expect(dialog).toBeVisible();
  expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
  await dialog.getByLabel('Template key').fill('browser-preserved-draft');
  await dialog.getByLabel('Notification title').fill('Draft message');
  await dialog.getByLabel('Notification body').fill('Preserve this draft if delivery fails.');
  await dialog.getByLabel('Change reason').fill('Test recoverable form submission');
  await page.route('**/api/admin/v1/advanced/notification-templates', route => route.abort('failed'));
  await dialog.getByRole('button', { name: 'Validate and save' }).click();
  await expect(dialog.getByRole('alert')).toContainText('Connection interrupted');
  await expect(dialog.getByLabel('Template key')).toHaveValue('browser-preserved-draft');
  await expect(dialog.getByRole('button', { name: 'Validate and save' })).toBeEnabled();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: 'Open governed form' })).toBeFocused();
});

test('audited exports finish through the database worker and download filtered records', async ({ page }) => {
  await page.goto('/admin/users?view=directory&q=Amina');
  await page.getByRole('button', { name: 'Export all matching records' }).click();
  await page.getByLabel('Business reason').fill('Browser verification of filtered account export');
  const queuedResponse = page.waitForResponse(response => response.url().endsWith('/api/admin/v1/workspaces/users/exports') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Queue export', exact: true }).click();
  const exportId = (await (await queuedResponse).json()).data.id as string;
  const exportRow = page.locator('li').filter({ hasText: exportId });
  await expect(page.getByRole('status')).toContainText('Export queued. Audit reference:');
  await expect(async () => {
    await page.getByRole('button', { name: 'Refresh exports' }).click();
    await expect(exportRow.getByRole('link', { name: 'Download CSV' })).toBeVisible();
  }).toPass({ timeout: 45000, intervals: [1000, 2000] });
  const downloadPromise = page.waitForEvent('download');
  await exportRow.getByRole('link', { name: 'Download CSV' }).click();
  const download = await downloadPromise;
  expect(await download.failure()).toBeNull();
  const stream = await download.createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
  const csv = Buffer.concat(chunks).toString('utf8');
  expect(csv).toContain('Amina Listener');
  expect(csv).not.toContain('password');
});

test('desktop and tablet layouts retain reviewed visual baselines', async ({ page }) => {
  await page.goto('/admin/communications/compose');
  await expect(page).toHaveScreenshot('communications-desktop.png', { fullPage: true, mask: [page.getByText(/^Updated /)] });
  await page.setViewportSize({ width: 834, height: 1112 });
  await page.getByRole('button', { name: 'Open navigation' }).click();
  await expect(page.getByRole('navigation', { name: 'Administration', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Close navigation', exact: true }).click({ position: { x: 750, y: 100 } });
  await expect(page).toHaveScreenshot('communications-tablet.png', { fullPage: true, mask: [page.getByText(/^Updated /)] });
});

test('command palette traps keyboard focus and restores its trigger', async ({ page }) => {
  await page.getByRole('button', { name: /Search operations and routes/ }).click();
  await expect(page.getByRole('dialog', { name: 'Command palette' })).toBeVisible();
  await expect(page.getByLabel('Search admin routes and records')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'Sign out', exact: true })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Search admin routes and records')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', { name: /Search operations and routes/ })).toBeFocused();
});

test('canonical show evidence and support replies work from their workspaces', async ({ page }) => {
  await page.goto('/admin/catalog');
  await page.getByRole('link', { name: 'The Listening Room', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'The Listening Room', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'RSS health', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'RSS health', exact: true })).toBeVisible();
  expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
  await page.goto('/admin/support/tickets/01M20000000000000000000001');
  await page.getByLabel('Reply to requester', { exact: true }).fill('Please reopen your library and resume the episode.');
  await page.getByLabel('Send this reply as an in-app notification.', { exact: false }).check();
  await page.getByLabel('Reason for change', { exact: true }).fill('Provide playback troubleshooting instructions');
  await page.getByRole('button', { name: 'Save ticket update' }).click();
  await expect(page.getByRole('status')).toContainText('Ticket updated. Audit reference:');
  await expect(page.getByText('Please reopen your library and resume the episode.', { exact: true }).first()).toBeVisible();
});

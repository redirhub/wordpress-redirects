// The whole import, with the real dashboard and backend: other plugins' redirects go from the
// WordPress admin page to the dashboard's import, are reviewed and imported there, and become
// links. Needs the backend's `.claude/dev-serve.sh --dashboard` (https://app.redirhub.test) and
// the e2e site started with E2E_DASHBOARD=https://app.redirhub.test, its other plugins seeded
// (other-plugins.sh). The dashboard's server holds port 80, so the site answers on 18080:
//   E2E_DASHBOARD=https://app.redirhub.test WP_PUBLIC_PORT=18079 SKIP_SUITES=1 e2e/run.sh --keep
//   docker compose exec -T cli wp option update home http://promo.example.test:18080 (and siteurl)
// Checks the links in the backend's database through dev-serve's artisan.
//
// Usage: node e2e/dashboard.mjs [screenshot directory]
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const shots = process.argv[2] || join(here, 'screenshots');
mkdirSync(shots, { recursive: true });
const backend = process.env.BACKEND_DIR || join(here, '../../backend');
const site = process.env.SITE || 'http://promo.example.test:18080';
const service = 'https://service.redirhub.test';
let passed = 0, failed = 0;
const check = (what, ok, detail = '') => {
  if (ok) { passed++; console.log(`  ok   ${what}`); } else { failed++; console.log(`  FAIL ${what}${detail ? ` (${detail})` : ''}`); }
};
const tinker = (code) => execFileSync(join(backend, '.claude/dev-serve.sh'), ['php', 'artisan', 'tinker', '--execute', code], { cwd: backend }).toString().trim().split('\n').pop();
const links = () => JSON.parse(tinker("echo json_encode(App\\Eloquents\\Record::query()->where('host', 'promo.example.test')->orderBy('file')->get()->map(fn ($r) => [$r->file, $r->getAttributeValue('destination'), (string) $r->type, $r->note])->all());"));

// From no links of this site, so the import adds every one.
tinker("App\\Eloquents\\Record::withTrashed()->where('host', 'promo.example.test')->forceDelete(); echo 'ok';");

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP *.example.test 127.0.0.1'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
const page = await context.newPage();

// Signed in to WordPress, and to the dashboard (its session cookie, as .claude/tools/shot.mjs does).
await page.goto(`${site}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await page.click('#wp-submit');
await page.waitForURL((url) => url.pathname.startsWith('/wp-admin/'));
await page.goto(`${service}/forgot-password`);
const token = await page.locator('input[name=_token]').first().inputValue();
await page.request.post(`${service}/login`, { form: { _token: token, email: 'demo@redirhub.com', password: 'redirhub123' }, maxRedirects: 0 });

console.log('\nWordPress → dashboard → links');
await page.goto(`${site}/wp-admin/tools.php?page=redirhub-redirects`);
const [tab] = await Promise.all([context.waitForEvent('page'), page.getByRole('button', { name: /Import 8 redirects into RedirHub/ }).click()]);
await tab.waitForLoadState();
check('"Import into RedirHub" opens the dashboard\'s import', tab.url().startsWith('https://app.redirhub.test/links/import'), tab.url());

const notice = tab.getByTestId('import-handoff');
await notice.filter({ hasText: 'Redirects from promo.example.test' }).waitFor({ timeout: 60_000 });
check('the dashboard says where they came from', /Sent by your WordPress site, from Redirection, Rank Math, Yoast SEO Premium, Safe Redirect Manager, Simple 301 Redirects/.test(await notice.innerText()));
await page.locator('[data-handoff-status]').filter({ hasText: 'Sent to RedirHub' }).waitFor({ timeout: 10_000 }).then(() => check('WordPress says they were sent', true), () => check('WordPress says they were sent', false));

const commit = tab.getByRole('button', { name: /^Import \d+ changes$/ });
await commit.waitFor({ timeout: 60_000 });
check('the dashboard opened an import ticket for them', /[?&]ticket=/.test(tab.url()), tab.url());
check('the review counts 8 changes', (await commit.innerText()).trim() === 'Import 8 changes', await commit.innerText());
check('nothing is written before Import is pressed', links().length === 0);
await tab.screenshot({ path: join(shots, 'dashboard-import-review.png'), fullPage: true });

await commit.click();
await tab.getByText('Import complete').waitFor({ timeout: 120_000 });
await tab.screenshot({ path: join(shots, 'dashboard-import-done.png'), fullPage: true });

const origin = new URL(site).origin;
const expected = [
  // RedirHub keeps a source without its trailing slash: it answers both.
  ['promo.example.test/old-about', `${origin}/about-us/`, '301', 'Imported from Redirection (WordPress)'],
  ['promo.example.test/rank-old', `${origin}/rank-new/`, '301', 'Imported from Rank Math (WordPress)'],
  ['promo.example.test/simple-old', `${origin}/simple-new`, '301', 'Imported from Simple 301 Redirects (WordPress)'],
  ['promo.example.test/spring-sale', 'https://shop.example.com/sale', '302', 'Imported from Redirection (WordPress)'],
  ['promo.example.test/srm-old', `${origin}/srm-new`, '301', 'Imported from Safe Redirect Manager (WordPress)'],
  ['promo.example.test/two-a', 'https://example.org/two', '307', 'Imported from Rank Math (WordPress)'],
  ['promo.example.test/two-b', 'https://example.org/two', '307', 'Imported from Rank Math (WordPress)'],
  ['promo.example.test/yoast-old', `${origin}/yoast-new`, '301', 'Imported from Yoast SEO Premium (WordPress)'],
];
const written = links();
check('the 8 redirects are links now, with their targets, types and where they came from', JSON.stringify(written) === JSON.stringify(expected), JSON.stringify(written));

await browser.close();
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);

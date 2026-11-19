// Moving other plugins' redirects: the admin page lists what it found in each plugin, the CSV is
// RedirHub's import file, and "Import into RedirHub" opens the dashboard's import and hands it the
// file once it says it is ready (here the mock's stand-in, /links/import).
//
// Usage: node e2e/import.mjs [screenshot directory]   (after run.sh and other-plugins.sh)
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const shots = process.argv[2] || join(here, 'screenshots');
mkdirSync(shots, { recursive: true });
const site = 'http://promo.example.test';
const page_ = `${site}/wp-admin/tools.php?page=redirhub-redirects`;
let passed = 0, failed = 0;
const check = (what, ok, detail = '') => {
  if (ok) { passed++; console.log(`  ok   ${what}`); } else { failed++; console.log(`  FAIL ${what}${detail ? ` (${detail})` : ''}`); }
};

const expected = [
  'source_url,destination,type,note',
  'promo.example.test/old-about/,http://promo.example.test/about-us/,301,Imported from Redirection (WordPress)',
  'promo.example.test/spring-sale,https://shop.example.com/sale,302,Imported from Redirection (WordPress)',
  'promo.example.test/rank-old,http://promo.example.test/rank-new/,301,Imported from Rank Math (WordPress)',
  'promo.example.test/two-a,https://example.org/two,307,Imported from Rank Math (WordPress)',
  'promo.example.test/two-b,https://example.org/two,307,Imported from Rank Math (WordPress)',
  'promo.example.test/yoast-old,http://promo.example.test/yoast-new,301,Imported from Yoast SEO Premium (WordPress)',
  'promo.example.test/srm-old,http://promo.example.test/srm-new,301,Imported from Safe Redirect Manager (WordPress)',
  'promo.example.test/simple-old,http://promo.example.test/simple-new,301,Imported from Simple 301 Redirects (WordPress)',
].join('\n') + '\n';

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP *.example.test 127.0.0.1'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, acceptDownloads: true });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(`${site}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await page.click('#wp-submit');
await page.waitForURL((url) => url.pathname.startsWith('/wp-admin/'));

console.log('\nWhat other plugins keep');
await page.goto(page_);
const card = page.locator('.rh-import');
check('the page shows the redirects other plugins keep', await card.isVisible());
const row = async (id) => (await card.locator(`[data-import-source="${id}"]`).innerText()).replace(/\s+/g, ' ');
check('each plugin with how many of its redirects are ready', /Redirection 2 redirects ready/.test(await row('redirection')) && /Rank Math 3 redirects ready/.test(await row('rank-math')) && /Yoast SEO Premium 1 redirect ready/.test(await row('yoast')) && /Safe Redirect Manager 1 redirect ready/.test(await row('safe-redirect-manager')) && /Simple 301 Redirects 1 redirect ready/.test(await row('simple-301-redirects')), await row('redirection'));
const leftOut = (await card.locator('[data-import-left-out]').innerText()).replace(/\s+/g, ' ');
check('one line says what is left out, over all of them', leftOut === 'Left out: 5 patterns (regex or wildcard), 2 error pages (410, 404…), 1 with conditions, 1 switched off, 1 already listed. Add those in RedirHub.', leftOut);
check('one button imports all of them', (await card.getByRole('button', { name: 'Import 8 redirects into RedirHub' }).count()) === 1);
check('it says to switch the old plugins off afterwards', /Once they are imported, switch the old plugins off\./.test(await card.innerText()));
await card.screenshot({ path: join(shots, 'import-card.png') });

console.log('\nThe import file');
const [download] = await Promise.all([page.waitForEvent('download'), card.getByRole('button', { name: 'Download CSV' }).click()]);
const csv = readFileSync(await download.path(), 'utf8');
check('Download CSV gives RedirHub\'s import file', csv === expected, csv);
check('named after the site and the day', /^redirects-promo\.example\.test-\d{4}-\d{2}-\d{2}\.csv$/.test(download.suggestedFilename()), download.suggestedFilename());

console.log('\nHanded to the dashboard');
await page.goto(page_);
const [tab] = await Promise.all([context.waitForEvent('page'), page.locator('.rh-import').getByRole('button', { name: /Import 8 redirects/ }).click()]);
await tab.waitForLoadState();
check('the dashboard\'s import opens in a new tab, asking for the hand-off', new URL(tab.url()).pathname === '/links/import' && new URL(tab.url()).searchParams.get('handoff') === 'wordpress', tab.url());
await tab.waitForFunction(() => window.handoff, null, { timeout: 15000 });
const received = await tab.evaluate(() => window.handoff);
check('once it is ready it gets the file', received.csv === expected);
check('with its name and where it comes from', /^redirects-promo\.example\.test-/.test(received.name) && received.source === 'WordPress · promo.example.test', JSON.stringify({ name: received.name, source: received.source }));
await page.locator('[data-handoff-status]').filter({ hasText: 'Sent to RedirHub' }).waitFor({ timeout: 5000 }).then(() => check('the page says it was sent and nothing changes until Import is pressed there', true), () => check('the page says it was sent', false));
check('no script errors', errors.length === 0, errors.join('; '));

console.log('\nNothing to move');
const { execFileSync } = await import('node:child_process');
const wp = (code) => execFileSync('docker', ['compose', 'exec', '-T', 'cli', 'wp', 'eval', code], { cwd: here, stdio: ['ignore', 'pipe', 'ignore'] }).toString();
wp('global $wpdb; foreach (array("redirection_items","rank_math_redirections") as $t) $wpdb->query("DELETE FROM {$wpdb->prefix}$t"); delete_option("301_redirects"); delete_option("wpseo-premium-redirects-base"); foreach (get_posts(array("post_type"=>"redirect_rule","numberposts"=>-1,"post_status"=>"any")) as $p) wp_delete_post($p->ID, true);');
await page.goto(page_);
check('without other plugins\' redirects, the card is not shown', (await page.locator('.rh-import').count()) === 0);

await browser.close();
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);

// The admin page in a real browser: connect (with a mistake first), the status, the test, the
// rules list, the settings, a failing download, Site Health, and disconnecting.
//
// Usage: node e2e/admin.mjs [screenshot directory]   (after e2e/run.sh has set the site up)
import { createRequire } from 'node:module';
// CommonJS resolution: finds a global Playwright (NODE_PATH) as well as a local one.
const { chromium } = createRequire(import.meta.url)('playwright');
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const shots = process.argv[2] || join(here, 'screenshots');
mkdirSync(shots, { recursive: true });
const site = 'http://promo.example.test';
const mock = 'http://127.0.0.1:18081';
const page_ = `${site}/wp-admin/tools.php?page=redirhub-redirects`;
let passed = 0, failed = 0;

const wp = (code) => execFileSync('docker', ['compose', 'exec', '-T', 'cli', 'wp', 'eval', code], { cwd: here }).toString();
const post = (path) => fetch(mock + path, { method: 'POST' });
function check(what, ok) {
  if (ok) { passed++; console.log(`  ok   ${what}`); } else { failed++; console.log(`  FAIL ${what}`); }
}

// From scratch: not connected, the sample rules on the stand-in.
wp('\\RedirHub\\Redirects\\Settings::update(["rules_url"=>"","key"=>"","link_options"=>true,"reports"=>true]); \\RedirHub\\Redirects\\Store::clear(); \\RedirHub\\Redirects\\State::reset();');
await post('/control/spec?name=site');
await post('/control/status?code=0');

const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP *.example.test 127.0.0.1'] });
const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
const page = await context.newPage();
page.on('dialog', (dialog) => dialog.accept());
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const shot = (name, full = true) => page.screenshot({ path: join(shots, `${name}.png`), fullPage: full });
// The WordPress.org screenshots (copied to .wordpress-org/ by bin/wporg-assets.mjs): no core update nag.
const listing = async (name, target = page) => {
  // Nor the test site's own notes (it has no HTTPS), which a real site would not show.
  // Removed rather than hidden: the page's utilities are important and would win over a style.
  await page.evaluate(() => document.querySelectorAll('.update-nag, #wp-admin-bar-updates, #rh-app [data-alert=warning]').forEach((e) => e.remove()));
  await target.screenshot({ path: join(shots, `wporg-${name}.png`) });
};

await page.goto(`${site}/wp-login.php`);
await page.fill('#user_login', 'admin');
await page.fill('#user_pass', 'admin');
await page.click('#wp-submit');
await page.waitForURL(/wp-admin/);

console.log('\nNot connected');
await page.goto(`${site}/wp-admin/plugins.php`);
check('the Plugins screen points to the connection', await page.locator('.notice a', { hasText: 'Connect' }).count() === 1);
check('the plugin has a Settings link', await page.locator('tr[data-slug="redirhub-redirects"] .settings a').count() === 1);
await page.goto(page_);
check('the page explains what to do and asks for the rules address', await page.locator('#rh-rules-url').isVisible() && await page.locator('.rh-connect ol li').count() === 3);
check('status: not connected', (await page.locator('.rh-pill').getAttribute('data-status')) === 'off');
await shot('1-connect');
await listing('1');

console.log('\nConnecting');
await page.fill('#rh-rules-url', 'http://mock:8080/rules/missing.txt');
await page.click('.rh-connect button[type=submit]');
await page.waitForLoadState();
check('a wrong address is refused with what to do', (await page.locator('[data-alert=error]').innerText()).includes('Copy it again from the RedirHub dashboard'));
check('the address entered is kept for correcting', (await page.inputValue('#rh-rules-url')) === 'http://mock:8080/rules/missing.txt');
await shot('2-wrong-address', false);
// Pasting the whole setup text of the dashboard: the address is picked out of it.
await page.fill('#rh-rules-url', '## Values\n- Hostname: `promo.example.test`\n- Rules file: `http://mock:8080/rules/site.txt` (setting `REDIRHUB_RULES_URL`)\n- Check: `GET /_redirhub/check`');
await page.fill('#rh-key', 'e2eIntegrationKey0123456789');
await page.click('.rh-connect button[type=submit]');
await page.waitForLoadState();
check('connected: the rules are counted', (await page.locator('[data-alert=success]').first().innerText()).includes('8 redirects for promo.example.test, shop.example.test are live'));
check('status: waiting for RedirHub’s check', (await page.locator('.rh-pill').getAttribute('data-status')) === 'waiting');
check('the rules card shows 8', (await page.locator('[data-rules-count]').getAttribute('data-rules-count')) === '8');
await shot('3-connected-waiting');
await page.goto(page_ + '&tab=settings');
check('the Change form is closed and never prints the saved rules address', !(await page.locator('#rh-rules-url').isVisible()) && (await page.inputValue('#rh-rules-url')) === '' && !(await page.content()).includes('value="http://mock:8080/rules/site.txt"'));
await page.click('[data-change-toggle]');
check('Change opens it', await page.locator('#rh-rules-url').isVisible());
await page.goto(page_);

console.log('\nRedirHub checks the site');
// As RedirHub does (Node's fetch drops a Host header, so curl).
execFileSync('curl', ['-s', '-H', 'Host: promo.example.test', 'http://127.0.0.1/_redirhub/check']);
await page.reload();
check('status: connected once the check path answered', (await page.locator('.rh-pill').getAttribute('data-status')) === 'ok');
check('the check card names the hostname', (await page.locator('[data-card=check]').innerText()).includes('promo.example.test'));

console.log('\nTest');
await page.click('form:has(input[value=redirhub_test]) button');
await page.waitForLoadState();
const results = await page.locator('.rh-test li').evaluateAll((items) => items.map((li) => li.dataset.ok));
check(`the self-test passes (${results.join(',')})`, results.length === 2 && results.every((ok) => ok === '1'));
await shot('4-connected-tested');
await listing('2');

console.log('\nRules list');
await page.goto(page_ + '&tab=redirects');
await listing('3', page.locator('.rh-rules'));
await page.fill('#rh-search', 'tour');
await page.click('.rh-search button');
await page.waitForLoadState();
const rows = await page.locator('.rh-table tbody tr').count();
check('search finds the frame rule', rows === 1 && (await page.locator('.rh-table tbody').innerText()).includes('Frame page'));
await page.goto(page_ + '&tab=redirects&s=vip');
check('options are shown as badges', (await page.locator('.rh-table tbody').innerText()).match(/password[\s\S]*rotates at random[\s\S]*blocks bots/i) !== null);

console.log('\nSettings');
await page.goto(page_ + '&tab=settings');
await page.uncheck('input[name=link_options]');
await page.check('input[name=ip_source][value=cloudflare]');
await page.click('form:has(input[value=redirhub_settings]) [type=submit]');
await page.waitForLoadState();
check('settings are saved, back on the Settings tab', page.url().includes('tab=settings') && (await page.locator('[data-alert=success]').innerText()).includes('Settings saved') && !(await page.isChecked('input[name=link_options]')) && await page.isChecked('input[name=ip_source][value=cloudflare]'));
await page.goto(page_ + '&tab=redirects&s=vip');
check('a link with options is marked when options are off', await page.locator('.rh-badge--off').count() === 1);
await page.goto(page_ + '&tab=settings');
await page.check('input[name=link_options]');
await page.check('input[name=ip_source][value=remote_addr]');
await page.click('form:has(input[value=redirhub_settings]) [type=submit]');
await page.waitForLoadState();

console.log('\nDownloads failing');
await post('/control/status?code=503');
await page.goto(page_);
await page.click('form:has(input[value=redirhub_sync]) button');
await page.waitForLoadState();
check('Update now reports the failure in words', (await page.locator('[data-alert=error]').innerText()).includes('an error (503)'));
check('the redirects card says they keep working with the last good copy', (await page.locator('[data-card=rules]').innerText()).includes('keep working with the last good copy'));
await shot('5-sync-failing', false);
// Long enough ago to count as stopped: every admin screen warns.
wp('\\RedirHub\\Redirects\\State::update(["fetched_at"=>time()-3600]);');
await page.goto(`${site}/wp-admin/index.php`);
check('when sync has stopped, every admin screen warns', await page.locator('.notice-error', { hasText: 'Your redirects can’t be updated' }).count() === 1);
await page.goto(`${site}/wp-admin/site-health.php`);
await page.waitForSelector('.health-check-accordion-trigger', { timeout: 30000 });
await page.waitForTimeout(3000);
check('Site Health shows it as critical', (await page.locator('.site-health-issues-wrapper').first().innerText()).includes('Your redirects may be out of date'));
await post('/control/status?code=0');
await page.goto(page_);
await page.click('form:has(input[value=redirhub_sync]) button');
await page.waitForLoadState();
check('Update now recovers', (await page.locator('[data-alert=success]').innerText()).includes('up to date') && (await page.locator('.rh-pill').getAttribute('data-status')) === 'ok');

console.log('\nSmall screens');
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(page_);
await shot('6-mobile');
const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
check(`no sideways scrolling on a phone (${overflow}px)`, overflow <= 0);
await page.setViewportSize({ width: 1280, height: 900 });

console.log('\nDisconnect');
await page.goto(page_ + '&tab=settings');
await page.click('.rh-disconnect button');
await page.waitForLoadState();
check('disconnected: back to the connection form', (await page.locator('[data-alert=success]').innerText()).includes('Disconnected') && await page.locator('#rh-rules-url').isVisible());
const status = execFileSync('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code}', '-H', 'Host: promo.example.test', 'http://127.0.0.1/old-pricing']).toString();
check('the site answers its own pages again', status === '200');

check(`no script errors on the page (${errors.join('; ')})`, errors.length === 0);
await browser.close();
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);

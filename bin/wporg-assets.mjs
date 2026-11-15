// Renders the WordPress.org directory assets into .wordpress-org/ from assets/logo.svg (RedirHub's
// mark): icon.svg, icon-128x128.png, icon-256x256.png, banner-772x250.png, banner-1544x500.png.
// The screenshots come from the admin end-to-end test (e2e/admin.mjs writes wporg-*.png).
// Usage: node bin/wporg-assets.mjs
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const { chromium } = createRequire(import.meta.url)('playwright');
const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, '.wordpress-org');
mkdirSync(out, { recursive: true });

// The mark's paths, from assets/logo.svg (viewBox 120 114 368 412).
const logo = readFileSync(join(root, 'assets/logo.svg'), 'utf8');
const paths = logo.match(/<path[^>]*\/>/g).join('');
const mark = (size) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="120 114 368 412" height="${size}">${paths}</svg>`;

// The icon: the mark centred on a square, white, as the directory shows icons on white anyway.
const iconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="64 80 480 480" width="256" height="256"><rect x="64" y="80" width="480" height="480" fill="#ffffff"/>${paths}</svg>\n`;
writeFileSync(join(out, 'icon.svg'), iconSvg);

const banner = (scale) => `<!doctype html><html><head><meta charset="utf-8"><style>
  *{box-sizing:border-box;margin:0}
  body{width:772px;height:250px;overflow:hidden;font-family:Inter,system-ui,sans-serif;color:#0E1513;
    background:radial-gradient(circle at 92% 18%,#E3F2EE 0,rgba(227,242,238,0) 42%),radial-gradient(circle at 70% 120%,#FDF0DC 0,rgba(253,240,220,0) 45%),#F6F7F6}
  .wrap{display:flex;align-items:center;gap:30px;height:100%;padding:0 44px}
  .mark{flex:none}
  h1{font-size:40px;line-height:1.05;letter-spacing:-.03em;font-weight:700}
  p{margin-top:10px;font-size:17px;line-height:1.4;color:#4B5754;max-width:440px}
  .route{display:flex;align-items:center;margin-top:20px;max-width:430px}
  .chip{padding:5px 11px;border-radius:999px;background:#fff;border:1px solid #DCE2E0;font:500 12.5px ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap}
  .seg{flex:1;height:2px;background:#0E1513;min-width:14px}
  .node{flex:none;width:30px;height:30px;border-radius:999px;background:#20A795;box-shadow:0 0 0 5px #D6EFEA;display:flex;align-items:center;justify-content:center}
  .node svg{width:14px;height:14px;stroke:#fff;fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}
</style></head><body><div class="wrap">
  <div class="mark">${mark(150)}</div>
  <div>
    <h1>RedirHub Redirects</h1>
    <p>Every old link lands on the right page.<br>Keep your rankings, see every click.</p>
    <div class="route"><span class="chip">/old-page</span><span class="seg"></span><span class="node"><svg viewBox="0 0 24 24"><path d="M5 12h12"/><path d="M12 6l6 6-6 6"/></svg></span><span class="seg"></span><span class="chip">/new-page</span></div>
  </div>
</div></body></html>`;

const browser = await chromium.launch();
async function render(html, width, height, scale, file) {
  const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: scale });
  await page.setContent(html);
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: join(out, file) });
  await page.close();
}
const iconHtml = `<!doctype html><html><body style="margin:0">${iconSvg.replace('width="256" height="256"', 'width="256" height="256" style="display:block"')}</body></html>`;
await render(iconHtml, 256, 256, 1, 'icon-256x256.png');
await render(iconHtml, 256, 256, 0.5, 'icon-128x128.png');
await render(banner(), 772, 250, 1, 'banner-772x250.png');
await render(banner(), 772, 250, 2, 'banner-1544x500.png');
await browser.close();

// The screenshots of the listing, as the admin end-to-end test last took them.
for (const n of [1, 2, 3]) {
  try {
    writeFileSync(join(out, `screenshot-${n}.png`), readFileSync(join(root, 'e2e/screenshots', `wporg-${n}.png`)));
  } catch {
    console.log(`no e2e/screenshots/wporg-${n}.png: run e2e/run.sh first`);
  }
}
console.log('written to .wordpress-org/');

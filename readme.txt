=== RedirHub Redirects ===
Contributors: redirhub
Tags: redirect, 301 redirect, 404, url shortener, link tracking
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Redirects and short links for all your sites in one place: keep your rankings when URLs change and see who clicks every link.

== Description ==

Pages move. Products get renamed. Sites change domain. Links get printed on flyers and shared in chats, and keep being clicked for years. Every one of those clicks should land somewhere useful, not on a "page not found".

RedirHub Redirects makes sure they do, and shows you who still uses them, without touching your server, your hosting or your DNS.

= What you get =

* **Every site in one place.** Manage the redirects of all your sites, WordPress or not, from one dashboard, with your team. No more logging in to ten admin areas to fix one link.
* **See who clicks your old links.** Every redirect is counted: how visitors arrived (search, social, email, QR scans, other sites), their country and city, device and browser, with bots filtered out. Compare with the previous period, watch visits live, get a weekly summary by email. And see the "not found" pages people still open, so you know which redirect is missing.
* **Your WordPress database stays light.** Visits and logs are kept in RedirHub, not in your site's database, which only holds the redirects themselves.
* **Redirects that outlive your site.** Change theme, host or platform: your redirects stay in RedirHub and keep working wherever the domain goes next.
* **Keep your search rankings when URLs change.** Permanent redirects tell Google where a page went, so its ranking moves with it. Moving a whole site? Map thousands of old URLs at once, from a spreadsheet, a Google Sheet, the API or your AI assistant.
* **No more dead ends.** Send every page your site cannot find to a page of your choice. Pages that work are never touched.
* **Short links and QR codes on your own domain.** `yoursite.com/spring` for a campaign, a QR code or a flyer. Change where it goes at any time, without reprinting anything.
* **Links that do more.** Protect a link with a password, split visitors between several destinations, keep bots away from a link, or help WeChat users open it in their browser.
* **Bring your redirects with you.** Already use Redirection, Rank Math, Yoast SEO Premium, All in One SEO, SEOPress, Safe Redirect Manager, Simple 301 Redirects or 301 Redirects? The plugin finds their redirects and sends them to RedirHub in one click, where you check them before anything changes.
* **Fast and safe.** Redirects are answered before your theme loads: about 6 ms, even with 50,000 of them. Only the addresses you set up are redirected; if anything goes wrong, your site behaves as if the plugin were not there.
* **Works with your cache.** When a redirect changes, the plugin clears the affected pages in WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cache Enabler, SiteGround Optimizer and more.
* **You always know it works.** One click tests your redirects from the outside, the way a visitor reaches them, through any cache or CDN. Problems show up on the plugin's page, as an admin notice and in Site Health.

= How it works =

You manage your redirects in the RedirHub dashboard: one by one, from a spreadsheet, by API, or by asking an AI assistant. This plugin keeps your site in sync within a minute and answers them itself. Your domain stays exactly where it is.

You need a [RedirHub](https://www.redirhub.com) account. RedirHub is a paid service: Core starts right away, and Pro comes with a 14-day free trial. See the [plans and prices](https://www.redirhub.com/pricing).

= External service =

This plugin connects your site to RedirHub, a service run by RedirHub:

* It downloads your domain's rules file from the rules address you paste (about once a minute; RedirHub's server sees your server's IP address and the plugin's name and version).
* With the integration key, it reports each redirect (and, where your domain asks for it, each "not found" page) to RedirHub's analytics: the visitor's IP address, the time, the address requested, the status, the referrer and the browser's User-Agent. No cookies or other data are sent. You can switch reports off under Tools → RedirHub.
* RedirHub calls your site's check path (`/_redirhub/check`) to confirm the domain is yours and serves its latest rules.

RedirHub's [terms of service](https://www.redirhub.com/legal/terms-of-service) and [privacy policy](https://www.redirhub.com/legal/privacy-policy) apply.

== Installation ==

1. Install and activate the plugin.
2. In the RedirHub dashboard, add your domain and choose **Connect through your own platform**, then **WordPress**.
3. Copy the two values it shows into **Tools → RedirHub** and press **Connect**.
4. Press **Check now** on the domain in the RedirHub dashboard. Your redirects are live.

Prefer configuration in code? Define `REDIRHUB_RULES_URL` and `REDIRHUB_INTEGRATION_KEY` in `wp-config.php`; the admin page then shows them read-only.

== Frequently Asked Questions ==

= Do I need to change my DNS or my server? =

No. Your domain keeps pointing at your current host, and there is nothing to set up on the server.

= Will redirects slow my site down? =

No. Redirects are answered before WordPress loads your theme and most plugins, in a few milliseconds. Your normal pages are not affected.

= Which requests does the plugin touch? =

GET and HEAD requests for the hostnames in your rules file. The admin, the login page, AJAX, cron, REST requests without a rule, and every other method go to WordPress unchanged.

= My redirect does not work. =

Run the test under Tools → RedirHub: it requests the redirect over the internet and says what stands in the way. The usual causes are plain permalinks (choose any other structure, so unknown paths reach WordPress), a page cache or CDN that kept the old page (purge it), or a real file at that path on the server (a file is served before WordPress).

= Does it work with WordPress in a subdirectory, or multisite? =

Multisite: yes, each site connects its own domain. WordPress in a subdirectory: only requests below that directory reach WordPress, so the plugin can only redirect those, and RedirHub checks the site at the root; serve WordPress at the root or use another way to connect the domain.

= Where are the rules kept? =

In the database, as options that are not autoloaded: a short summary and the redirects split into parts, so a visit reads only the part its address is in. Nothing is written to the file system.

= What happens if RedirHub is down? =

Your site keeps redirecting with the last rules it downloaded and tries again every 30 seconds. After a while the admin page, an admin notice and Site Health tell you downloads are failing.

= Can I bring the redirects of another redirect plugin? =

Yes. Tools → RedirHub lists the redirects that Redirection, Rank Math, Yoast SEO Premium, All in One SEO, SEOPress, Safe Redirect Manager, Simple 301 Redirects and 301 Redirects keep on your site, even when that plugin is switched off. "Import into RedirHub" opens RedirHub's import with them, ready to review; nothing changes until you press Import there. You can also download them as a CSV. Regex and wildcard patterns, redirects with conditions (login, referrer…) and error pages (410) are listed but not moved: add those in RedirHub. Once they are imported, deactivate the old plugin.

= Can I turn a part off? =

Analytics reports and link options (passwords, bot and WeChat blocking, rotating destinations) each have a switch under Tools → RedirHub. To stop the plugin answering anything in an emergency, define `REDIRHUB_REDIRECTS_DISABLE` as `true` in `wp-config.php`.

== Screenshots ==

1. Connect your domain in a minute: paste two values and you are done.
2. See at a glance that your redirects are live, verified and counted, and test them from the outside.
3. Every redirect your site serves, searchable.

== Changelog ==

= 1.0.0 =
* First release: rules file format v1 (redirects, frame pages, catch-alls, `p` and `q`), link options (passwords, rotation, bot and WeChat blocking), analytics reports, page cache purging, admin page, Site Health test, and a one-click import of the redirects of other redirect plugins.

== Upgrade Notice ==

= 1.0.0 =
First release.

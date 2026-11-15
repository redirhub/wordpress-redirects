=== RedirHub Redirects ===
Contributors: redirhub
Tags: redirects, 301 redirect, short links, migration, analytics
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Serve your RedirHub redirects from WordPress without moving DNS: migrations, short links and catch-alls, with click analytics.

== Description ==

[RedirHub](https://www.redirhub.com) manages redirects for whole domains: site migrations with thousands of mappings, branded short links, QR codes, campaign links. Usually a domain points its DNS at RedirHub. With this plugin your WordPress site serves them itself and your DNS stays where it is.

* **Connect once.** Paste the rules address (and, for analytics, the integration key) from the RedirHub dashboard. The plugin downloads your domain's rules and redirects at once.
* **Manage in RedirHub.** Add and change redirects in the dashboard, in bulk, by API or with an AI assistant; they reach your site within a minute.
* **Fast.** Exact redirects are answered while WordPress loads, before your theme and other plugins. The rules are kept in a PHP file that OPcache holds in memory, so 50,000 rules answer as fast as five (about 5 ms on a small server, against 40 ms for a page).
* **Safe.** Only GET and HEAD requests on your domain are touched. A catch-all redirects only pages WordPress cannot find, never a working page. When anything is wrong, RedirHub unreachable or a broken download, the site keeps using the last good copy, or answers as if the plugin were not there.
* **Every click counted.** With the integration key, each redirect is reported to RedirHub's analytics after the visitor has their answer, as a web server log line. Pages WordPress cannot find can be reported too, so you see which old links still need a redirect.
* **Everything a link can do.** Permanent and temporary redirects, frame pages, keeping the query or the path, password-protected links, links that rotate between destinations, keeping bots out, and the "open in your browser" page for WeChat.
* **Plays well with page caches.** When the rules change, the plugin purges the changed pages in WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cache Enabler, SiteGround Optimizer and others, so a cached page never hides a new redirect.
* **Shows you what is going on.** Tools → RedirHub shows the rules in use, when RedirHub last checked the site, the analytics reports, and a one-click test through any cache or CDN in front of the site. Problems also appear in Site Health and as an admin notice.

The rules file format is an open specification: [redirhub/integration-spec](https://github.com/redirhub/integration-spec). This plugin passes its golden sample.

= External service =

This plugin connects your site to RedirHub, a service run by RedirHub:

* It downloads your domain's rules file from the rules address you paste (about once a minute; RedirHub's server sees your server's IP address and the plugin's name and version).
* With the integration key, it reports each redirect (and, where your domain asks for it, each "not found" page) to RedirHub's analytics: the visitor's IP address, the time, the address requested, the status, the referrer and the browser's User-Agent. No cookies or other data are sent. You can switch reports off under Tools → RedirHub.
* RedirHub calls your site's check path (`/_redirhub/check`) to confirm the domain is yours and serves its latest rules.

RedirHub's [terms of service](https://www.redirhub.com/terms) and [privacy policy](https://www.redirhub.com/privacy) apply.

== Installation ==

1. Install and activate the plugin.
2. In the RedirHub dashboard, add your domain and choose **Connect through your own platform**, then **WordPress**.
3. Copy the rules address and the integration key into **Tools → RedirHub** and press **Connect**.
4. Press **Check now** on the domain in the RedirHub dashboard. Done.

Prefer configuration in code? Define `REDIRHUB_RULES_URL` and `REDIRHUB_INTEGRATION_KEY` in `wp-config.php`; the admin page then shows them read-only.

== Frequently Asked Questions ==

= Do I need to change my DNS? =

No. That is the point of the plugin: your domain keeps pointing at your WordPress host.

= Which requests does the plugin touch? =

GET and HEAD requests for the hostnames in your rules file. The admin, the login page, AJAX, cron, REST requests without a rule, and every other method go to WordPress unchanged.

= My redirect does not work. =

Run the test under Tools → RedirHub: it requests the redirect over the internet and says what stands in the way. The usual causes are plain permalinks (choose any other structure, so unknown paths reach WordPress), a page cache or CDN that kept the old page (purge it), or a real file at that path on the server (a file is served before WordPress).

= Does it work with WordPress in a subdirectory, or multisite? =

Multisite: yes, each site connects its own domain. WordPress in a subdirectory: only requests below that directory reach WordPress, so the plugin can only redirect those, and RedirHub checks the site at the root; serve WordPress at the root or use another way to connect the domain.

= Where are the rules kept? =

In `wp-content/cache/redirhub-redirects`, as a PHP file closed to the web, or in the database where that directory cannot be written.

= What happens if RedirHub is down? =

Your site keeps redirecting with the last rules it downloaded and tries again every 30 seconds. After a while the admin page, an admin notice and Site Health tell you downloads are failing.

= Can I turn a part off? =

Analytics reports and link options (passwords, bot and WeChat blocking, rotating destinations) each have a switch under Tools → RedirHub. To stop the plugin answering anything in an emergency, define `REDIRHUB_REDIRECTS_DISABLE` as `true` in `wp-config.php`.

== Screenshots ==

1. Connecting the site: paste the rules address and the key.
2. Connected: the rules in use, RedirHub's check, analytics, and the test.
3. The redirects in use, searchable.

== Changelog ==

= 1.0.0 =
* First release: rules file format v1 (redirects, frame pages, catch-alls, `p` and `q`), link options (passwords, rotation, bot and WeChat blocking), analytics reports, page cache purging, admin page, Site Health test.

== Upgrade Notice ==

= 1.0.0 =
First release.

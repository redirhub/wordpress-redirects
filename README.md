# wordpress-redirects: RedirHub Integration Mode for WordPress

**The official WordPress plugin for [RedirHub](https://www.redirhub.com) Integration Mode.** It
serves a domain's RedirHub redirects from the WordPress site itself, so the domain's DNS stays
where it is: the site downloads the domain's rules file, answers its redirects, proves the domain
with the check path, and reports every redirect to RedirHub's analytics.

It implements [Integration Mode format v1](https://github.com/redirhub/integration-spec) and passes
its golden sample, like the [Caddy](https://github.com/redirhub/caddy-redirects),
[Next.js](https://github.com/redirhub/nextjs-redirects) and
[Cloudflare Workers](https://github.com/redirhub/cloudflare-redirects) clients.

| | |
| --- | --- |
| Plugin name and slug | RedirHub Redirects, `redirhub-redirects` |
| Requires | WordPress 5.9+, PHP 7.4+ |
| `User-Agent` | `wordpress-redirects/1.0.0` |
| Settings | Tools → RedirHub, or `REDIRHUB_RULES_URL` and `REDIRHUB_INTEGRATION_KEY` in `wp-config.php` |

## Install

Download `redirhub-redirects.zip` from the latest release (or the `release` job of CI), upload it
under Plugins → Add New → Upload, activate, then follow Tools → RedirHub: paste the rules address
and the integration key from the RedirHub dashboard and press Connect. `readme.txt` is the
WordPress.org listing.

## How it answers

| Request | When | How |
| --- | --- | --- |
| The check path | while the plugin file loads | `200` `token=… etag=…`, `no-store` |
| An exact rule | while the plugin file loads, before the theme and the plugins after it | its redirect or frame page |
| The catch-all | `template_redirect`, last, only when WordPress answers 404 | its redirect |
| The site's 404 | `shutdown`, for hostnames with `report404` | reported |

- **Fast.** The parsed rules are written as a PHP array to `wp-content/cache/redirhub-redirects/`,
  which OPcache keeps in shared memory: with 50,000 rules a redirect answers in about 5 ms, a
  WordPress page in about 40 ms (e2e, Docker on one machine). Where that directory cannot be
  written, the copy goes into an option instead.
- **Fails open.** Only GET and HEAD on listed hostnames are touched; any error leaves the request
  to WordPress; a failed download keeps the last good copy.
- **Nobody waits.** Downloads (every `@refresh` seconds, never per request) and reports run after the
  visitor has the answer: after `fastcgi_finish_request()`/`litespeed_finish_request()`, after the
  plugin's own complete answers, otherwise in WP-Cron. One download at a time across processes.
- **Link options.** Unlike the other clients it carries out a link's options as RedirHub's edge
  does: the password form (the edge's unlock cookie and attempt limit), `nobot`, `nowechat`, and
  further destinations (`routing=order` in turn, otherwise at random). They can be switched off.
- **Page caches.** Answers set `DONOTCACHEPAGE`; when the rules change, the pages of the changed
  paths are purged in the cache plugins it knows (all pages for the first copy, a changed
  catch-all or list of hostnames). A download that follows one of the plugin's early answers
  leaves the purge to the next request that loads every plugin, since the cache plugins load
  after it.
- **Admin.** Tools → RedirHub: connect (pasting the whole setup text works too), status, RedirHub's
  last check, analytics, a self-test over HTTP, the rules in use, settings, disconnect. Site Health
  test and info, an admin notice when downloads keep failing.

## Release

1. Raise the version in `redirhub-redirects.php` (header and `REDIRHUB_REDIRECTS_VERSION`) and
   `readme.txt` (`Stable tag`, changelog).
2. Push a tag `v<version>`. The release workflow builds the zip, attaches it to a GitHub release
   and, once the repository has the `SVN_USERNAME` and `SVN_PASSWORD` secrets of the WordPress.org
   account, deploys the plugin and the listing's assets (`.wordpress-org/`, regenerated with
   `node bin/wporg-assets.mjs` after `e2e/run.sh`) to the directory.

## Develop

```bash
composer install
vendor/bin/phpunit            # the core against spec/v1, and edge cases
e2e/run.sh                    # WordPress in Docker: the golden sample over HTTP, scenarios, the admin page in Chromium
WP_IMAGE=wordpress:6-php7.4-apache e2e/run.sh   # the oldest PHP supported
e2e/backend.sh ../backend     # against a checkout of redirhub/backend (see the script)
bin/build-zip.sh              # build/redirhub-redirects.zip
npm install && npm run build:css   # after changing the admin page's classes (assets/src/admin.css)
```

The admin page uses the RedirHub dashboard's design: its tokens and Inter (`assets/src/admin.css`,
Tailwind CSS 4 with the `rh:` prefix and no global reset, so nothing leaks into the rest of
wp-admin) and lucide icons (`node bin/build-icons.mjs` writes `includes/Admin/Icons.php`). The
built `assets/admin.css` is committed; CI checks it matches its source.

`e2e/run.sh` needs Docker, PHP with curl, and Node with Playwright for the admin suite; it uses
ports 80, 18080 and 18081. `includes/Core/` is pure PHP (no WordPress) and is what the unit tests
and the golden sample exercise; `spec/v1/` is a copy of the specification's, checked in CI.

## License

Apache License 2.0, see [LICENSE](LICENSE). Copyright 2026 RedirHub.

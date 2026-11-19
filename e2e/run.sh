#!/usr/bin/env bash
# Runs every end-to-end suite against a fresh WordPress in Docker:
#   e2e/run.sh                     WordPress on PHP 8.3
#   WP_IMAGE=wordpress:6-php7.4-apache e2e/run.sh   the oldest PHP the plugin supports
#   e2e/run.sh --keep              leave the containers running afterwards
# Needs Docker, PHP with curl on the host, and Node with Playwright for the admin suite.
set -euo pipefail
cd "$(dirname "$0")"
keep=0; [ "${1:-}" = "--keep" ] && keep=1
shots="${SCREENSHOTS:-$(pwd)/screenshots}"

docker compose down -v >/dev/null 2>&1 || true
docker compose up -d >/dev/null
for _ in $(seq 1 90); do
  curl -s -o /dev/null http://127.0.0.1:18080/ && break; sleep 2
done
wp() { docker compose exec -T cli wp "$@"; }
for _ in $(seq 1 30); do wp core is-installed >/dev/null 2>&1 || wp db check >/dev/null 2>&1 && break; sleep 2; done
wp core install --url=http://promo.example.test --title="E2E Shop" --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.test --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
for slug in old-pricing exists beta; do
  wp post create --post_type=page --post_title="$slug" --post_name="$slug" --post_status=publish >/dev/null
done
wp plugin activate redirhub-redirects >/dev/null
php_version="$(docker compose exec -T wordpress php -r 'echo PHP_VERSION;')"
echo "WordPress $(wp core version) on PHP $php_version"

status=0
if [ "${SKIP_SUITES:-0}" = 1 ]; then exit 0; fi
curl -s -X POST "http://127.0.0.1:18081/control/spec?name=site" >/dev/null
wp eval '\RedirHub\Redirects\Settings::update(["rules_url"=>"http://mock:8080/rules/site.txt","key"=>"e2eIntegrationKey0123456789","link_options"=>false]); \RedirHub\Redirects\Sync::run(true);'
echo; echo "== golden sample (spec/v1) over HTTP"; php golden.php || status=1
echo; echo "== scenarios"; php scenarios.php || status=1
if node -e "require('playwright')" 2>/dev/null; then
  echo; echo "== admin page in a browser"; node admin.mjs "$shots" || status=1
  echo; echo "== other plugins' redirects, imported"
  ./other-plugins.sh >/dev/null && node import.mjs "$shots" || status=1
fi
log="$( { docker compose exec -T wordpress sh -c 'cat wp-content/debug.log 2>/dev/null'; docker compose logs --no-log-prefix wordpress 2>&1; } | grep -E 'PHP (Warning|Notice|Deprecated|Fatal|Parse)' | grep -i redirhub || true)"
if [ -n "$log" ]; then echo; echo "== PHP notices from the plugin"; echo "$log"; status=1; fi

[ "$keep" = 1 ] || docker compose down -v >/dev/null
exit $status

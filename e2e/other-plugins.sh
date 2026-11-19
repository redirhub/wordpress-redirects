#!/usr/bin/env bash
# Installs the free redirect plugins the import reads (from WordPress.org, cached in e2e/.cache)
# into the running e2e site, activates them and makes redirects in each: fixtures/seed-plugins.php.
set -euo pipefail
cd "$(dirname "$0")"
plugins=(redirection seo-by-rank-math safe-redirect-manager simple-301-redirects)
mkdir -p .cache
for slug in "${plugins[@]}"; do
  if [ ! -d ".cache/$slug" ]; then
    curl -fsSL "https://downloads.wordpress.org/plugin/$slug.zip" -o ".cache/$slug.zip"
    (cd .cache && unzip -q -o "$slug.zip")
  fi
  docker compose cp ".cache/$slug" "wordpress:/var/www/html/wp-content/plugins/$slug" >/dev/null 2>&1
  docker compose exec -T -u root wordpress chown -R www-data "/var/www/html/wp-content/plugins/$slug"
done
wp() { docker compose exec -T cli wp "$@" 2>/dev/null; }
wp plugin activate "${plugins[@]}" >/dev/null
wp option update rank_math_modules '["redirections"]' --format=json >/dev/null
docker compose cp fixtures/seed-plugins.php cli:/tmp/seed-plugins.php >/dev/null 2>&1
docker compose exec -T cli wp eval-file /tmp/seed-plugins.php 2>&1 | grep -v "^Deprecated\|^PHP Deprecated" || true
# The plugins stay installed but switched off: their data is what the import reads, and the
# other suites keep answering as before.
wp plugin deactivate "${plugins[@]}" >/dev/null

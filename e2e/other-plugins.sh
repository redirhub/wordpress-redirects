#!/usr/bin/env bash
# Installs the free redirect plugins the import reads (from WordPress.org, cached in e2e/.cache)
# into the running e2e site, activates them and makes redirects in each: fixtures/seed-plugins.php.
# Their current releases need WordPress 6.9; an older site gets the last release that ran on it,
# which keeps its redirects the same way.
set -euo pipefail
cd "$(dirname "$0")"
wp() { docker compose exec -T cli wp "$@" 2>/dev/null; }
version="$(wp core version)"
if [ "$(printf '%s\n6.9\n' "$version" | sort -V | head -1)" = "6.9" ]; then
  plugins=(redirection seo-by-rank-math safe-redirect-manager simple-301-redirects)
else
  plugins=(redirection.5.3.10 seo-by-rank-math.1.0.214 safe-redirect-manager.2.1.1 simple-301-redirects)
fi
slugs=()
for plugin in "${plugins[@]}"; do
  slug="${plugin%%.[0-9]*}"
  slugs+=("$slug")
  if [ ! -d ".cache/$plugin/$slug" ]; then
    mkdir -p ".cache/$plugin"
    curl -fsSL "https://downloads.wordpress.org/plugin/$plugin.zip" -o ".cache/$plugin.zip"
    unzip -q -o ".cache/$plugin.zip" -d ".cache/$plugin"
  fi
  docker compose exec -T -u root wordpress rm -rf "/var/www/html/wp-content/plugins/$slug"
  docker compose cp ".cache/$plugin/$slug" "wordpress:/var/www/html/wp-content/plugins/$slug" >/dev/null 2>&1
  docker compose exec -T -u root wordpress chown -R www-data "/var/www/html/wp-content/plugins/$slug"
done
wp plugin activate "${slugs[@]}" >/dev/null
wp option update rank_math_modules '["redirections"]' --format=json >/dev/null
docker compose cp fixtures/seed-plugins.php cli:/tmp/seed-plugins.php >/dev/null 2>&1
docker compose exec -T cli wp eval-file /tmp/seed-plugins.php 2>&1 | grep -v "^Deprecated\|^PHP Deprecated" || true
# The plugins stay installed but switched off: their data is what the import reads, and the
# other suites keep answering as before.
wp plugin deactivate "${slugs[@]}" >/dev/null

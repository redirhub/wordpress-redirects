#!/usr/bin/env bash
# Builds build/redirhub-redirects.zip: the plugin as installed, without what .distignore lists.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/redirhub-redirects
grep -v '^#' .distignore | sed '/^$/d' >build/.exclude
tar -cf - --exclude-from=build/.exclude --exclude=./build . | tar -xf - -C build/redirhub-redirects
rm build/.exclude
# WordPress.org accepts only the files a plugin normally has: anything else is a review issue.
unexpected=$(cd build/redirhub-redirects && find . -type f ! -name LICENSE ! -regex '.*\.\(php\|js\|css\|txt\|md\|png\|svg\|jpg\|json\|woff2\|pot\|po\|mo\)$')
if [ -n "$unexpected" ]; then
	echo "Files WordPress.org does not accept in a plugin (add them to .distignore):" >&2
	echo "$unexpected" >&2
	exit 1
fi
(cd build && zip -qr redirhub-redirects.zip redirhub-redirects)
echo "build/redirhub-redirects.zip ($(du -h build/redirhub-redirects.zip | cut -f1))"

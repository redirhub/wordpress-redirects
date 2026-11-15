#!/usr/bin/env bash
# Builds build/redirhub-redirects.zip: the plugin as installed, without what .distignore lists.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/redirhub-redirects
grep -v '^#' .distignore | sed '/^$/d' >build/.exclude
tar -cf - --exclude-from=build/.exclude --exclude=./build . | tar -xf - -C build/redirhub-redirects
rm build/.exclude
(cd build && zip -qr redirhub-redirects.zip redirhub-redirects)
echo "build/redirhub-redirects.zip ($(du -h build/redirhub-redirects.zip | cut -f1))"

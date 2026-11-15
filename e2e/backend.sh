#!/usr/bin/env bash
# The plugin against the real RedirHub backend: needs a checkout of redirhub/backend whose
# `.claude/dev-serve.sh` has been run once (the demo database and the local CA; then stop it, this
# script uses ports 80 and 443), and an /etc/hosts line `127.0.0.1 blog.example.test`.
#   e2e/backend.sh [path to the backend checkout, default ../../backend]
set -euo pipefail
cd "$(dirname "$0")"
backend="$(cd "${1:-../../backend}" && pwd)"
export COMPOSE_FILE=docker-compose.yml:docker-compose.backend.yml
tls="$(mktemp -d)"
passed=0; failed=0
check() { if [ "$2" = "$3" ]; then passed=$((passed+1)); echo "  ok   $1"; else failed=$((failed+1)); echo "  FAIL $1: want '$3', got '$2'"; fi; }
json() { php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(explode(".",$argv[1]) as $k){$d=$d[$k]??null;} echo is_scalar($d)?$d:json_encode($d);' "$1"; }

SKIP_SUITES=1 ./run.sh --keep
for _ in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:19000/ && break; sleep 1; done

# A certificate for the WordPress hostname from the backend's local CA, which this machine trusts.
printf 'subjectAltName=DNS:blog.example.test\nextendedKeyUsage=serverAuth\n' >"$tls/ext.cnf"
openssl req -newkey rsa:2048 -nodes -subj "/CN=blog.example.test" -keyout "$tls/key.pem" -out "$tls/cert.csr" 2>/dev/null
openssl x509 -req -in "$tls/cert.csr" -CA "$backend/storage/dev-env/tls/ca.pem" -CAkey "$backend/storage/dev-env/tls/ca-key.pem" \
  -CAcreateserial -days 7 -extfile "$tls/ext.cnf" -out "$tls/cert.pem" 2>/dev/null
node tls-proxy.mjs "$tls/cert.pem" "$tls/key.pem" >/dev/null 2>&1 &
proxy=$!
trap 'kill $proxy 2>/dev/null; rm -rf "$tls"' EXIT
sleep 1

app() {
  (cd "$backend" && env -u HTTPS_PROXY -u HTTP_PROXY -u https_proxy -u http_proxy \
    APP_ENV=local DB_DATABASE=redirhub_demo DB_STATISTICS_DATABASE=redirhub_demo QUEUE_CONNECTION=sync \
    EDGE_SYNC_S3_BUCKET=edge-sync INTEGRATION_RULES_BUCKET=rh-rules INTEGRATION_RULES_URL=http://s3:8333/rh-rules \
    EDGE_SYNC_S3_ENDPOINT=http://127.0.0.1:19000 EDGE_SYNC_S3_ACCESS_KEY_ID=any EDGE_SYNC_S3_SECRET_ACCESS_KEY=any \
    php "$OLDPWD/backend.php" "$backend" "$@")
}
wp() { docker compose exec -T cli wp "$@"; }
visit() { curl -s --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' "https://blog.example.test$1"; }

echo; echo "== the real backend publishes; the plugin serves; the backend's check connects"
setup="$(app setup)"
rules_url="$(echo "$setup" | json host.integration.rules_url)"
key="$(echo "$setup" | json key)"
echo "  rules file: ${rules_url%/*}/…"
check 'the domain waits for its check' "$(echo "$setup" | json host.status)" 'waiting'
check 'the backend offers WordPress in the setup' "$(echo "$setup" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo in_array("wordpress", array_column($d["host"]["integration"]["setup"],"platform"))?"yes":"no";')" 'yes'
wp eval "\\RedirHub\\Redirects\\Settings::update(['rules_url'=>'$rules_url','key'=>'$key']); echo json_encode(\\RedirHub\\Redirects\\Sync::run(true));" >/dev/null
check 'the plugin downloads the backend’s rules file' "$(wp eval 'echo \RedirHub\Redirects\State::get()["rules"];')" '2'
check 'an exact redirect over HTTPS' "$(visit /old-post)" '301 https://www.example.com/new-post'
check 'q keeps the query' "$(visit '/spring-sale?ref=mail')" '302 https://shop.example.com/sale?src=blog&ref=mail'
host="$(app check)"
check 'the backend’s own check connects the domain' "$(echo "$host" | json status)" 'connected'
check 'it sees the plugin using its latest rules' "$(echo "$host" | json integration.rules_in_use)" "$(echo "$host" | json integration.rules_etag)"

echo; echo "== a change in RedirHub reaches the site"
host="$(app change)"
wp eval 'echo json_encode(\RedirHub\Redirects\Sync::run(true));' >/dev/null
check 'the changed destination and type' "$(visit /old-post)" '308 https://www.example.com/newer-post'
check 'the new redirect' "$(visit /launch)" '307 https://launch.example.com/'
host="$(app check)"
check 'the check sees the new rules in use' "$(echo "$host" | json integration.rules_in_use)" "$(echo "$host" | json integration.rules_etag)"
check 'still connected' "$(echo "$host" | json status)" 'connected'

echo; echo "$passed passed, $failed failed"
[ "$failed" = 0 ]

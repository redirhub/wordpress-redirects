<?php
/**
 * What the golden sample does not cover, against a real WordPress: link options (password,
 * bots, WeChat, rotation), rules changing while the site runs, downloads failing, the database
 * fallback, scale (50,000 rules) and speed.
 *
 * Usage: php e2e/scenarios.php   (after e2e/run.sh has set the site up)
 */

require __DIR__ . '/golden-lib.php';

$wp   = 'http://127.0.0.1:18080';
$mock = 'http://127.0.0.1:18081';

function rules_file( array $lines, string $host = 'links.example.test', string $extra = '' ): string {
	return "@format 1\n@refresh 60\n@hits http://mock:8080/_edge/hits\n@check /_redirhub/check\n@host {$host} check=FAKElink report404\n{$extra}" . implode( "\n", $lines ) . "\n";
}

function connect( string $body ): void {
	global $mock;
	post( $mock . '/control/rules?name=links', $body );
	post( $mock . '/control/status?code=0', '' );
	wp_eval( '\RedirHub\Redirects\Settings::update(["rules_url"=>"http://mock:8080/rules/links.txt","key"=>"e2eIntegrationKey0123456789","link_options"=>true]); $r = \RedirHub\Redirects\Sync::run(true); if ($r["status"] !== "updated" && $r["status"] !== "unchanged") { echo "SYNC FAILED ", json_encode($r); }' );
}

$hash = password_hash( 'open sesame', PASSWORD_BCRYPT );
$rules = array(
	"links.example.test/vault\thttps://dest.example.test/secret\t302\t\tpassword={$hash}",
	"links.example.test/human\thttps://dest.example.test/human\t302\t\tplugins=nobot",
	"links.example.test/cn\thttps://dest.example.test/cn\t302\t\tplugins=nowechat",
	"links.example.test/turns\thttps://a.example.test/\t302\t\tdestinations=https://b.example.test/,https://c.example.test/ routing=order",
	"links.example.test/dice\thttps://a.example.test/\t302\t\tdestinations=https://b.example.test/ routing=random",
	"links.example.test/old\thttps://dest.example.test/v1",
);
// A clean slate: no attempts counted by an earlier run, no download or report waiting in WP-Cron.
wp_eval( 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%redirhub_pw_%\'"); wp_cache_flush(); wp_clear_scheduled_hook("redirhub_redirects_sync"); wp_clear_scheduled_hook("redirhub_redirects_report");' );
connect( rules_file( $rules ) );

section( 'Password-protected link' );
list( $code, $head, $body ) = http( $wp, 'GET', 'https://links.example.test/vault', array( 'Accept-Language' => 'zh-CN,zh;q=0.9' ) );
check( 'asks for the password (401), in the visitor’s language', 401 === $code && false !== strpos( $body, '这个链接受密码保护' ) && false !== strpos( $body, 'name="password"' ) );
check( 'never shows the destination before unlocking', false === strpos( $body, 'dest.example.test' ) );
check( 'is not cached', 'no-store' === ( $head['cache-control'] ?? '' ) );
list( $code, , $body ) = http( $wp, 'POST', 'https://links.example.test/vault', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'password=wrong' );
check( 'a wrong password is refused', 401 === $code && false !== strpos( $body, 'didn’t match' ) );
list( $code, $head ) = http( $wp, 'POST', 'https://links.example.test/vault?utm=x', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'password=' . rawurlencode( 'open sesame' ) );
preg_match( '/^(__rh_pw_[a-f0-9]{16})=([^;]+)/', $head['set-cookie'] ?? '', $cookie );
check( 'the right one unlocks: 303 back to the same address with a cookie', 303 === $code && '/vault?utm=x' === ( $head['location'] ?? '' ) && ! empty( $cookie ) && false !== stripos( $head['set-cookie'], 'httponly' ) );
list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/vault', array( 'Cookie' => ( $cookie[1] ?? 'x' ) . '=' . ( $cookie[2] ?? '' ) ) );
check( 'with the cookie, the link redirects', 302 === $code && 'https://dest.example.test/secret' === ( $head['location'] ?? '' ) );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/vault', array( 'Cookie' => ( $cookie[1] ?? 'x' ) . '=1999999999.forged' ) );
check( 'a forged cookie does not', 401 === $code );
for ( $i = 0; $i < 10; $i++ ) {
	list( $code ) = http( $wp, 'POST', 'https://links.example.test/vault', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'password=guess' . $i );
}
check( 'more than 10 tries a minute are refused (429)', 429 === $code );

section( 'Bots and WeChat' );
list( $code, , $body ) = http( $wp, 'GET', 'https://links.example.test/human', array( 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
check( 'nobot keeps Googlebot out (403)', 403 === $code && 'Robot access denied' === $body );
list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/human', array( 'User-Agent' => 'Mozilla/5.0 (Macintosh)' ) );
check( 'nobot lets a browser through', 302 === $code && 'https://dest.example.test/human' === ( $head['location'] ?? '' ) );
list( $code, , $body ) = http( $wp, 'GET', 'https://links.example.test/cn', array( 'User-Agent' => 'Mozilla/5.0 (iPhone) MicroMessenger/8.0.40', 'Accept-Language' => 'zh-CN' ) );
check( 'nowechat shows WeChat’s in-app browser the “open in your browser” page', 200 === $code && false !== strpos( $body, '请在浏览器中打开' ) );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/cn', array( 'User-Agent' => 'Mozilla/5.0 (iPhone) Safari' ) );
check( 'nowechat redirects other browsers', 302 === $code );

section( 'Several destinations' );
$seen = array();
for ( $i = 0; $i < 6; $i++ ) {
	list( , $head ) = http( $wp, 'GET', 'https://links.example.test/turns' );
	$seen[] = parse_url( $head['location'] ?? '', PHP_URL_HOST );
}
check( 'routing=order takes them in turn: ' . implode( ' ', $seen ), array( 'a.example.test', 'b.example.test', 'c.example.test', 'a.example.test', 'b.example.test', 'c.example.test' ) === $seen );
$hosts = array();
for ( $i = 0; $i < 40; $i++ ) {
	list( , $head ) = http( $wp, 'GET', 'https://links.example.test/dice' );
	$hosts[ parse_url( $head['location'] ?? '', PHP_URL_HOST ) ] = true;
}
check( 'routing=random uses every destination', isset( $hosts['a.example.test'], $hosts['b.example.test'] ) && 2 === count( $hosts ) );
wp_eval( '\RedirHub\Redirects\Settings::update(["link_options"=>false]);' );
list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/vault' );
check( 'with link options off, a protected link redirects plainly to its target', 302 === $code && 'https://dest.example.test/secret' === ( $head['location'] ?? '' ) );
wp_eval( '\RedirHub\Redirects\Settings::update(["link_options"=>true]);' );

section( 'Rules change while the site runs' );
post( $mock . '/control/reset', '' );
post( $mock . '/control/rules?name=links', rules_file( array_merge( $rules, array( "links.example.test/fresh\thttps://dest.example.test/fresh\t307" ) ) ) );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/fresh' );
check( 'a new rule is not served before the copy is due (no download per request)', 307 !== $code );
wp_eval( '\RedirHub\Redirects\State::update(["fetched_at"=>time()-120,"attempt_at"=>0]); delete_option("e2e_purges");' );
http( $wp, 'GET', 'https://links.example.test/old' ); // Finds the copy due: downloads after its answer.
$code = 0;
for ( $i = 0; $i < 20 && 307 !== $code; $i++ ) {
	usleep( 250000 );
	list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/fresh' );
}
check( 'once due, the next visit downloads it in the background, and it is served', 307 === $code && 'https://dest.example.test/fresh' === ( $head['location'] ?? '' ) );
http( $wp, 'GET', 'https://links.example.test/exists' ); // A page: every plugin loads.
$purges = trim( wp_eval( 'echo implode("|", (array) get_option("e2e_purges", []));' ) );
check( 'with every plugin loaded (the next page WordPress renders), the new path is purged (' . $purges . ')', false !== strpos( $purges, '/fresh' ) );
$log = mock_log();
$downloads = array_values( array_filter( $log, function ( $e ) { return '/rules/links.txt' === $e->path; } ) );
check( 'exactly one download (' . count( $downloads ) . '), with the plugin’s User-Agent', 1 === count( $downloads ) && 0 === strpos( $downloads[0]->ua, 'wordpress-redirects/1.0.0' ) );
check( 'it asked with If-None-Match', '' !== ( $downloads[0]->inm ?? '' ) );
$state = json_decode( wp_eval( 'echo json_encode(\RedirHub\Redirects\Sync::run(true));' ) );
check( 'an unchanged file is answered 304 and kept', 'unchanged' === ( $state->status ?? '' ) );

section( 'Downloads failing' );
post( $mock . '/control/status?code=503', '' );
$raw    = wp_eval( 'echo json_encode(\RedirHub\Redirects\Sync::run(true));' );
$result = json_decode( $raw );
if ( null === $result ) {
	echo "    raw output: {$raw}\n";
}
list( $code ) = http( $wp, 'GET', 'https://links.example.test/fresh' );
check( 'a 503 keeps the last good copy: redirects go on (' . json_encode( $result ) . " {$code})", 'failed' === ( $result->status ?? '' ) && 307 === $code );
post( $mock . '/control/status?code=0', '' );
post( $mock . '/control/rules?name=links', "@format 2\n@host links.example.test check=X\n" );
$result = json_decode( wp_eval( 'echo json_encode(\RedirHub\Redirects\Sync::run(true));' ) );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/fresh' );
check( 'an unknown format keeps the last good copy', 'failed' === ( $result->status ?? '' ) && 0 === strpos( $result->error ?? '', 'format:' ) && 307 === $code );
post( $mock . '/control/rules?name=links', 'not a rules file at all <html>' );
$result = json_decode( wp_eval( 'echo json_encode(\RedirHub\Redirects\Sync::run(true));' ) );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/fresh' );
check( 'garbage parses to no hostnames: the site answers itself', 307 !== $code && 'updated' === ( $result->status ?? '' ) );
connect( rules_file( $rules ) );

section( 'Copy kept in the database where wp-content/cache is read-only' );
docker( 'wordpress', 'chmod 0555 /var/www/html/wp-content/cache/redirhub-redirects && rm -f /var/www/html/wp-content/cache/redirhub-redirects/rules-*' );
post( $mock . '/control/rules?name=links', rules_file( array_merge( $rules, array( "links.example.test/db\thttps://dest.example.test/db" ) ) ) );
$file = trim( wp_eval( '\RedirHub\Redirects\Sync::run(true); echo \RedirHub\Redirects\State::get()["file"];' ) );
list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/db' );
check( 'the copy goes to the database and redirects work', 'option' === $file && 301 === $code && 'https://dest.example.test/db' === ( $head['location'] ?? '' ) );
docker( 'wordpress', 'chmod 0777 /var/www/html/wp-content/cache/redirhub-redirects' );
$file = trim( wp_eval( '\RedirHub\Redirects\State::update(["etag"=>""]); \RedirHub\Redirects\Sync::run(true); echo \RedirHub\Redirects\State::get()["file"], " ", get_option("redirhub_redirects_copy") ? "copy-left" : "copy-gone";' ) );
check( 'back to a file once writable, and the database copy is removed', 1 === preg_match( '/^rules-[a-f0-9]{32}\.php copy-gone$/', $file ) );

section( 'Scale: 50,000 rules' );
$many = array();
for ( $i = 0; $i < 50000; $i++ ) {
	$many[] = "links.example.test/old/page-{$i}\thttps://new.example.test/page-{$i}";
}
post( $mock . '/control/rules?name=links', rules_file( $many ) );
$t      = microtime( true );
$result = json_decode( wp_eval( 'echo json_encode(\RedirHub\Redirects\Sync::run(true) + ["rules" => \RedirHub\Redirects\State::get()["rules"]]);' ) );
$took   = microtime( true ) - $t;
list( $code, $head ) = http( $wp, 'GET', 'https://links.example.test/old/page-49999' );
check( sprintf( '50,000 rules download, parse and store in %.1f s (wp-cli included)', $took ), 'updated' === ( $result->status ?? '' ) && 50000 === ( $result->rules ?? 0 ) && 301 === $code && 'https://new.example.test/page-49999' === ( $head['location'] ?? '' ) );
$redirect = timing( $wp, 'https://links.example.test/old/page-12345', 60 );
$page     = timing( $wp, 'https://links.example.test/exists', 60 );
printf( "    median time to first byte: redirect with 50,000 rules %.1f ms, a WordPress page %.1f ms\n", $redirect * 1000, $page * 1000 );
check( 'a redirect answers faster than a WordPress page', $redirect < $page );

section( 'Hardening' );
$out = wp_eval( '$r = new \RedirHub\Redirects\Core\RuleSet(["etag" => "a\rprint(\"PWNED\");//", "hosts" => []]); $f = \RedirHub\Redirects\Store::save($r); ob_start(); include \RedirHub\Redirects\Store::dir() . "/" . $f; echo ob_get_clean() === "" ? "inert" : "EXECUTED";' );
check( 'a hostile ETag in the cache file stays inert', 'inert' === trim( $out ) );
connect( rules_file( $rules ) );
wp_eval( 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'%redirhub_pw_%\'");' );
list( $code, $head ) = http( $wp, 'POST', 'https://links.example.test//vault', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'password=' . rawurlencode( 'open sesame' ) );
check( 'unlocking never sends a protocol-relative Location (' . ( $head['location'] ?? '-' ) . ')', 303 === $code && '/vault' === ( $head['location'] ?? '' ) );
$runs = shell_exec( 'cd ' . escapeshellarg( __DIR__ ) . ' && for i in 1 2 3 4; do docker compose exec -T cli wp eval \'\RedirHub\Redirects\State::update(["fetched_at"=>0]); echo \RedirHub\Redirects\Sync::run()["status"], "\n";\' 2>/dev/null & done; wait' );
$statuses = array_count_values( array_filter( array_map( 'trim', explode( "\n", (string) $runs ) ) ) );
check( 'concurrent downloads: one at a time (' . json_encode( $statuses ) . ')', ( $statuses['updated'] ?? 0 ) + ( $statuses['unchanged'] ?? 0 ) >= 1 && array_sum( $statuses ) === 4 );
$file = trim( wp_eval( '$f = \RedirHub\Redirects\State::get()["file"]; echo is_file(\RedirHub\Redirects\Store::dir() . "/" . $f) ? "present" : "missing";' ) );
check( 'and the copy in use is on disk afterwards', 'present' === $file );

section( 'Password-protected catch-all' );
connect( rules_file( $rules, 'links.example.test', "@host vault.example.test check=FAKEvlt\nvault.example.test/*\thttps://dest.example.test/v/\t302\tp\tpassword={$hash}\n" ) );
list( $code ) = http( $wp, 'GET', 'https://vault.example.test/anything' );
check( 'a missing page of the hostname asks for the password', 401 === $code );
list( $code, $head ) = http( $wp, 'POST', 'https://vault.example.test/anything', array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'password=' . rawurlencode( 'open sesame' ) );
preg_match( '/^(__rh_pw_[a-f0-9]{16})=([^;]+)/', $head['set-cookie'] ?? '', $cookie );
check( 'the form posts back and unlocks', 303 === $code && ! empty( $cookie ) );
list( $code, $head ) = http( $wp, 'GET', 'https://vault.example.test/anything', array( 'Cookie' => ( $cookie[1] ?? 'x' ) . '=' . ( $cookie[2] ?? '' ) ) );
check( 'then the catch-all redirects', 302 === $code && 'https://dest.example.test/v/anything' === ( $head['location'] ?? '' ) );
list( $code ) = http( $wp, 'GET', 'https://vault.example.test/exists' );
check( 'a page that exists is never taken over', 200 === $code );
connect( rules_file( $rules ) );

section( 'Disconnect' );
connect( rules_file( $rules ) );
wp_eval( 'do_action("admin_post_redirhub_disconnect");' ); // Without a nonce: must be refused.
list( $code ) = http( $wp, 'GET', 'https://links.example.test/old' );
check( 'the disconnect action refuses a request without a nonce', 301 === $code );
wp_eval( '\RedirHub\Redirects\Settings::update(["rules_url"=>"","key"=>""]); \RedirHub\Redirects\Store::clear(); \RedirHub\Redirects\State::reset();' );
list( $code ) = http( $wp, 'GET', 'https://links.example.test/old' );
check( 'disconnected, the site answers every path itself', 404 === $code );
$left = trim( docker( 'wordpress', 'ls /var/www/html/wp-content/cache/redirhub-redirects/ | grep -c rules- || true' ) );
check( 'no copy is left behind', '0' === $left );

finish();

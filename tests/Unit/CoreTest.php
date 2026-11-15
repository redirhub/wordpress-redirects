<?php
/**
 * Edge cases of the format beyond the golden sample.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedirHub\Redirects\Core\HitLine;
use RedirHub\Redirects\Core\Location;
use RedirHub\Redirects\Core\Parser;
use RedirHub\Redirects\Core\Path;
use RedirHub\Redirects\Core\Request;
use RedirHub\Redirects\Core\Router;
use RedirHub\Redirects\Core\Rule;
use RedirHub\Redirects\Core\UnknownFormat;

final class CoreTest extends TestCase {

	private static function rules( string $rules ): string {
		return "@format 1\n@host a.test check=T\n" . $rules;
	}

	public function test_unknown_format_throws(): void {
		$this->expectException( UnknownFormat::class );
		Parser::parse( "@format 2\n@host a.test check=T\n" );
	}

	public function test_minor_versions_and_missing_format_are_v1(): void {
		$this->assertSame( array( 'a.test' ), Parser::parse( "@format 1.3\n@host a.test\n" )->hostnames() );
		$this->assertSame( array( 'a.test' ), Parser::parse( "@host a.test\n" )->hostnames() );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function refreshes(): array {
		return array(
			'missing' => array( '', 60 ),
			'below'   => array( "@refresh 5\n", 30 ),
			'above'   => array( "@refresh 99999\n", 3600 ),
			'garbage' => array( "@refresh soon\n", 60 ),
			'inside'  => array( "@refresh 120\n", 120 ),
		);
	}

	/**
	 * @dataProvider refreshes
	 */
	public function test_refresh_is_clamped( string $line, int $expected ): void {
		$this->assertSame( $expected, Parser::parse( "@format 1\n" . $line )->refresh() );
	}

	public function test_crlf_lines_and_blank_lines(): void {
		$rules = Parser::parse( "@format 1\r\n\r\n@host a.test check=T\r\na.test/x\thttps://b.test/\t302\r\n" );

		$this->assertSame( 302, $rules->exact( 'a.test', '/x' )->status );
	}

	public function test_the_first_rule_for_a_source_and_the_first_host_line_win(): void {
		$rules = Parser::parse( self::rules( "@host a.test check=OTHER report404\na.test/x\thttps://one.test/\na.test/x/\thttps://two.test/\na.test/*\thttps://c1.test/\na.test/*\thttps://c2.test/\n" ) );

		$this->assertSame( 'https://one.test/', $rules->exact( 'a.test', '/x' )->target );
		$this->assertSame( 'https://c1.test/', $rules->catch_all( 'a.test' )->target );
		$this->assertSame( 'T', $rules->token( 'a.test' ) );
		$this->assertFalse( $rules->report404( 'a.test' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_lines(): array {
		return array(
			'scheme in source'  => array( "https://a.test/x\thttps://b.test/" ),
			'no path'           => array( "a.test\thttps://b.test/" ),
			'space in source'   => array( "a.test/x y\thttps://b.test/" ),
			'quote in source'   => array( "a.test/x\"\thttps://b.test/" ),
			'relative target'   => array( "a.test/x\t/b" ),
			'ftp target'        => array( "a.test/x\tftp://b.test/" ),
			'bad status'        => array( "a.test/x\thttps://b.test/\t303" ),
			'unknown flag'      => array( "a.test/x\thttps://b.test/\t301\tz" ),
			'one column'        => array( 'a.test/x' ),
			'unlisted hostname' => array( "b.test/x\thttps://b.test/" ),
		);
	}

	/**
	 * @dataProvider invalid_lines
	 */
	public function test_unparsable_rules_are_skipped( string $line ): void {
		$rules = Parser::parse( self::rules( $line . "\na.test/ok\thttps://ok.test/\n" ) );

		$this->assertSame( 1, $rules->count() );
		$this->assertFalse( $rules->has_exact( 'a.test', '/x' ) );
	}

	public function test_a_query_in_the_source_is_ignored_and_further_columns_too(): void {
		$rules = Parser::parse( self::rules( "a.test/x?y=1\thttps://b.test/\t308\tq\tk=v\textra\n" ) );
		$rule  = $rules->exact( 'a.test', '/x' );

		$this->assertSame( 308, $rule->status );
		$this->assertTrue( $rule->keep_query );
		$this->assertSame( array( 'k' => 'v' ), $rule->options );
	}

	public function test_powered_by_must_be_short_printable_ascii(): void {
		$this->assertNull( Parser::parse( "@powered-by " . str_repeat( 'x', 129 ) . "\n" )->powered_by() );
		$this->assertNull( Parser::parse( "@powered-by caf\xC3\xA9\n" )->powered_by() );
		$this->assertSame( 'Acme  (x)', Parser::parse( "@powered-by   Acme  (x)  \n" )->powered_by() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function hosts(): array {
		return array(
			'case and port'  => array( 'Shop.Example.COM:8443', 'shop.example.com' ),
			'trailing dot'   => array( 'shop.example.com.', 'shop.example.com' ),
			'ipv6 with port' => array( '[2001:db8::1]:443', '2001:db8::1' ),
			'bare ipv6'      => array( '2001:db8::1', '2001:db8::1' ),
		);
	}

	/**
	 * @dataProvider hosts
	 */
	public function test_hostnames_are_normalised( string $value, string $expected ): void {
		$this->assertSame( $expected, Path::host( $value ) );
	}

	public function test_paths_match_decoded_and_trimmed(): void {
		$this->assertSame( '/', Path::match( '/' ) );
		$this->assertSame( '/', Path::match( '//' ) );
		$this->assertSame( '/a b', Path::match( '/a%20b/' ) );
		$this->assertSame( '/100%zz', Path::match( '/100%zz' ), 'a malformed escape is matched as written' );
		$this->assertSame( '/a+b', Path::match( '/a+b' ), 'a plus is not a space in a path' );
	}

	/**
	 * @return array<string, array{string, bool, bool, string, string, string}>
	 */
	public static function locations(): array {
		return array(
			'plain'                    => array( 'https://b.test/x', false, false, '/old', 'a=1', 'https://b.test/x' ),
			'q onto target with query' => array( 'https://b.test/x?k=v', false, true, '/old', 'a=1', 'https://b.test/x?k=v&a=1' ),
			'q onto bare question'     => array( 'https://b.test/x?', false, true, '/old', 'a=1', 'https://b.test/x?a=1' ),
			'q keeps the fragment last' => array( 'https://b.test/x#top', false, true, '/old', 'a=1', 'https://b.test/x?a=1#top' ),
			'p without double slash'   => array( 'https://b.test/', true, false, '/old/page', '', 'https://b.test/old/page' ),
			'p onto a path'            => array( 'https://b.test/base', true, true, '/old', 'a=1', 'https://b.test/base/old?a=1' ),
			'p at the root'            => array( 'https://b.test', true, false, '/', '', 'https://b.test/' ),
		);
	}

	/**
	 * @dataProvider locations
	 */
	public function test_location( string $target, bool $append, bool $query, string $path, string $request_query, string $expected ): void {
		$rule = new Rule( array( 'a.test/*', $target, 301, $append, $query, array() ) );

		$this->assertSame( $expected, Location::build( $rule, $path, $request_query ) );
	}

	public function test_escape_keeps_escapes_and_encodes_raw_bytes(): void {
		$this->assertSame( '/caf%C3%A9/%20x', Path::escape( "/caf\xC3\xA9/%20x" ) );
		$this->assertSame( 'a=%22b%22&c=d?e', Path::escape( 'a="b"&c=d?e', true ) );
		$this->assertSame( '/%3Cscript%3E', Path::escape( '/<script>' ) );
	}

	public function test_report_line_escapes_control_characters_and_truncates(): void {
		$line = HitLine::format(
			array(
				'ip'         => null,
				'method'     => 'GET',
				'scheme'     => 'https',
				'host'       => 'a.test',
				'path'       => '/x',
				'query'      => '',
				'status'     => 404,
				'referer'    => "a\tb",
				'user_agent' => str_repeat( 'é', 600 ),
			),
			0
		);

		$this->assertStringStartsWith( '- - - [01/Jan/1970:00:00:00 +0000] "GET https://a.test/x HTTP/1.1" 404 0 "a\x09b" "', $line );
		$this->assertSame( 1024, strlen( substr( $line, strrpos( $line, ' "' ) + 2, -1 ) ) );
	}

	public function test_destinations_and_plugins_options(): void {
		$rule = new Rule( array( 'a.test/x', 'https://one.test/', 302, false, false, array( 'destinations' => 'https://two.test/a%2Cb,ftp://bad,https://three.test/', 'plugins' => 'nobot,nowechat' ) ) );

		$this->assertSame( array( 'https://one.test/', 'https://two.test/a,b', 'https://three.test/' ), $rule->destinations() );
		$this->assertSame( array( 'nobot', 'nowechat' ), $rule->plugins() );
	}

	public function test_the_check_path_needs_a_listed_hostname_and_get_or_head(): void {
		$router = new Router( Parser::parse( self::rules( '' ), 'W/"abc"' ) );

		$this->assertSame( 'token=T etag=abc', $router->before( Request::from_url( 'GET', 'https://A.test/_redirhub/check' ) )->body );
		$this->assertNull( $router->before( Request::from_url( 'POST', 'https://a.test/_redirhub/check' ) ) );
		$this->assertNull( $router->before( Request::from_url( 'GET', 'https://b.test/_redirhub/check' ) ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function etags(): array {
		return array(
			'quoted'                 => array( '"abc"', 'abc' ),
			'weak'                   => array( 'W/"abc"', 'abc' ),
			'a carriage return'      => array( "\"a\"\rsystem(\$_GET['c']);//", '' ),
			'a line feed'            => array( "\"a\nb\"", '' ),
			'a NUL'                  => array( "\"a\x00b\"", '' ),
			'non-ASCII'              => array( "\"caf\xC3\xA9\"", '' ),
			'a quote inside'         => array( '"a"b"', '' ),
			'empty'                  => array( '', '' ),
		);
	}

	/**
	 * An ETag that is not visible ASCII is no ETag: it never reaches the check path, the state
	 * or the cache file.
	 *
	 * @dataProvider etags
	 */
	public function test_etags_are_visible_ascii_or_nothing( string $header, string $expected ): void {
		$this->assertSame( $expected, Path::etag( $header ) );
		$this->assertSame( $expected, Parser::parse( "@host a.test check=T\n", $header )->etag() );
	}

	public function test_an_etag_header_is_sent_back_only_when_printable(): void {
		$this->assertSame( 'W/"abc"', Path::etag_header( ' W/"abc" ' ) );
		$this->assertSame( '', Path::etag_header( "\"a\"\rX-Evil: 1" ) );
	}

	public function test_hostile_values_stay_inert_in_the_exported_copy(): void {
		$rules = Parser::parse( "@format 1\n@host a.test check=T'.die('x').'\na.test/x\thttps://b.test/?a='.die('y').'\n" );
		$php   = '<?php return ' . var_export( $rules->to_array(), true ) . ';';
		$file  = tempnam( sys_get_temp_dir(), 'rh' );
		file_put_contents( $file, $php );

		$this->assertSame( $rules->to_array(), include $file );
		unlink( $file );
	}
}

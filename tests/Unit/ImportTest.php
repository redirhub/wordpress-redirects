<?php
/**
 * Other plugins' redirects as RedirHub's import file.
 *
 * @package RedirHub\Redirects
 */

use PHPUnit\Framework\TestCase;
use RedirHub\Redirects\Import\Converter;

final class ImportTest extends TestCase {

	private function convert( array $items, string $home = 'https://www.example.test' ): array {
		return ( new Converter( $home ) )->convert( $items, 'Imported from Test (WordPress)' );
	}

	public function test_paths_get_the_site_host_and_targets_become_absolute(): void {
		$out = $this->convert(
			array(
				array( 'source' => '/old-page/', 'target' => '/new-page/', 'code' => 301 ),
				array( 'source' => 'no-slash', 'target' => 'relative', 'code' => 302 ),
				array( 'source' => '/promo?utm=1', 'target' => 'https://shop.example.com/sale', 'code' => 307 ),
				array( 'source' => 'https://Blog.Example.test/2019/post', 'target' => '//cdn.example.com/a', 'code' => 308 ),
			)
		);

		$this->assertSame(
			array(
				array( 'www.example.test/old-page/', 'https://www.example.test/new-page/', '301', 'Imported from Test (WordPress)' ),
				array( 'www.example.test/no-slash', 'https://www.example.test/relative', '302', 'Imported from Test (WordPress)' ),
				array( 'www.example.test/promo?utm=1', 'https://shop.example.com/sale', '307', 'Imported from Test (WordPress)' ),
				array( 'blog.example.test/2019/post', 'https://cdn.example.com/a', '308', 'Imported from Test (WordPress)' ),
			),
			$out['rows']
		);
		$this->assertSame( array(), $out['skipped'] );
	}

	public function test_a_site_on_a_port_keeps_it_in_targets(): void {
		$out = $this->convert( array( array( 'source' => '/a', 'target' => '/b', 'code' => 301 ) ), 'http://localhost:8080' );

		$this->assertSame( array( 'localhost/a', 'http://localhost:8080/b', '301', 'Imported from Test (WordPress)' ), $out['rows'][0] );
	}

	public function test_303_is_a_temporary_redirect(): void {
		$this->assertSame( '302', $this->convert( array( array( 'source' => '/a', 'target' => '/b', 'code' => 303 ) ) )['rows'][0][2] );
	}

	public function test_what_redirhub_cannot_serve_as_it_is_is_counted_by_reason(): void {
		$out = $this->convert(
			array(
				array( 'source' => '/ok', 'target' => '/fine', 'code' => 301 ),
				array( 'source' => '/blog/(.*)', 'target' => '/news/$1', 'code' => 301, 'pattern' => true ),
				array( 'source' => '/gone', 'target' => '', 'code' => 410 ),
				array( 'source' => '/members', 'target' => '/login', 'code' => 302, 'condition' => true ),
				array( 'source' => '/off', 'target' => '/on', 'code' => 301, 'enabled' => false ),
				array( 'source' => '/ok', 'target' => '/other', 'code' => 302 ),
				array( 'source' => '/no-target', 'target' => '', 'code' => 301 ),
				array( 'source' => '/mail', 'target' => 'mailto:hi@example.test', 'code' => 301 ),
				array( 'source' => '/space', 'target' => 'https://example.com/a b', 'code' => 301 ),
			)
		);

		$this->assertCount( 1, $out['rows'] );
		$this->assertSame(
			array(
				Converter::PATTERN   => 1,
				Converter::TYPE      => 1,
				Converter::CONDITION => 1,
				Converter::DISABLED  => 1,
				Converter::DUPLICATE => 1,
				Converter::INVALID   => 3,
			),
			$out['skipped']
		);
	}

	public function test_a_source_listed_by_two_plugins_is_kept_once(): void {
		$converter = new Converter( 'https://www.example.test' );
		$first     = $converter->convert( array( array( 'source' => '/a', 'target' => '/b', 'code' => 301 ) ), 'one' );
		$second    = $converter->convert( array( array( 'source' => 'a', 'target' => '/c', 'code' => 302 ) ), 'two' );

		$this->assertCount( 1, $first['rows'] );
		$this->assertSame( array(), $second['rows'] );
		$this->assertSame( array( Converter::DUPLICATE => 1 ), $second['skipped'] );
	}

	public function test_the_file_has_the_import_columns_and_quotes_what_needs_it(): void {
		$csv = Converter::csv(
			array(
				array( 'www.example.test/a', 'https://www.example.test/b?x=1,2', '301', 'Imported from "Test"' ),
			)
		);

		$this->assertSame( "source_url,destination,type,note\nwww.example.test/a,\"https://www.example.test/b?x=1,2\",301,\"Imported from \"\"Test\"\"\"\n", $csv );
		$parsed = array_map( 'str_getcsv', explode( "\n", trim( $csv ) ) );
		$this->assertSame( array( 'www.example.test/a', 'https://www.example.test/b?x=1,2', '301', 'Imported from "Test"' ), $parsed[1] );
	}
}

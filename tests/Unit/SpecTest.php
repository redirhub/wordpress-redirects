<?php
/**
 * The golden sample of format v1 (spec/v1, a copy of redirhub/integration-spec).
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedirHub\Redirects\Core\Answer;
use RedirHub\Redirects\Core\Pages;
use RedirHub\Redirects\Core\Parser;
use RedirHub\Redirects\Core\Request;
use RedirHub\Redirects\Core\Router;

final class SpecTest extends TestCase {

	/** Every request of the sample comes at 31/Oct/2026:19:10:00 +0000. */
	const TIME = 1793473800;

	private static function spec( string $file ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/spec/v1/' . $file );
	}

	private static function router(): Router {
		return new Router( Parser::parse( self::spec( 'rules.txt' ), '"spec-v1"' ) );
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public static function requests(): array {
		$lines  = array_values( array_filter( explode( "\n", self::spec( 'requests.tsv' ) ) ) );
		$header = explode( "\t", array_shift( $lines ) );
		$cases  = array();
		foreach ( $lines as $line ) {
			$row                   = array_combine( $header, explode( "\t", $line ) );
			$cases[ $row['name'] ] = array( $row );
		}

		return $cases;
	}

	public function test_the_time_is_the_sample_time(): void {
		$this->assertSame( '31/Oct/2026:19:10:00', gmdate( 'd/M/Y:H:i:s', self::TIME ) );
	}

	/**
	 * @dataProvider requests
	 * @param array<string, string> $row One line of requests.tsv.
	 */
	public function test_request( array $row ): void {
		$dash    = static function ( string $value ): ?string {
			return '-' === $value ? null : $value;
		};
		$request = Request::from_url(
			$row['method'],
			$row['url'],
			array(
				'ip'         => '203.0.113.9',
				'referer'    => $dash( $row['referer'] ),
				'user_agent' => $dash( $row['user_agent'] ),
			)
		);
		$router  = self::router();
		$answer  = $router->before( $request );
		$site    = (int) $row['site'];
		$report  = null;

		if ( null === $answer && 404 === $site ) {
			$answer = $router->after_404( $request );
			if ( null === $answer && $router->reports_404( $request ) ) {
				$report = $router->report_line( $request, null, self::TIME );
			}
		}

		if ( null === $answer ) {
			$outcome  = 'site';
			$status   = $site;
			$location = '-';
			$body     = '-';
		} elseif ( $answer->is_check() ) {
			$outcome  = 'check';
			$status   = 200;
			$location = '-';
			$body     = $answer->body;
		} else {
			$outcome  = $answer->rule->is_frame() ? 'frame' : 'redirect';
			$status   = $answer->rule->answer_status();
			$location = $answer->rule->is_frame() ? '-' : $answer->location;
			$body     = $answer->rule->is_frame() ? 'src=' . $answer->location : '-';
			$report   = $router->report_line( $request, $answer, self::TIME );
		}

		$this->assertSame( $row['outcome'], $outcome, 'outcome' );
		$this->assertSame( (int) $row['status'], $status, 'status' );
		$this->assertSame( $row['location'], $location, 'location' );
		$this->assertSame( $row['body'], $body, 'body' );

		$reports = explode( "\n", rtrim( self::spec( 'reports.txt' ), "\n" ) );
		$this->assertSame( '-' === $row['report'] ? null : $reports[ (int) $row['report'] - 1 ], $report, 'report line' );
	}

	public function test_frame_page_holds_one_iframe_with_the_escaped_src(): void {
		$page = Pages::frame( 'https://tour.example.test/?v=1&a="x"', 'https://tour.example.test/?v=1' );

		$this->assertSame( 1, substr_count( $page, '<iframe' ) );
		$this->assertStringContainsString( '<iframe src="https://tour.example.test/?v=1&amp;a=&quot;x&quot;"', $page );
		$this->assertStringContainsString( '<title>tour.example.test</title>', $page );
	}

	public function test_directives(): void {
		$rules = self::router()->rules();

		$this->assertSame( 'spec-v1', $rules->etag() );
		$this->assertSame( 60, $rules->refresh() );
		$this->assertSame( 'https://edge.example.test/_edge/hits', $rules->hits_url() );
		$this->assertSame( '/_redirhub/check', $rules->check_path() );
		$this->assertSame( 'RedirHub (integrated)', $rules->powered_by() );
		$this->assertSame( array( 'promo.example.test', 'shop.example.test' ), $rules->hostnames() );
		$this->assertSame( 8, $rules->count(), 'beta is skipped (unknown flag)' );
	}
}

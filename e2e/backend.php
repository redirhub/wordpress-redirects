<?php
/**
 * The plugin against the real RedirHub backend (a checkout of redirhub/backend with its local
 * development database, run in-process): the domain is added in integration mode and given
 * redirects through the public REST API as a customer would, the backend publishes its rules file
 * to an S3 bucket, and its own IntegrationHealth checks the WordPress site over HTTPS.
 *
 * Usage: php e2e/backend.php <backend checkout> setup|check|change
 *   setup   adds blog.example.test with two redirects; prints the rules address and key (JSON)
 *   check   runs the backend's check of the hostname; prints the host resource (JSON)
 *   change  changes a redirect's destination and adds one; prints the host resource (JSON)
 */

use App\Eloquents\Host;
use App\Eloquents\Organization;
use App\Eloquents\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Modules\Host\Services\Integration\IntegrationHealth;
use Modules\Host\Services\Integration\IntegrationRulesPublisher;

[$script, $backend, $phase] = $argv + array( '', '', 'setup' );
require $backend . '/vendor/autoload.php';
$app = require $backend . '/bootstrap/app.php';
$app->make( Illuminate\Contracts\Console\Kernel::class )->bootstrap();

const HOSTNAME = 'blog.example.test';

$user = User::query()->where( 'email', 'demo@redirhub.com' )->firstOrFail();
$org  = Organization::query()->where( 'name', 'Northwind Studio' )->firstOrFail();

/** A REST call as the demo user, through the app's HTTP kernel. */
function api( string $method, string $path, array $data = array() ): array {
	global $app, $user, $org;
	static $token;
	$token ??= $user->createToken( 'wordpress-redirects e2e' )->plainTextToken;
	$request  = Request::create(
		'https://api.redirhub.test/' . $path,
		$method,
		array(),
		array(),
		array(),
		array(
			'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
			'HTTP_ACCEPT'        => 'application/json',
			'HTTP_WORKSPACE'     => $org->uuid,
			'CONTENT_TYPE'       => 'application/json',
		),
		array() === $data ? null : json_encode( $data )
	);
	$response = $app->make( HttpKernel::class )->handle( $request );
	$body     = json_decode( (string) $response->getContent(), true );
	if ( $response->getStatusCode() >= 300 ) {
		fwrite( STDERR, "{$method} {$path}: {$response->getStatusCode()} {$response->getContent()}\n" );
		exit( 1 );
	}

	return $body['data'] ?? $body ?? array();
}

/**
 * Writes the hostname's rules file as the queued PublishIntegrationRules job does once the
 * scheduler's edge publisher has drained the outbox.
 */
function publish(): void {
	app( IntegrationRulesPublisher::class )->publish( HOSTNAME, true );
}

switch ( $phase ) {
	case 'setup':
		$s3 = new S3Client(
			array(
				'endpoint'                => config( 'filesystems.disks.integration_rules.endpoint' ),
				'region'                  => 'us-east-1',
				'version'                 => 'latest',
				'use_path_style_endpoint' => true,
				'credentials'             => array( 'key' => 'any', 'secret' => 'any' ),
			)
		);
		foreach ( array( config( 'filesystems.disks.integration_rules.bucket' ), (string) env( 'EDGE_SYNC_S3_BUCKET' ) ) as $bucket ) {
			try {
				$s3->createBucket( array( 'Bucket' => $bucket ) );
			} catch ( Throwable $e ) {
				// Exists already.
			}
		}
		// A rerun starts from a clean domain.
		App\Eloquents\Record::query()->where( 'host', HOSTNAME )->forceDelete();
		Host::query()->where( 'host', HOSTNAME )->get()->each( fn ( Host $host ) => api( 'DELETE', 'v1/host/' . HOSTNAME ) );

		api( 'POST', 'v1/host', array( 'host' => HOSTNAME, 'connection_mode' => 'integration' ) );
		$key = api( 'POST', 'v1/workspace/integration-key' );
		api( 'POST', 'v1/redirect', array( 'url' => HOSTNAME . '/old-post', 'destination' => 'https://www.example.com/new-post', 'type' => '301' ) );
		api( 'POST', 'v1/redirect', array( 'url' => HOSTNAME . '/spring-sale', 'destination' => 'https://shop.example.com/sale?src=blog', 'type' => '302', 'forward_query' => true ) );
		publish();
		$host = api( 'GET', 'v1/host/' . HOSTNAME );
		echo json_encode( array( 'key' => $key['key'] ?? $key['integration_key'] ?? $key, 'host' => $host ) );
		break;

	case 'check':
		// The backend never checks a hostname that resolves to a private or loopback address (SSRF
		// protection); here the site runs on this machine, so this one hostname is let through.
		app()->instance(
			Modules\Host\Services\DomainConnect\EndpointValidator::class,
			new class() extends Modules\Host\Services\DomainConnect\EndpointValidator {
				public function isSafe( string $url ): bool {
					return parse_url( $url, PHP_URL_HOST ) === HOSTNAME || parent::isSafe( $url );
				}
			}
		);
		$host = Host::query()->where( 'host', HOSTNAME )->firstOrFail();
		app( IntegrationHealth::class )->check( $host );
		echo json_encode( api( 'GET', 'v1/host/' . HOSTNAME ) );
		break;

	case 'change':
		$links = api( 'GET', 'v1/redirect?search=old-post' );
		$link  = collect( $links['data'] ?? $links )->firstWhere( 'url', HOSTNAME . '/old-post' );
		api( 'PUT', 'v1/redirect/' . $link['id'], array( 'destination' => 'https://www.example.com/newer-post', 'type' => '308' ) );
		api( 'POST', 'v1/redirect', array( 'url' => HOSTNAME . '/launch', 'destination' => 'https://launch.example.com/', 'type' => '307' ) );
		publish();
		echo json_encode( api( 'GET', 'v1/host/' . HOSTNAME ) );
		break;
}

<?php
/**
 * A visitor's request, as the integration sees it.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The facts of a request the format cares about. Built from `$_SERVER` by the plugin and from
 * spec/v1/requests.tsv by the tests.
 */
final class Request {

	/**
	 * `GET`, `HEAD`, ...
	 *
	 * @var string
	 */
	public $method;

	/**
	 * The `Host` header as sent: any letter case, maybe a port.
	 *
	 * @var string
	 */
	public $host;

	/**
	 * The path as sent: percent-encoded or raw bytes.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * The query string as sent, without `?`.
	 *
	 * @var string
	 */
	public $query;

	/**
	 * `http` or `https`, as the visitor asked.
	 *
	 * @var string
	 */
	public $scheme;

	/**
	 * The visitor's address from a value the platform sets; null when unknown.
	 *
	 * @var string|null
	 */
	public $ip;

	/**
	 * The Referer header, or null.
	 *
	 * @var string|null
	 */
	public $referer;

	/**
	 * The User-Agent header, or null.
	 *
	 * @var string|null
	 */
	public $user_agent;

	/**
	 * `HTTP/1.1`, `HTTP/2.0`, ...
	 *
	 * @var string
	 */
	public $protocol;

	/**
	 * Builds a request.
	 *
	 * @param array{method: string, host: string, path: string, query?: string, scheme?: string, ip?: ?string, referer?: ?string, user_agent?: ?string, protocol?: string} $fields The request's fields.
	 */
	public function __construct( array $fields ) {
		$this->method     = strtoupper( $fields['method'] );
		$this->host       = $fields['host'];
		$this->path       = '' === $fields['path'] ? '/' : $fields['path'];
		$this->query      = $fields['query'] ?? '';
		$this->scheme     = $fields['scheme'] ?? 'https';
		$this->ip         = $fields['ip'] ?? null;
		$this->referer    = $fields['referer'] ?? null;
		$this->user_agent = $fields['user_agent'] ?? null;
		$this->protocol   = $fields['protocol'] ?? 'HTTP/1.1';
	}

	/**
	 * A request from an absolute URL (`https://Host:port/path?query`), for the tests.
	 *
	 * @param string               $method The method.
	 * @param string               $url    The absolute URL.
	 * @param array<string, mixed> $extra  Further fields (ip, referer, user_agent).
	 */
	public static function from_url( string $method, string $url, array $extra = array() ): self {
		preg_match( '#^(https?)://([^/?]*)([^?]*)(?:\?(.*))?$#', $url, $parts );

		return new self(
			array(
				'method' => $method,
				'scheme' => strtolower( $parts[1] ),
				'host'   => $parts[2],
				'path'   => $parts[3],
				'query'  => $parts[4] ?? '',
			) + $extra
		);
	}

	/** Whether the format answers this method at all: GET and HEAD only. */
	public function is_answerable(): bool {
		return 'GET' === $this->method || 'HEAD' === $this->method;
	}
}

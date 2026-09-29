<?php
/**
 * Browser-like request headers for live-site fetches.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Http;

final class Browser_Headers {

	public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/**
	 * @return array<string, string>
	 */
	public static function html(): array {
		return array(
			'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language'           => 'en-US,en;q=0.9',
			'User-Agent'                => self::USER_AGENT,
			'Upgrade-Insecure-Requests' => '1',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function css(): array {
		return array(
			'Accept'          => 'text/css,*/*;q=0.1',
			'Accept-Language' => 'en-US,en;q=0.9',
			'User-Agent'      => self::USER_AGENT,
		);
	}
}

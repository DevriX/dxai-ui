<?php
/**
 * Request headers for live-site fetches.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Http;

/**
 * The headers the page builder sends to a client's old site: the Accept
 * headers a browser sends, so the site answers with the same HTML it serves
 * people, and a User-Agent that says what is asking — in the `compatible`
 * form crawlers use, with the plugin's name for robots.txt groups (`dxai-ui`,
 * Crawl_Politeness). It does not pretend to be a browser.
 */
final class Browser_Headers {

	public const USER_AGENT = 'Mozilla/5.0 (compatible; DXAI-UI/1.0; +https://devrix.com/dxai-ui)';

	/**
	 * @return array<string, string>
	 */
	public static function html(): array {
		return array(
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
			'User-Agent'      => self::USER_AGENT,
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

<?php
/**
 * Normalized conversion source.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

final class Source_Document {

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, array{path:string, url:string}> $assets
	 * @param array<int, string> $logs
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $title,
		public readonly array $payload,
		public readonly array $assets = array(),
		public readonly array $logs = array(),
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'kind'    => $this->kind,
			'title'   => $this->title,
			'payload' => $this->payload,
			'assets'  => $this->assets,
			'logs'    => $this->logs,
		);
	}

	public function to_prompt_blob(): string {
		$encoded = wp_json_encode( $this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}
}

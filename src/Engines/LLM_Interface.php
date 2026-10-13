<?php
/**
 * LLM provider contract.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

interface LLM_Provider_Interface {

	public function get_id(): string;

	public function get_label(): string;

	public function test_connection(): bool|\WP_Error;

	/**
	 * @param array<string, mixed> $args
	 * @return array{block_title:string, gutenberg_markup:string, custom_css:string, required_media:array<int, string>}|\WP_Error
	 */
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error;

	/**
	 * The provider's plain reply to a prompt (the copy, the planner and the critic ask for text, not blocks); a provider that
	 * cannot give one returns a WP_Error (Abstract_Engine does).
	 *
	 * @param array<string, mixed> $args max_tokens.
	 */
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error;
}

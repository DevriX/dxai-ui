<?php
/**
 * Selects the active LLM engine.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Engines;

use DXAI_UI\Settings\Options;

final class Engine_Factory {

	/**
	 * Legacy brief labels → current request IDs.
	 *
	 * @var array<string, string>
	 */
	private const MODEL_ALIASES = array(
		'claude-3.5-sonnet'  => 'claude-sonnet-5',
		'claude-3.7-sonnet'  => 'claude-sonnet-5',
		'claude-3-5-sonnet'  => 'claude-sonnet-5',
		'claude-3-7-sonnet'  => 'claude-sonnet-5',
		'gpt-4o'             => 'gpt-4o',
		'gpt-4o-mini'        => 'gpt-4o-mini',
		'grok-3'             => 'grok-4.6',
		'grok-beta'          => 'grok-4.6',
		'deepseek-r1'        => 'deepseek-v4-pro',
		'deepseek-v3'        => 'deepseek-v4-pro',
		'deepseek-chat'      => 'deepseek-chat',
	);

	public static function make( ?string $engine = null ): LLM_Provider_Interface|\WP_Error {
		$settings = Options::get();
		$engine   = $engine ?: (string) $settings['active_engine'];

		return match ( $engine ) {
			'claude'   => new Claude_Engine( Options::get_secret( 'anthropic_api_key' ), self::resolve_model( (string) $settings['anthropic_model'] ) ),
			'openai'   => new OpenAI_Engine( Options::get_secret( 'openai_api_key' ), self::resolve_model( (string) $settings['openai_model'] ) ),
			'grok'     => new Grok_Engine( Options::get_secret( 'xai_api_key' ), self::resolve_model( (string) $settings['grok_model'] ) ),
			'deepseek' => new DeepSeek_Engine( Options::get_secret( 'deepseek_api_key' ), self::resolve_model( (string) $settings['deepseek_model'] ) ),
			default    => new \WP_Error( 'dxai_ui_unknown_engine', __( 'Unknown AI engine.', 'dxai-ui' ), array( 'status' => 400 ) ),
		};
	}

	public static function resolve_model( string $model ): string {
		$model = sanitize_text_field( $model );

		return self::MODEL_ALIASES[ $model ] ?? $model;
	}

	/**
	 * @return array<int, array{id:string, label:string, models:array<int, array{id:string, label:string}>}>
	 */
	public static function catalog(): array {
		return array(
			array(
				'id'     => 'claude',
				'label'  => 'Anthropic Claude',
				'models' => array(
					array( 'id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5 (recommended)' ),
					array( 'id' => 'claude-opus-5', 'label' => 'Claude Opus 5' ),
					array( 'id' => 'claude-3.7-sonnet', 'label' => 'Claude 3.7 Sonnet (alias)' ),
					array( 'id' => 'claude-3.5-sonnet', 'label' => 'Claude 3.5 Sonnet (alias)' ),
				),
			),
			array(
				'id'     => 'openai',
				'label'  => 'OpenAI',
				'models' => array(
					array( 'id' => 'gpt-5.6', 'label' => 'GPT-5.6 (recommended)' ),
					array( 'id' => 'gpt-4o', 'label' => 'GPT-4o' ),
					array( 'id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini' ),
				),
			),
			array(
				'id'     => 'grok',
				'label'  => 'xAI Grok',
				'models' => array(
					array( 'id' => 'grok-4.6', 'label' => 'Grok 4.6 (recommended)' ),
					array( 'id' => 'grok-3', 'label' => 'Grok 3 (alias)' ),
					array( 'id' => 'grok-beta', 'label' => 'Grok Beta (alias)' ),
				),
			),
			array(
				'id'     => 'deepseek',
				'label'  => 'DeepSeek',
				'models' => array(
					array( 'id' => 'deepseek-v4-pro', 'label' => 'DeepSeek V4 Pro (recommended)' ),
					array( 'id' => 'deepseek-v4-flash', 'label' => 'DeepSeek V4 Flash' ),
					array( 'id' => 'deepseek-r1', 'label' => 'DeepSeek R1 (alias)' ),
					array( 'id' => 'deepseek-v3', 'label' => 'DeepSeek V3 (alias)' ),
				),
			),
		);
	}
}

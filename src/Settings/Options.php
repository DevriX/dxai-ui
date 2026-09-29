<?php
/**
 * Plugin options.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Settings;

use DXAI_UI\Compiler\Rewrite_Policy;
use DXAI_UI\Security\Secret_Store;

final class Options {

	public const OPTION_KEY = 'dxai_ui_settings';

	private const SECRET_FIELDS = array(
		'anthropic_api_key',
		'openai_api_key',
		'xai_api_key',
		'deepseek_api_key',
		'figma_api_token',
		'lovable_api_key',
	);

	public function register(): void {
		add_filter( 'dxai_ui_get_settings', array( $this, 'get' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'active_engine'     => 'claude',
			'anthropic_model'   => 'claude-sonnet-5',
			'openai_model'      => 'gpt-5.6',
			'grok_model'        => 'grok-4.6',
			'deepseek_model'    => 'deepseek-v4-pro',
			'anthropic_api_key' => '',
			'openai_api_key'    => '',
			'xai_api_key'       => '',
			'deepseek_api_key'  => '',
			'figma_api_token'   => '',
			'lovable_api_key'   => '',
			// Per-structure handling: `copy` reproduces the design, `dynamic`
			// swaps in the WordPress-native block. Fidelity is the default so
			// an import looks like what the designer built.
			'structure_mode'    => array(
				'blog'       => Rewrite_Policy::COPY,
				'form'       => Rewrite_Policy::COPY,
				'slider'     => Rewrite_Policy::COPY,
				'navigation' => Rewrite_Policy::COPY,
			),
		);
	}

	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::defaults(), '', false );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		foreach ( self::SECRET_FIELDS as $field ) {
			if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
				$settings[ $field ] = Secret_Store::decrypt( $settings[ $field ] );
			}
		}

		return $settings;
	}

	/**
	 * Public payload for the admin SPA (secrets masked).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_public(): array {
		$settings = self::get();
		foreach ( self::SECRET_FIELDS as $field ) {
			$raw                                = (string) ( $settings[ $field ] ?? '' );
			$settings[ $field ]                 = '';
			$settings[ $field . '_masked' ]     = Secret_Store::mask( $raw );
			$settings[ $field . '_configured' ] = $raw !== '';
		}

		$settings['has_llm']   = ! empty( $settings['anthropic_api_key_configured'] )
			|| ! empty( $settings['openai_api_key_configured'] )
			|| ! empty( $settings['xai_api_key_configured'] )
			|| ! empty( $settings['deepseek_api_key_configured'] );
		$settings['has_figma'] = ! empty( $settings['figma_api_token_configured'] );

		return $settings;
	}

	public static function get_secret( string $field ): string {
		if ( ! in_array( $field, self::SECRET_FIELDS, true ) ) {
			return '';
		}

		$settings = self::get();

		return is_string( $settings[ $field ] ?? null ) ? $settings[ $field ] : '';
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function save( array $input ): array {
		$current = self::get();
		$next    = $current;

		$text_fields = array(
			'active_engine',
			'anthropic_model',
			'openai_model',
			'grok_model',
			'deepseek_model',
		);

		foreach ( $text_fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$next[ $field ] = sanitize_text_field( (string) $input[ $field ] );
			}
		}

		$allowed_engines = array( 'claude', 'openai', 'grok', 'deepseek' );
		if ( ! in_array( $next['active_engine'], $allowed_engines, true ) ) {
			$next['active_engine'] = 'claude';
		}

		if ( array_key_exists( 'structure_mode', $input ) && is_array( $input['structure_mode'] ) ) {
			$defaults = self::defaults();
			$next['structure_mode'] = array_merge(
				is_array( $defaults['structure_mode'] ) ? $defaults['structure_mode'] : array(),
				Rewrite_Policy::sanitize( $input['structure_mode'] )
			);
		}

		foreach ( self::SECRET_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$value = trim( (string) $input[ $field ] );
			if ( $value === '' ) {
				continue;
			}
			$next[ $field ] = $value;
		}

		$persisted = $next;
		foreach ( self::SECRET_FIELDS as $field ) {
			$persisted[ $field ] = Secret_Store::encrypt( (string) $next[ $field ] );
		}

		update_option( self::OPTION_KEY, $persisted, false );

		return $next;
	}
}

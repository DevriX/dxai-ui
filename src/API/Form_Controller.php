<?php
/**
 * Public form submission endpoint.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\API;

use DXAI_UI\Content\Content_Types;
use DXAI_UI\Structures\Structure_Repository;
use WP_REST_Request;
use WP_REST_Response;

final class Form_Controller extends \WP_REST_Controller {

	public function __construct() {
		$this->namespace = DXAI_UI_REST_NAMESPACE;
		$this->rest_base = 'forms';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/forms/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'nonce'   => array(
						'type'     => 'string',
						'required' => false,
					),
					\DXAI_UI\Blocks\Form_Block::HONEYPOT => array(
						'type'     => 'string',
						'required' => false,
					),
					'page_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/entries',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'entries' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/forms/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	public function permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	public function submit( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		/*
		 * The trap field (Form_Block::HONEYPOT). "website" is a real field now —
		 * as the trap it swallowed every lead whose form asked for a website.
		 * A form rendered before the rename (a page cache still serving it)
		 * sends no new trap, and there "website" is still the trap.
		 */
		$trap     = \DXAI_UI\Blocks\Form_Block::HONEYPOT;
		$legacy   = ! $request->has_param( $trap );
		$honeypot = trim( (string) $request->get_param( $legacy ? 'website' : $trap ) );
		if ( $honeypot !== '' ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$limited = $this->rate_limit( $request );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		/*
		 * No nonce check. A public contact form has no privileged action for a
		 * nonce to guard, and on a real site the nonce broke it: a page cache
		 * (LiteSpeed, Varnish, Cloudflare) serves the rendered form, token
		 * included, long after the token expires, and a logged-in visitor's
		 * token is minted for their user id while this route — called without
		 * the REST nonce header — runs as user 0. Both got 403 "Invalid form
		 * token". Spam is handled by the honeypot and the rate limit above.
		 */

		$params = $request->get_params();
		unset( $params['nonce'], $params[ $trap ] );
		if ( $legacy ) {
			unset( $params['website'] );
		}
		$safe = array();
		foreach ( $params as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' || $key === 'page_id' ) {
				if ( $key === 'page_id' ) {
					$safe['page_id'] = absint( $value );
				}
				continue;
			}
			$safe[ $key ] = is_array( $value )
				? array_map( 'sanitize_text_field', $value )
				: sanitize_textarea_field( (string) $value );
		}

		$title = sprintf(
			/* translators: %s datetime */
			__( 'Form entry %s', 'dxai-ui' ),
			gmdate( 'Y-m-d H:i:s' )
		);

		$id = wp_insert_post(
			array(
				'post_type'    => Content_Types::FORM_ENTRY,
				'post_status'  => 'publish',
				'post_title'   => $title,
				/*
				 * Slashed, because wp_insert_post() unslashes: unslashed JSON
				 * lost every backslash, so every non-ASCII character, newline
				 * and quote was destroyed and the entry never decoded again.
				 * The HEX flags leave nothing in it for KSES — which runs here,
				 * the visitor being user 0 — to treat as markup.
				 */
				'post_content' => wp_slash( wp_json_encode( $safe, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ?: '' ),
				'meta_input'   => array(
					'_dxai_ui_page_id' => $safe['page_id'] ?? 0,
				),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$admin = get_option( 'admin_email' );
		if ( is_string( $admin ) && is_email( $admin ) ) {
			wp_mail(
				$admin,
				sprintf( '[DX UI] %s', $title ),
				wp_json_encode( $safe, JSON_PRETTY_PRINT ) ?: ''
			);
		}

		return rest_ensure_response( array( 'ok' => true, 'id' => (int) $id ) );
	}

	public function entries(): WP_REST_Response {
		$repo = new Structure_Repository();
		$lib  = $repo->library();

		return rest_ensure_response(
			array(
				'entries' => $lib['form_entries'] ?? array(),
			)
		);
	}

	public function export(): WP_REST_Response {
		$repo    = new Structure_Repository();
		$lib     = $repo->library();
		$entries = is_array( $lib['form_entries'] ?? null ) ? $lib['form_entries'] : array();

		$fh = fopen( 'php://temp', 'r+' );
		if ( false === $fh ) {
			return rest_ensure_response( array( 'csv' => '' ) );
		}

		fputcsv( $fh, array( 'id', 'date', 'page_id', 'email', 'message', 'fields' ) );
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			fputcsv(
				$fh,
				array(
					(string) ( $entry['id'] ?? '' ),
					(string) ( $entry['date'] ?? '' ),
					(string) ( $entry['page_id'] ?? '' ),
					(string) ( $entry['email'] ?? '' ),
					(string) ( $entry['message'] ?? '' ),
					wp_json_encode( $entry['fields'] ?? array() ) ?: '',
				)
			);
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return rest_ensure_response(
			array(
				'filename' => 'dxai-form-entries.csv',
				'csv'      => is_string( $csv ) ? $csv : '',
			)
		);
	}

	private function rate_limit( WP_REST_Request $request ): true|\WP_Error {
		/*
		 * The connecting address. X-Forwarded-For is only believed when the
		 * connection comes from a proxy the site lists as trusted (the
		 * dxai_ui_trusted_proxies filter): anyone can send the header, so
		 * trusting it by default let a bot pick a fresh address per request
		 * and never reach the limit.
		 */
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$proxies = (array) apply_filters( 'dxai_ui_trusted_proxies', array() );
		$fwd     = $request->get_header( 'x-forwarded-for' );
		if ( is_string( $fwd ) && $fwd !== '' && in_array( $ip, $proxies, true ) ) {
			$ip = sanitize_text_field( trim( explode( ',', $fwd )[0] ) );
		}

		$key   = 'dxai_ui_form_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 8 ) {
			return new \WP_Error(
				'dxai_ui_form_rate',
				__( 'Too many form submissions. Please wait a few minutes.', 'dxai-ui' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}
}

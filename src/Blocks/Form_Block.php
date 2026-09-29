<?php
/**
 * Dynamic contact/newsletter form — submissions go to dxai_form_entry.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Blocks;

final class Form_Block {

	/**
	 * The hidden field only a bot fills in (Form_Controller::submit()). It was
	 * "website", and a design's own Website field — common on a quote form —
	 * came after it with the same name: the visitor's answer filled the trap,
	 * and every such lead was thanked and thrown away.
	 */
	public const HONEYPOT = '_dxai_hp';

	/** Names the form sends for itself; a design field with one of them is renamed. */
	private const RESERVED = array( 'nonce', 'page_id', self::HONEYPOT );

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		register_block_type(
			'dxai-ui/form',
			array(
				'api_version'     => 3,
				'editor_script'   => 'dxai-ui-blocks-editor',
				'view_script'     => 'dxai-ui-form-view',
				'style'           => 'dxai-ui-dynamic',
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'fields' => array(
						'type'    => 'array',
						'default' => array(),
					),
					'submitLabel' => array(
						'type'    => 'string',
						'default' => 'Send',
					),
					'successMessage' => array(
						'type'    => 'string',
						'default' => 'Thank you.',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		unset( $content );
		$fields  = is_array( $attributes['fields'] ?? null ) ? $attributes['fields'] : array();
		$label   = sanitize_text_field( (string) ( $attributes['submitLabel'] ?? __( 'Send', 'dxai-ui' ) ) );
		$class   = preg_replace( '/[^a-zA-Z0-9_\-:\/\[\]\s]/', '', (string) ( $attributes['className'] ?? '' ) ) ?? '';
		$success = sanitize_text_field( (string) ( $attributes['successMessage'] ?? __( 'Thank you.', 'dxai-ui' ) ) );
		$id      = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : get_the_ID();
		$nonce   = wp_create_nonce( 'dxai_ui_form' );
		$action  = esc_url_raw( rest_url( DXAI_UI_REST_NAMESPACE . '/forms/submit' ) );

		if ( $fields === array() ) {
			$fields = array(
				array(
					'name'     => 'name',
					'type'     => 'text',
					'label'    => __( 'Name', 'dxai-ui' ),
					'required' => true,
				),
				array(
					'name'     => 'email',
					'type'     => 'email',
					'label'    => __( 'Email', 'dxai-ui' ),
					'required' => true,
				),
				array(
					'name'     => 'phone',
					'type'     => 'tel',
					'label'    => __( 'Phone', 'dxai-ui' ),
					'required' => false,
				),
				array(
					'name'     => 'message',
					'type'     => 'textarea',
					'label'    => __( 'Message', 'dxai-ui' ),
					'required' => true,
				),
			);
		}

		if ( $label === '' || strcasecmp( $label, 'Send' ) === 0 ) {
			$label = __( 'Send message', 'dxai-ui' );
		}

		wp_enqueue_style( 'dxai-ui-dynamic' );

		$html  = '<form class="dxai-form ' . esc_attr( $class ) . '" method="post" action="' . esc_url( $action ) . '" data-dxai-form="1" data-success="' . esc_attr( $success ) . '">';
		$html .= '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';
		$html .= '<input type="hidden" name="page_id" value="' . esc_attr( (string) $id ) . '" />';
		$html .= '<input type="text" name="' . esc_attr( self::HONEYPOT ) . '" value="" class="dxai-form-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />';

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name     = sanitize_key( (string) ( $field['name'] ?? '' ) );
			$type     = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			$field_label = sanitize_text_field( (string) ( $field['label'] ?? $name ) );
			$required = ! empty( $field['required'] ) ? ' required' : '';
			if ( $name === '' ) {
				continue;
			}
			// A field named like one of the form's own would be dropped or overwritten on submit.
			if ( in_array( $name, self::RESERVED, true ) ) {
				$name .= '_field';
			}
			$html .= '<label class="dxai-form-field">';
			$html .= '<span>' . esc_html( $field_label ) . '</span>';
			if ( 'textarea' === $type ) {
				$html .= '<textarea name="' . esc_attr( $name ) . '"' . $required . '></textarea>';
			} else {
				$input_type = in_array( $type, array( 'email', 'tel', 'url', 'number', 'text' ), true ) ? $type : 'text';
				$html      .= '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '"' . $required . ' />';
			}
			$html .= '</label>';
		}

		$html .= '<button type="submit" class="dxai-form-submit">' . esc_html( $label ) . '</button>';
		$html .= '<p class="dxai-form-status" hidden></p>';
		$html .= '</form>';

		wp_enqueue_script( 'dxai-ui-form-view' );

		return $html;
	}
}

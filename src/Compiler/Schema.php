<?php
/**
 * Shared JSON schema for LLM structured output.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Schema {

	/**
	 * @return array<string, mixed>
	 */
	public static function generation(): array {
		$menu_item = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'label', 'url' ),
			'properties'           => array(
				'label' => array( 'type' => 'string' ),
				'url'   => array( 'type' => 'string' ),
			),
		);

		$form_field = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'type', 'label', 'required' ),
			'properties'           => array(
				'name'     => array( 'type' => 'string' ),
				'type'     => array( 'type' => 'string' ),
				'label'    => array( 'type' => 'string' ),
				'required' => array( 'type' => 'boolean' ),
			),
		);

		$structure = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'type', 'title', 'gutenberg_markup', 'menu_items', 'form_fields' ),
			'properties'           => array(
				'type'             => array( 'type' => 'string' ),
				'title'            => array( 'type' => 'string' ),
				'gutenberg_markup' => array( 'type' => 'string' ),
				'menu_items'       => array(
					'type'  => 'array',
					'items' => $menu_item,
				),
				'form_fields'      => array(
					'type'  => 'array',
					'items' => $form_field,
				),
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'block_title', 'gutenberg_markup', 'structures', 'custom_css', 'custom_js', 'required_media' ),
			'properties'           => array(
				'block_title'      => array( 'type' => 'string' ),
				'gutenberg_markup' => array( 'type' => 'string' ),
				'structures'       => array(
					'type'  => 'array',
					'items' => $structure,
				),
				'custom_css'       => array( 'type' => 'string' ),
				'custom_js'        => array( 'type' => 'string' ),
				'required_media'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}
}

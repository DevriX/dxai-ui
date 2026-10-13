<?php
/**
 * A template: a theme, or a kind of theme, and the team's sections made for it.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Theme\Capabilities;

/**
 * The plugin carries one template, American Restoration's: the sections built by hand and the layouts (data/block-library), written in
 * that theme's blocks, classes and colours, for that theme and the DX themes made from it. A site whose theme has no template gets the
 * generic library: the same sections, in core blocks, with the colours this theme's palette has for the same roles (Block_Library::fill
 * translates them as it makes them). The templates a site has may grow (a filter now; uploaded ones later).
 */
final class Template {

	/** The built-in template's slug: the theme it is for. */
	public const BUILT_IN = \DXAI_UI\Theme\Adapters\Amr_Adapter::SLUGS[0];

	/** The generic library's slug. */
	public const GENERIC = 'generic';

	/** The colour slugs the built-in template's sections are written in, and the role each plays. */
	private const BUILT_IN_SLUGS = array(
		'primary-dark'  => 'dark',
		'primary'       => 'main',
		'primary-light' => 'main',
		'accent'        => 'accent',
		'secondary'     => 'light',
		'border'        => 'light',
		'on-accent'     => 'on_dark',
		'black'         => 'dark',
		'white'         => 'light',
	);

	/**
	 * The templates the site has, by slug: `label`, `themes` (theme folder names), `kinds` (`dx`: any DX theme), `dir` (where its
	 * library is), `generic`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		// The libraries a site keeps in its uploads first: one made for a theme is taken before the built-in one for it.
		$all = Library_Store::all() + array(
			self::BUILT_IN => array(
				'slug'    => self::BUILT_IN,
				'label'   => 'American Restoration',
				'themes'  => Block_Library::themes(),
				'kinds'   => array( 'dx' ),
				'extends' => '',
				'dir'     => DXAI_UI_DIR . Block_Library::DIR,
				'generic' => false,
				'stored'  => false,
			) + Block_Library::layouts_in( DXAI_UI_DIR . Block_Library::DIR ),
		);

		/**
		 * The templates a site has.
		 *
		 * @param array<string, array<string, mixed>> $all By slug.
		 */
		return (array) apply_filters( 'dxai_ui_templates', $all );
	}

	/**
	 * The template made for the active theme (by its folder name, or its kind), or null when none is.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function for_theme(): ?array {
		$names = array( get_stylesheet(), get_template() );
		foreach ( self::all() as $template ) {
			if ( array_intersect( $names, array_map( 'strval', (array) ( $template['themes'] ?? array() ) ) ) !== array() ) {
				return $template;
			}
			if ( in_array( 'dx', (array) ( $template['kinds'] ?? array() ), true ) && Capabilities::is_dx() ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * The generic library: the built-in template's sections, made in core blocks with the theme's own colours.
	 *
	 * @return array<string, mixed>
	 */
	public static function generic(): array {
		return array(
			'slug'    => self::GENERIC,
			'label'   => __( 'The generic library', 'dxai-ui' ),
			'themes'  => array(),
			'kinds'   => array(),
			'dir'     => DXAI_UI_DIR . Block_Library::DIR,
			'generic' => true,
		);
	}

	/**
	 * The template in use: the active theme's, else the generic library. A filter may name one by slug (a test does).
	 *
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		/**
		 * The template to use, by slug ('generic' for the generic library); '' for the active theme's own.
		 *
		 * @param string $slug
		 */
		$slug = (string) apply_filters( 'dxai_ui_template', '' );
		if ( $slug === self::GENERIC ) {
			return self::generic();
		}
		if ( $slug !== '' && isset( self::all()[ $slug ] ) ) {
			return self::all()[ $slug ];
		}

		return self::for_theme() ?? self::generic();
	}

	/** Whether the sections are made in the generic library (no template for this theme). */
	public static function is_generic(): bool {
		return ! empty( self::current()['generic'] );
	}

	/**
	 * The theme's colours by the roles the sections use: `dark` (a band's ground), `main` (the brand's colour), `accent` (a second), `light`
	 * (a light ground), `on_dark` (words on a band), `text` (words on a light ground); read from the theme's palette by what the colours
	 * are (how dark, how saturated) and what they are called. '' for a role a palette with nothing in it cannot fill.
	 *
	 * @return array<string, string> role => slug
	 */
	public static function palette_roles(): array {
		$palette = Capabilities::palette();
		$rows    = array();
		foreach ( $palette as $slug => $hex ) {
			if ( preg_match( '/^#([0-9a-f]{6})$/i', $hex, $m ) !== 1 ) {
				continue;
			}
			$r = hexdec( substr( $m[1], 0, 2 ) );
			$g = hexdec( substr( $m[1], 2, 2 ) );
			$b = hexdec( substr( $m[1], 4, 2 ) );
			$max = max( $r, $g, $b );
			$rows[ (string) $slug ] = array(
				'lum' => ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255,
				'sat' => $max > 0 ? ( $max - min( $r, $g, $b ) ) / $max : 0.0,
			);
		}
		$roles = array( 'dark' => '', 'main' => '', 'accent' => '', 'light' => '', 'on_dark' => '', 'text' => '' );
		if ( $rows === array() ) {
			return $roles;
		}
		$pick = static function ( callable $score, array $not = array() ) use ( $rows ): string {
			$best  = '';
			$score_best = -INF;
			foreach ( $rows as $slug => $row ) {
				if ( in_array( $slug, $not, true ) ) {
					continue;
				}
				$s = $score( $slug, $row );
				if ( $s > $score_best ) {
					$score_best = $s;
					$best       = $slug;
				}
			}

			return $best;
		};
		// The darkest, a name for it counting a little; the lightest the same way.
		$roles['dark']  = $pick( static fn( $slug, $row ) => ( 1 - $row['lum'] ) + ( preg_match( '/contrast|ink|dark|black|foreground|primary-dark/', $slug ) === 1 ? 0.15 : 0 ) );
		$roles['light'] = $pick( static fn( $slug, $row ) => $row['lum'] + ( preg_match( '/^(?:base|background|white|light|fog|paper|surface)/', $slug ) === 1 ? 0.15 : 0 ) );
		// The brand's colour: the most saturated of the ones that are neither the dark nor the light ground, a name for it counting a little.
		$roles['main']   = $pick( static fn( $slug, $row ) => $row['sat'] * ( $row['lum'] > 0.08 && $row['lum'] < 0.85 ? 1 : 0.2 ) + ( preg_match( '/^(?:primary|brand|accent-1|accent|main)$/', $slug ) === 1 ? 0.3 : 0 ), array( $roles['dark'], $roles['light'] ) );
		$roles['accent'] = $pick( static fn( $slug, $row ) => $row['sat'] * ( $row['lum'] > 0.08 && $row['lum'] < 0.85 ? 1 : 0.2 ) + ( preg_match( '/accent|secondary|highlight|brand-2|cta/', $slug ) === 1 ? 0.3 : 0 ), array( $roles['dark'], $roles['light'], $roles['main'] ) );
		if ( $roles['accent'] === '' ) {
			$roles['accent'] = $roles['main'];
		}
		$roles['on_dark'] = $roles['light'];
		$roles['text']    = $roles['dark'];

		return $roles;
	}

	/**
	 * The built-in template's colour slugs as this theme's (palette_roles()): what a section's `primary-dark` is written as here. A slug
	 * the theme itself has keeps its name. A role the palette cannot fill is left out: the colour is dropped from the section.
	 *
	 * @return array<string, string> the template's slug => this theme's
	 */
	public static function slug_map(): array {
		$palette = Capabilities::palette();
		$roles   = self::palette_roles();
		$out     = array();
		foreach ( self::BUILT_IN_SLUGS as $slug => $role ) {
			if ( isset( $palette[ $slug ] ) ) {
				$out[ $slug ] = $slug;
			} elseif ( ( $roles[ $role ] ?? '' ) !== '' ) {
				$out[ $slug ] = $roles[ $role ];
			}
		}

		return $out;
	}

	/** @return array<int, string> The built-in template's colour slugs. */
	public static function built_in_slugs(): array {
		return array_keys( self::BUILT_IN_SLUGS );
	}
}

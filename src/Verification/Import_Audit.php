<?php
/**
 * Check a converted page for the defects that conversion actually produces.
 *
 * Every rule here stands for something that shipped silently at least once: an
 * empty `<ol>`, an `<img>` with no src, raw TSX rendered as body copy, a
 * stylesheet with `@utility` left unexpanded, one font family linked twice. All
 * of them rendered a page that looked plausible, which is why none was noticed
 * until a design was measured against its own source.
 *
 * Pure PHP on purpose: this runs inside the request that finishes an import,
 * where there is no browser. Geometry against the design, block validity as the
 * editor computes it, and behaviour on a live page need
 * `bin/verify-import.cjs`; `report()` says so rather than implying the page is
 * fully verified.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Verification;

final class Import_Audit {

	public const META = '_dxai_ui_audit';

	/** Source-code shapes that must never reach rendered copy. */
	private const LEAKED_SOURCE = '/(?:const\s+\w+\s*[:=]|=>\s*\{|\breturn\s*\(\s*<|\bimport\s+\w+\s+from\b|useState\(|className=\{)/';

	/**
	 * Audit one generated page and everything it pulls in.
	 *
	 * @return array{ok:bool, checked:int, findings:array<int, array{check:string, detail:string}>, note:string}
	 */
	public function page( int $page_id ): array {
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->result( array( array( 'check' => 'page', 'detail' => 'page ' . $page_id . ' does not exist' ) ), 0 );
		}

		$markup = (string) $post->post_content;
		$parts  = $this->template_parts( $markup );
		$whole  = $markup;
		foreach ( $parts as $slug => $content ) {
			if ( null === $content ) {
				continue;
			}
			$whole .= "\n" . $content;
		}

		$findings = array_merge(
			$this->check_not_empty( $markup ),
			$this->check_blocks( $whole ),
			$this->check_parts( $parts ),
			$this->check_images( $whole ),
			$this->check_leaked_source( $whole ),
			$this->check_stylesheet( $page_id ),
			$this->check_fonts( $page_id )
		);

		/*
		 * Editability: a soft fail when more than 30% of named blocks are raw
		 * HTML islands — enough to flag a conversion that is mostly uneditable,
		 * without failing every page that still has a few opaque chips.
		 * Controls and inline-style notes stay notices only (known gaps).
		 */
		$editability = $this->editability_pressure( $whole );
		$notices     = array_merge(
			$editability['notice'] !== null ? array( $editability['notice'] ) : array(),
			$this->note_controls( $whole ),
			$this->note_inline_styles( $whole )
		);
		if ( $editability['finding'] !== null ) {
			$findings[] = $editability['finding'];
		}

		$out = $this->result( $findings, 8, $notices );
		// Tag histogram from the compile, when the page still carries it.
		$islands = get_post_meta( $page_id, '_dxai_ui_islands', true );
		if ( is_array( $islands ) && $islands !== array() ) {
			$out['islands'] = $islands;
		}

		return $out;
	}

	/**
	 * @param array<int, array{check:string, detail:string}> $findings
	 * @param array<int, array{check:string, detail:string}> $notices
	 * @return array{ok:bool, checked:int, findings:array<int, array{check:string, detail:string}>, notices:array<int, array{check:string, detail:string}>, note:string}
	 */
	private function result( array $findings, int $checked, array $notices = array() ): array {
		return array(
			'ok'       => $findings === array(),
			'checked'  => $checked,
			'findings' => array_values( $findings ),
			'notices'  => array_values( $notices ),
			'note'     => __( 'Geometry, editor block validity and page behaviour are not covered here — run bin/verify-import.cjs for those.', 'dxai-ui' ),
		);
	}

	/**
	 * How much of the page a person can actually edit.
	 *
	 * A `dxai-ui/html` block is a box of markup: no controls, no child blocks,
	 * nothing editable but the HTML itself. Above 30% opaque blocks the audit
	 * soft-fails so the import summary surfaces the gap; below that it stays a
	 * notice (every design still has some islands we have not closed).
	 *
	 * @return array{finding:array{check:string, detail:string}|null, notice:array{check:string, detail:string}|null}
	 */
	private function editability_pressure( string $markup ): array {
		$blocks = $this->flatten( parse_blocks( $markup ) );
		$total  = 0;
		$opaque = 0;

		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === '' ) {
				continue;
			}
			++$total;
			if ( $name === 'dxai-ui/html' || $name === 'core/html' ) {
				++$opaque;
			}
		}

		if ( $total === 0 || $opaque === 0 ) {
			return array( 'finding' => null, 'notice' => null );
		}

		$pct    = (int) round( $opaque / $total * 100 );
		$row    = array(
			'check'  => 'editability',
			'detail' => sprintf(
				/* translators: 1: opaque block count, 2: total blocks, 3: percentage. */
				__( '%1$d of %2$d blocks (%3$d%%) are raw-HTML blocks, which cannot be edited in the editor beyond their markup.', 'dxai-ui' ),
				$opaque,
				$total,
				$pct
			),
		);

		if ( $pct > 30 ) {
			return array( 'finding' => $row, 'notice' => null );
		}

		return array( 'finding' => null, 'notice' => $row );
	}

	/**
	 * @deprecated 0.4.0 Use editability_pressure(); kept for callers that expect notice-only.
	 *
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function note_editability( string $markup ): array {
		$pressure = $this->editability_pressure( $markup );
		$row      = $pressure['finding'] ?? $pressure['notice'];

		return $row !== null ? array( $row ) : array();
	}

	/**
	 * Controls that can be clicked against elements that can change.
	 *
	 * The runtime binds a click to a `dxai-toggle-*` marker and changes a
	 * `dxai-on-*` / `dxai-off-*` target. A trigger with no target is a control
	 * that responds to nothing — measured across the corpus at 390 triggers to
	 * 22 targets, which the browser probe reported as 38 of 158 controls inert.
	 *
	 * `aria-expanded` is counted separately and on purpose: it is right for
	 * assistive technology, and not one of the designs' stylesheets selects on
	 * it, so it must never be mistaken for the visual mechanism.
	 *
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function note_controls( string $markup ): array {
		$triggers = (int) preg_match_all( '/dxai-toggle-[a-z0-9_-]+/i', $markup );
		if ( $triggers === 0 ) {
			return array();
		}

		$targets = (int) preg_match_all( '/dxai-(?:on|off)-[a-z0-9_-]+/i', $markup );
		if ( $targets >= $triggers ) {
			return array();
		}

		return array(
			array(
				'check'  => 'controls',
				'detail' => sprintf(
					/* translators: 1: trigger count, 2: target count. */
					__( '%1$d clickable control(s) but only %2$d element(s) the runtime can change, so some controls will do nothing.', 'dxai-ui' ),
					$triggers,
					$targets
				),
			),
		);
	}

	/**
	 * Inline styles that would not survive a save by a restricted user.
	 *
	 * The import runs as an administrator, who has `unfiltered_html`, so kses
	 * never sees this markup. A contributor opening the page and pressing
	 * Update is a different matter: `safecss_filter_attr()` drops any property
	 * outside core's allow-list — `transition-delay`, `filter`, `animation` —
	 * and any value it will not parse, which includes our `oklch()` and
	 * `color-mix()` colours. Measured over the corpus: 376 of 978 attributes
	 * lose at least one declaration.
	 *
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function note_inline_styles( string $markup ): array {
		if ( ! function_exists( 'safecss_filter_attr' ) ) {
			require_once ABSPATH . WPINC . '/kses.php';
		}

		preg_match_all( '/\sstyle="([^"]*)"/i', $markup, $matches );
		$styles = $matches[1] ?? array();
		if ( $styles === array() ) {
			return array();
		}

		$total = 0;
		$lossy = 0;
		foreach ( $styles as $style ) {
			$raw = html_entity_decode( (string) $style, ENT_QUOTES );
			if ( trim( $raw ) === '' ) {
				continue;
			}
			++$total;
			// Compare the property sets: a reformatted value is fine, a
			// dropped declaration is not.
			if ( self::properties( $raw ) !== self::properties( safecss_filter_attr( $raw ) ) ) {
				++$lossy;
			}
		}

		if ( $total === 0 || $lossy === 0 ) {
			return array();
		}

		return array(
			array(
				'check'  => 'inline-styles',
				'detail' => sprintf(
					/* translators: 1: lossy count, 2: total count. */
					__( '%1$d of %2$d inline styles would lose a declaration if a user without unfiltered_html saved this page.', 'dxai-ui' ),
					$lossy,
					$total
				),
			),
		);
	}

	/**
	 * The property names in a style attribute, for comparing before and after.
	 *
	 * @return array<int, string>
	 */
	private static function properties( string $style ): array {
		$out = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$parts = explode( ':', $declaration, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$name = strtolower( trim( $parts[0] ) );
			if ( $name !== '' ) {
				$out[] = $name;
			}
		}
		sort( $out );

		return $out;
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_not_empty( string $markup ): array {
		$blocks = $this->flatten( parse_blocks( $markup ) );
		if ( count( $blocks ) > 1 ) {
			return array();
		}

		/*
		 * One design imported as a wrapper group and nothing else, because its
		 * `/` route was a redirect stub and the real page lived a directory
		 * down. The page saved, the import reported success, and the result was
		 * 158 bytes.
		 */
		return array(
			array(
				'check'  => 'not-empty',
				'detail' => sprintf(
					/* translators: %d: number of blocks found. */
					__( 'The page holds %d block(s) — the conversion produced nothing to show.', 'dxai-ui' ),
					count( $blocks )
				),
			),
		);
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_blocks( string $markup ): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$unknown  = array();
		$orphans  = 0;

		foreach ( $this->flatten( parse_blocks( $markup ) ) as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === '' ) {
				// A nameless block carrying real markup is a parse failure, not
				// the whitespace between two blocks.
				if ( trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ) !== '' ) {
					++$orphans;
				}
				continue;
			}
			if ( ! $registry->is_registered( $name ) ) {
				$unknown[ $name ] = true;
			}
		}

		$findings = array();
		if ( $unknown !== array() ) {
			$findings[] = array(
				'check'  => 'blocks-registered',
				'detail' => sprintf(
					/* translators: %s: comma-separated block names. */
					__( 'Unregistered block(s): %s. They render as nothing and cannot be edited.', 'dxai-ui' ),
					implode( ', ', array_keys( $unknown ) )
				),
			);
		}
		if ( $orphans > 0 ) {
			$findings[] = array(
				'check'  => 'blocks-parse',
				'detail' => sprintf(
					/* translators: %d: number of fragments. */
					__( '%d markup fragment(s) sit outside any block — the serialized output is malformed.', 'dxai-ui' ),
					$orphans
				),
			);
		}

		return $findings;
	}

	/**
	 * @param array<string, string|null> $parts
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_parts( array $parts ): array {
		$missing = array();
		foreach ( $parts as $slug => $content ) {
			if ( null === $content ) {
				$missing[] = $slug;
			}
		}
		if ( $missing === array() ) {
			return array();
		}

		return array(
			array(
				'check'  => 'template-parts',
				'detail' => sprintf(
					/* translators: %s: comma-separated template part slugs. */
					__( 'The page references template part(s) that do not exist: %s.', 'dxai-ui' ),
					implode( ', ', $missing )
				),
			),
		);
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_images( string $markup ): array {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $markup, $matches ) ) {
			return array();
		}

		$empty = 0;
		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\bsrc\s*=\s*("|\')(.*?)\1/i', $tag, $src ) || trim( $src[2] ) === '' ) {
				++$empty;
			}
		}
		if ( $empty === 0 ) {
			return array();
		}

		/*
		 * An asset import that failed to bind leaves `src={hero}` resolving to
		 * nothing. The element still occupies its CSS box in some layouts and
		 * collapses in others, so the page looks merely wrong rather than
		 * broken.
		 */
		return array(
			array(
				'check'  => 'image-sources',
				'detail' => sprintf(
					/* translators: %d: number of images. */
					__( '%d image(s) have no source — an asset import did not resolve.', 'dxai-ui' ),
					$empty
				),
			),
		);
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_leaked_source( string $markup ): array {
		// Only the text a visitor reads: a `dxai-ui/html` island legitimately
		// contains markup, and block comments legitimately contain JSON.
		$text = wp_strip_all_tags( (string) preg_replace( '/<!--.*?-->/s', '', $markup ) );
		if ( ! preg_match( self::LEAKED_SOURCE, $text, $hit ) ) {
			return array();
		}

		return array(
			array(
				'check'  => 'no-leaked-source',
				'detail' => sprintf(
					/* translators: %s: the offending snippet. */
					__( 'Component source is being rendered as page copy, near: %s', 'dxai-ui' ),
					trim( substr( $text, max( 0, (int) strpos( $text, $hit[0] ) - 20 ), 90 ) )
				),
			),
		);
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_stylesheet( int $page_id ): array {
		$url = (string) get_post_meta( $page_id, '_dxai_ui_css_url', true );
		if ( $url === '' ) {
			return array(
				array(
					'check'  => 'stylesheet',
					'detail' => __( 'No stylesheet was written for this page.', 'dxai-ui' ),
				),
			);
		}

		// Either stored form — see Upload_Paths. The old str_replace() left a
		// URL from the site's previous address untouched and reported a sheet
		// that was on disk all along as missing.
		$path = \DXAI_UI\Support\Upload_Paths::path( $url );
		if ( $path === '' || ! is_readable( $path ) ) {
			return array(
				array(
					'check'  => 'stylesheet',
					'detail' => sprintf(
						/* translators: %s: stylesheet URL. */
						__( 'The stylesheet is missing from disk: %s', 'dxai-ui' ),
						$url
					),
				),
			);
		}

		$css      = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$findings = array();

		/*
		 * A browser ignores an at-rule it does not know, so `@utility` or a
		 * surviving `@layer` means a design's own component classes silently do
		 * nothing at all.
		 */
		foreach ( array( '@utility' => 'utility', '@layer' => 'layer' ) as $at => $label ) {
			if ( str_contains( $css, $at ) ) {
				$findings[] = array(
					'check'  => 'stylesheet-at-rules',
					'detail' => sprintf(
						/* translators: %s: the at-rule, e.g. @utility. */
						__( '%s survived into the page stylesheet; a browser ignores it, so those rules never apply.', 'dxai-ui' ),
						$at
					),
				);
			}
		}

		return $findings;
	}

	/**
	 * @return array<int, array{check:string, detail:string}>
	 */
	private function check_fonts( int $page_id ): array {
		$urls = get_post_meta( $page_id, '_dxai_ui_font_urls', true );
		if ( ! is_array( $urls ) ) {
			return array();
		}

		$seen = array();
		foreach ( $urls as $url ) {
			if ( ! preg_match_all( '/family=([^:&]+)/', (string) $url, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $family ) {
				$key = strtolower( urldecode( str_replace( '+', ' ', $family ) ) );
				$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
			}
		}

		$twice = array_keys( array_filter( $seen, static fn( int $n ): bool => $n > 1 ) );
		if ( $twice === array() ) {
			return array();
		}

		/*
		 * Two faces of one family let the browser synthesise a weight instead
		 * of using the variable face — about 7% wider glyphs, which moves every
		 * line break on the page.
		 */
		return array(
			array(
				'check'  => 'fonts-once',
				'detail' => sprintf(
					/* translators: %s: comma-separated font families. */
					__( 'Font famil(ies) requested more than once: %s. The browser may synthesise weights instead of using the real face.', 'dxai-ui' ),
					implode( ', ', $twice )
				),
			),
		);
	}

	/**
	 * The template parts a page references, mapped to their content — or null
	 * where the part is missing.
	 *
	 * @return array<string, string|null>
	 */
	private function template_parts( string $markup ): array {
		if ( ! preg_match_all( '/wp:template-part\s*\{[^}]*"slug"\s*:\s*"([^"]+)"/', $markup, $matches ) ) {
			return array();
		}

		$out = array();
		foreach ( array_unique( $matches[1] ) as $slug ) {
			$found = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'name'           => $slug,
					'post_status'    => 'any',
					'posts_per_page' => 1,
				)
			);
			$out[ $slug ] = $found ? (string) $found[0]->post_content : null;
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$out[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, $this->flatten( $block['innerBlocks'] ) );
			}
		}

		return $out;
	}
}

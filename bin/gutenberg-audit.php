<?php
/**
 * The blocks of pages, held to what a seasoned Gutenberg developer does (docs/PLAN-PERFECT-PAGES.md, section 3, gate P14).
 *
 *   wp eval-file bin/gutenberg-audit.php <page id>... [out=<report.json>]
 *
 * Per page, from its blocks and from its content as WordPress renders it:
 *   1  core and theme blocks, no HTML/classic blocks with words in them;
 *   2  style from presets: no inline styles, no custom colours or font sizes where the theme has presets;
 *   3  one H1, headings in order;
 *   4  pictures from the media library (srcset), with alt, width and height;
 *   5  questions in core/details;
 *   6  buttons in core/buttons;
 *   7  a section repeated on several pages is a synced pattern (core/block);
 *   9  no group that only wraps one other group;
 *   10 no empty paragraph, heading, group or column;
 *   11 the blocks parse back to the same markup;
 *   12 links that say where they go.
 * Reads only. Prints one line a page and the findings; exit code 0 (a measurement).
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;

$dxai_audit_ids = array();
$dxai_audit_out = '';
foreach ( (array) ( $args ?? array() ) as $dxai_arg ) {
	if ( str_starts_with( (string) $dxai_arg, 'out=' ) ) {
		$dxai_audit_out = substr( (string) $dxai_arg, 4 );
	} elseif ( ctype_digit( (string) $dxai_arg ) ) {
		$dxai_audit_ids[] = (int) $dxai_arg;
	}
}
if ( $dxai_audit_ids === array() ) {
	WP_CLI::error( 'usage: wp eval-file bin/gutenberg-audit.php <page id>... [out=<report.json>]' );
}

/** Every block of a tree, depth first, with its depth. */
function dxai_audit_walk( array $blocks, int $depth = 0 ): array {
	$out = array();
	foreach ( $blocks as $b ) {
		if ( ! is_array( $b ) ) {
			continue;
		}
		$out[] = array( $b, $depth );
		$out   = array_merge( $out, dxai_audit_walk( (array) ( $b['innerBlocks'] ?? array() ), $depth + 1 ) );
	}
	return $out;
}

/** The rendered content as a DOM. */
function dxai_audit_dom( string $html ): DOMXPath {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="dxai-audit">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	return new DOMXPath( $doc );
}

$dxai_audit_pages    = array();
$dxai_audit_sections = array();
foreach ( $dxai_audit_ids as $dxai_id ) {
	$markup = (string) get_post_field( 'post_content', $dxai_id );
	$blocks = parse_blocks( $markup );
	$all    = dxai_audit_walk( $blocks );
	$find   = array();
	$count  = array( 'blocks' => 0, 'core' => 0, 'theme' => 0, 'plugin' => 0, 'other' => 0 );

	foreach ( $all as list( $b, $depth ) ) {
		$name = (string) ( $b['blockName'] ?? '' );
		$html = (string) ( $b['innerHTML'] ?? '' );
		if ( $name === '' ) {
			// 1 · classic content with words in it.
			if ( trim( wp_strip_all_tags( $html ) ) !== '' ) {
				$find['1'][] = 'classic (freeform) content: ' . mb_substr( trim( wp_strip_all_tags( $html ) ), 0, 50 );
			}
			continue;
		}
		$count['blocks']++;
		$ns = strtok( $name, '/' );
		$count[ $ns === 'core' ? 'core' : ( in_array( $ns, array( 'dx', 'amr' ), true ) ? 'theme' : ( $ns === 'dxai-ui' ? 'plugin' : 'other' ) ) ]++;
		$attrs = (array) ( $b['attrs'] ?? array() );
		// 1 · an HTML block that carries words.
		if ( in_array( $name, array( 'core/html', 'core/freeform', 'core/shortcode' ), true ) && trim( wp_strip_all_tags( $html ) ) !== '' ) {
			$find['1'][] = $name . ': ' . mb_substr( trim( wp_strip_all_tags( $html ) ), 0, 50 );
		}
		// 2 · custom values where presets exist.
		$style = (array) ( $attrs['style'] ?? array() );
		foreach ( array( 'text', 'background' ) as $k ) {
			$v = (string) ( $style['color'][ $k ] ?? '' );
			if ( $v !== '' && ! str_starts_with( $v, 'var:' ) && ! str_starts_with( $v, 'var(' ) ) {
				$find['2'][] = $name . ' custom ' . $k . ' colour ' . $v;
			}
		}
		$fs = (string) ( $style['typography']['fontSize'] ?? '' );
		if ( $fs !== '' && ! str_starts_with( $fs, 'var:' ) && ! str_starts_with( $fs, 'var(' ) && ! str_starts_with( $fs, 'clamp(' ) ) {
			$find['2'][] = $name . ' custom font size ' . $fs;
		}
		// 9 · a group that only wraps one group.
		$inner = (array) ( $b['innerBlocks'] ?? array() );
		if ( $name === 'core/group' && count( $inner ) === 1 && ( $inner[0]['blockName'] ?? '' ) === 'core/group' && array_diff( array_keys( $attrs ), array( 'className', 'layout', 'metadata', 'lock' ) ) === array() ) {
			$find['9'][] = 'a group that only wraps a group' . ( isset( $attrs['className'] ) ? ' (' . $attrs['className'] . ')' : '' );
		}
		// 10 · empty blocks.
		if ( in_array( $name, array( 'core/paragraph', 'core/heading', 'core/list-item' ), true ) && trim( wp_strip_all_tags( $html ) ) === '' && strpos( $html, '<img' ) === false ) {
			$find['10'][] = 'empty ' . $name;
		}
		if ( in_array( $name, array( 'core/group', 'core/column', 'core/columns', 'core/buttons' ), true ) && $inner === array() && trim( wp_strip_all_tags( $html ) ) === '' ) {
			$find['10'][] = 'empty ' . $name;
		}
		// 4 · a core/image not from the media library has no srcset.
		if ( $name === 'core/image' && empty( $attrs['id'] ) ) {
			$find['4'][] = 'core/image without an attachment (no srcset)';
		}
	}

	// 11 · the blocks parse back to the same markup (the blocks themselves, not the white space between them — as G7 checks).
	if ( is_wp_error( Block_Tree::serialize_checked( array_values( array_filter( $blocks, static fn( $b ) => trim( (string) ( $b['blockName'] ?? '' ) ) !== '' ) ) ) ) ) {
		$find['11'][] = 'does not parse back to the same markup';
	}

	// The rendered content.
	$xp = dxai_audit_dom( do_blocks( $markup ) );
	// 2 · inline styles in what is shown.
	$styled = $xp->query( '//*[@style]' )->length;
	if ( $styled > 0 ) {
		$find['2'][] = $styled . ' elements with inline styles';
	}
	// 3 · one H1, headings in order.
	$levels = array();
	foreach ( $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $h ) {
		if ( trim( $h->textContent ) !== '' ) {
			$levels[] = (int) substr( $h->nodeName, 1 );
		}
	}
	$h1 = count( array_filter( $levels, static fn( $l ) => $l === 1 ) );
	if ( $h1 !== 1 ) {
		$find['3'][] = $h1 . ' H1 headings';
	}
	$skips = 0;
	for ( $i = 1, $n = count( $levels ); $i < $n; $i++ ) {
		if ( $levels[ $i ] > $levels[ $i - 1 ] + 1 ) {
			$skips++;
		}
	}
	if ( $skips > 0 ) {
		$find['3'][] = $skips . ' headings that skip a level';
	}
	// 4 · pictures: alt, width, height.
	$imgs = $xp->query( '//img' );
	$no_alt = 0;
	$no_size = 0;
	foreach ( $imgs as $img ) {
		if ( ! $img->hasAttribute( 'alt' ) ) {
			$no_alt++;
		}
		if ( ! $img->hasAttribute( 'width' ) || ! $img->hasAttribute( 'height' ) ) {
			$no_size++;
		}
	}
	if ( $no_alt ) {
		$find['4'][] = $no_alt . ' pictures without alt';
	}
	if ( $no_size ) {
		$find['4'][] = $no_size . ' pictures without width and height (the page shifts as they load)';
	}
	// 5 · questions outside core/details: a heading or bold line that asks, in a group with others that ask.
	$asks = 0;
	foreach ( $xp->query( '//h2|//h3|//h4|//h5|//h6|//p/strong|//button|//p' ) as $q ) {
		$t = trim( $q->textContent );
		if ( $t !== '' && str_ends_with( $t, '?' ) && mb_strlen( $t ) <= 180 && $xp->query( 'ancestor::details', $q )->length === 0 ) {
			$asks++;
		}
	}
	if ( $asks >= 2 ) {
		$find['5'][] = $asks . ' questions outside core/details';
	}
	// 6 · buttons outside core/buttons.
	$loose = 0;
	foreach ( $xp->query( '//a[contains(concat(" ", normalize-space(@class), " "), "button") or contains(@class, "btn")]' ) as $a ) {
		if ( $xp->query( 'ancestor::*[contains(concat(" ", normalize-space(@class), " "), " wp-block-button ")]', $a )->length === 0 && ! str_contains( (string) $a->getAttribute( 'class' ), 'wp-block-button__link' ) ) {
			$loose++;
		}
	}
	if ( $loose ) {
		$find['6'][] = $loose . ' links styled as buttons outside core/buttons';
	}
	// 12 · links that do not say where they go.
	$vague = 0;
	foreach ( $xp->query( '//a' ) as $a ) {
		$t = strtolower( trim( preg_replace( '/\s+/', ' ', $a->textContent ) ) );
		if ( preg_match( '/^(click here|here|learn more|read more|more|details|view more|see more)\W*$/', $t ) && ! $a->hasAttribute( 'aria-label' ) ) {
			$vague++;
		}
	}
	if ( $vague ) {
		$find['12'][] = $vague . ' links that only say "learn more" or the like';
	}

	// 7 · sections (top-level blocks), for the repeats across pages.
	foreach ( $blocks as $top ) {
		if ( ! is_array( $top ) || empty( $top['blockName'] ) || $top['blockName'] === 'core/block' ) {
			continue;
		}
		$sig = md5( serialize_block( $top ) );
		$dxai_audit_sections[ $sig ][ $dxai_id ] = (string) ( $top['attrs']['metadata']['name'] ?? $top['attrs']['className'] ?? $top['blockName'] );
	}
	$synced = substr_count( $markup, '<!-- wp:block ' );
	$dxai_audit_pages[ $dxai_id ] = array(
		'id'     => $dxai_id,
		'title'  => html_entity_decode( get_the_title( $dxai_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		'count'  => $count,
		'synced' => $synced,
		'find'   => $find,
	);
}

// 7 · a section that is on two or more of these pages, word for word, and is not a synced pattern.
foreach ( $dxai_audit_sections as $sig => $pages ) {
	if ( count( $pages ) >= 2 ) {
		foreach ( $pages as $id => $label ) {
			$dxai_audit_pages[ $id ]['find']['7'][] = 'the same section on ' . count( $pages ) . ' pages, not a synced pattern: ' . mb_substr( $label, 0, 40 );
		}
	}
}

$dxai_total = 0;
foreach ( $dxai_audit_pages as $p ) {
	$n           = array_sum( array_map( 'count', $p['find'] ) );
	$dxai_total += $n;
	$share       = $p['count']['blocks'] ? round( 100 * ( $p['count']['core'] + $p['count']['theme'] ) / $p['count']['blocks'] ) : 0;
	WP_CLI::log( sprintf( '%6d %-34s %4d blocks, %3d%% core/theme, %d plugin, %d synced · %d findings', $p['id'], mb_substr( $p['title'], 0, 34 ), $p['count']['blocks'], $share, $p['count']['plugin'], $p['synced'], $n ) );
	foreach ( $p['find'] as $rule => $list ) {
		$shown = array_count_values( $list );
		foreach ( array_slice( $shown, 0, 4, true ) as $what => $times ) {
			WP_CLI::log( sprintf( '         #%-2s %s%s', $rule, $what, $times > 1 ? ' ×' . $times : '' ) );
		}
		if ( count( $shown ) > 4 ) {
			WP_CLI::log( sprintf( '         #%-2s … %d more', $rule, count( $shown ) - 4 ) );
		}
	}
}
WP_CLI::log( sprintf( "\n%d pages, %d findings", count( $dxai_audit_pages ), $dxai_total ) );
if ( $dxai_audit_out !== '' ) {
	file_put_contents( $dxai_audit_out, wp_json_encode( array_values( $dxai_audit_pages ), JSON_PRETTY_PRINT ) );
}

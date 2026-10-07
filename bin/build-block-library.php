<?php
/**
 * Builds data/block-library from the team's own pages: the sections of the two "DX Test Page" exports (Arcus and Archer Restoration, the
 * finished Home, service, About, Testimonials, location, FAQ and Contact pages of two sites of the team, each written as one long page).
 *
 *   wp eval-file bin/build-block-library.php --user=1 "<code-editor-arcus>" "<code-editor-archer>" [dry]
 *
 * The two files are the pages' block markup as the block editor's code editor shows it. This is a developer tool: the plugin ships what
 * it writes (data/block-library/), not the files it reads.
 *
 * What is kept is what makes a section the team's: its blocks, classes, spacing, colours and typography as the page's block settings
 * wrote them. What is taken out is what makes it one company's: the name, the phone, the address, the cities, the claims, the pictures,
 * the review widget and the form. Each is a token ({{company}}, {{phone}}, …) a page is made with from what the Home says, or a marker
 * (`dxaiRepeat`, `dxaiIf`, `dxaiIcon`) the library reads when it fills the section.
 *
 * Every word of every section is read here: a text the spec does not map or keep stops the build, so no sentence of another company
 * reaches a page unseen. A last check looks for what must not be there at all (the two companies' names, their cities, their phones, a
 * number of reviews, an address) and stops the build when it finds one.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;

$dry   = in_array( 'dry', $args, true );
$files = array_values( array_filter( $args, static fn( $a ) => is_string( $a ) && $a !== 'dry' ) );
if ( count( $files ) < 2 || ! is_readable( $files[0] ) || ! is_readable( $files[1] ) ) {
	fwrite( STDERR, "usage: wp eval-file bin/build-block-library.php --user=1 <code-editor-arcus> <code-editor-archer> [dry]\n" );
	exit( 2 );
}
$sources = array(
	'arcus'  => (string) file_get_contents( $files[0] ),
	'archer' => (string) file_get_contents( $files[1] ),
);
$pages = array();
foreach ( $sources as $site => $markup ) {
	$pages[ $site ] = array_values( array_filter( parse_blocks( $markup ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
}

require __DIR__ . '/block-library-specs.php'; // $specs, $forbidden, $leaf_blocks

$out_dir = dirname( __DIR__ ) . '/data/block-library';
$errors  = array();
$index   = array();

/** The leaf blocks that hold words: the build reads each one. */
$is_text = static fn( array $b ): bool => empty( $b['innerBlocks'] ) && in_array( (string) $b['blockName'], $leaf_blocks, true );

/** Visit every block with its path (child indexes joined by dots, the section being "0"). */
$visit = static function ( array &$block, callable $fn, string $path = '0' ) use ( &$visit ): void {
	$fn( $block, $path );
	foreach ( $block['innerBlocks'] as $i => &$child ) {
		if ( ! empty( $child['blockName'] ) ) {
			$visit( $child, $fn, $path . '.' . $i );
		}
	}
};
$path_of = static fn( string $p ): array => array_map( 'intval', array_slice( explode( '.', $p ), 1 ) );

/** Every string of a block: its attributes (at any depth) and its own markup, through $fn. */
$each_string = static function ( array &$b, callable $fn ) use ( &$each_string ): void {
	$walk = static function ( &$v ) use ( &$walk, $fn ): void {
		if ( is_string( $v ) ) {
			$v = $fn( $v );
		} elseif ( is_array( $v ) ) {
			foreach ( $v as &$x ) {
				$walk( $x );
			}
		}
	};
	$walk( $b['attrs'] );
	$b['innerHTML'] = $fn( (string) $b['innerHTML'] );
	foreach ( $b['innerContent'] as &$part ) {
		if ( is_string( $part ) ) {
			$part = $fn( $part );
		}
	}
};

/** The block's own opening tag (the first one in its markup), changed by $fn. */
$open_tag = static function ( array &$b, callable $fn ): void {
	$change = static fn( string $s ): string => (string) preg_replace_callback( '/<[a-z][^>]*>/i', static fn( $m ) => $fn( $m[0] ), $s, 1 );
	$b['innerHTML'] = $change( (string) $b['innerHTML'] );
	foreach ( $b['innerContent'] as $k => $part ) {
		if ( is_string( $part ) ) {
			$b['innerContent'][ $k ] = $change( $part );
			break;
		}
	}
};

/** A block's background (or text) colour as the theme's own preset, in its attributes and its markup. */
$to_preset = static function ( array &$b, string $kind, string $slug ) use ( $open_tag ): void {
	$prop = $kind === 'text' ? 'text' : 'background';
	unset( $b['attrs']['style']['color'][ $prop ] );
	if ( isset( $b['attrs']['style']['color'] ) && $b['attrs']['style']['color'] === array() ) {
		unset( $b['attrs']['style']['color'] );
	}
	$b['attrs'][ $kind === 'text' ? 'textColor' : 'backgroundColor' ] = $slug;
	$open_tag(
		$b,
		static function ( string $tag ) use ( $kind, $slug ): string {
			// The colour leaves the inline style (which goes when nothing else is in it); the preset's classes come in, once.
			$tag     = (string) preg_replace_callback(
				'/\sstyle="([^"]*)"/i',
				static function ( $m ) use ( $kind ): string {
					$css = (string) preg_replace( $kind === 'text' ? '/(?<![-\w])color:[^;"]*;?/i' : '/background-color:[^;"]*;?/i', '', $m[1] );
					$css = trim( $css, '; ' );

					return $css === '' ? '' : ' style="' . $css . '"';
				},
				$tag
			);
			$classes = $kind === 'text' ? array( 'has-' . $slug . '-color', 'has-text-color' ) : array( 'has-' . $slug . '-background-color', 'has-background' );

			return (string) preg_replace_callback(
				'/class="([^"]*)"/i',
				static function ( $m ) use ( $classes ): string {
					$have = preg_split( '/\s+/', trim( $m[1] ) ) ?: array();
					foreach ( $classes as $c ) {
						if ( ! in_array( $c, $have, true ) ) {
							$have[] = $c;
						}
					}

					return 'class="' . implode( ' ', array_filter( $have ) ) . '"';
				},
				$tag,
				1
			);
		}
	);
};

/**
 * The whitespace between a block's children after some were taken out: one blank line between two blocks, none before the first or
 * after the last (the way the editor writes them).
 */
$tidy = static function ( array &$b ) use ( &$tidy ): void {
	if ( $b['innerBlocks'] === array() ) {
		return;
	}
	$content = array();
	foreach ( $b['innerContent'] as $part ) {
		if ( is_string( $part ) && $content !== array() && is_string( end( $content ) ) ) {
			$content[ array_key_last( $content ) ] .= $part;
			continue;
		}
		$content[] = $part;
	}
	$last = count( $content ) - 1;
	foreach ( $content as $k => $part ) {
		if ( ! is_string( $part ) ) {
			continue;
		}
		$after  = $k > 0 && $content[ $k - 1 ] === null;
		$before = $k < $last && $content[ $k + 1 ] === null;
		if ( $after && $before ) {
			$content[ $k ] = trim( $part ) === '' ? "\n\n" : $part;
		} elseif ( $after ) {
			$content[ $k ] = ltrim( $part );
		} elseif ( $before && $k === 0 ) {
			$content[ $k ] = rtrim( $part );
		}
	}
	$b['innerContent'] = $content;
	$b['innerHTML']    = implode( '', array_filter( $content, 'is_string' ) );
	foreach ( $b['innerBlocks'] as &$child ) {
		$tidy( $child );
	}
	unset( $child );
};

/** A class on a block: in its attributes and in its own opening tag. */
$add_class = static function ( array &$b, string $token ) use ( $open_tag ): void {
	$have = preg_split( '/\s+/', trim( (string) ( $b['attrs']['className'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	if ( in_array( $token, $have, true ) ) {
		return;
	}
	$b['attrs']['className'] = implode( ' ', array_merge( $have, array( $token ) ) );
	$open_tag(
		$b,
		static function ( string $tag ) use ( $token ): string {
			if ( preg_match( '/\sclass="[^"]*"/', $tag ) === 1 ) {
				return (string) preg_replace_callback( '/\sclass="([^"]*)"/', static fn( array $m ): string => ' class="' . $m[1] . ' ' . $token . '"', $tag, 1 );
			}

			return (string) preg_replace_callback( '/^<([a-z][a-z0-9]*)/i', static fn( array $m ): string => '<' . $m[1] . ' class="' . $token . '"', $tag, 1 );
		}
	);
};

/** A block names no font of its own: the site's fonts (the theme's heading and body fonts) are what it is set in. */
$strip_fonts = static function ( array &$b ): void {
	unset( $b['attrs']['fontFamily'], $b['attrs']['style']['typography']['fontFamily'] );
	$fix = static function ( string $s ): string {
		$s = (string) preg_replace( '/\s+has-[a-z0-9-]+-font-family\b/', '', $s );
		$s = (string) preg_replace( '/font-family:[^;"]*;?/', '', $s );

		return (string) preg_replace( '/\sstyle=""/', '', $s );
	};
	$b['innerHTML'] = $fix( (string) $b['innerHTML'] );
	foreach ( $b['innerContent'] as $k => $part ) {
		if ( is_string( $part ) ) {
			$b['innerContent'][ $k ] = $fix( $part );
		}
	}
};

foreach ( $specs as $id => $spec ) {
	list( $site, $at ) = $spec['from'];
	if ( ! isset( $pages[ $site ][ $at ] ) ) {
		$errors[] = "$id: no section $site:$at";
		continue;
	}
	$root  = $pages[ $site ][ $at ];
	$seen  = array();
	$todo  = array();
	// 1. Every word is mapped, kept or dropped; the ones that are none stop the build.
	$visit(
		$root,
		static function ( array &$b, string $path ) use ( $spec, $is_text, &$seen, &$todo ): void {
			if ( ! $is_text( $b ) ) {
				return;
			}
			$text = Block_Tree::clean( Block_Tree::own_text( $b ) );
			if ( $text === '' ) {
				return;
			}
			$seen[ $text ] = true;
			// A section made of long, general answers (a page of questions) is read whole and kept but for what the spec says: the leak check
			// below is what stops a word of one company (`texts_open`).
			$rule = $spec['texts'][ $text ] ?? ( ! empty( $spec['texts_open'] ) ? true : null );
			if ( $rule === null ) {
				$todo[] = "$path  " . $b['blockName'] . '  “' . $text . '”';
			}
		}
	);
	foreach ( $todo as $line ) {
		$errors[] = "$id: no rule for $line";
	}
	foreach ( array_keys( (array) $spec['texts'] ) as $text ) {
		if ( ! isset( $seen[ $text ] ) ) {
			$errors[] = "$id: a rule for a text the section does not have: “" . substr( (string) $text, 0, 70 ) . '”';
		}
	}
	if ( $todo !== array() ) {
		continue;
	}
	// 2. Apply: words, then the structure.
	$drops = array();
	$visit(
		$root,
		static function ( array &$b, string $path ) use ( $spec, $is_text, $each_string, &$drops ): void {
			if ( ! $is_text( $b ) ) {
				return;
			}
			$text = Block_Tree::clean( Block_Tree::own_text( $b ) );
			$rule = $spec['texts'][ $text ] ?? true;
			// The words keep no link to the page of one site (`unlink`): a sentence that says "water damage restoration" links its service
			// page on the site it came from, and the words stay without it. A button is a link by being one.
			if ( ! empty( $spec['unlink'] ) && (string) $b['blockName'] !== 'core/button' && is_string( $rule ) === false ) {
				$each_string( $b, static fn( string $s ): string => (string) preg_replace( '#<a\b[^>]*>(.*?)</a>#is', '$1', $s ) );
			}
			if ( $rule === true ) {
				return;
			}
			if ( $rule === false ) {
				$drops[] = $path;
				return;
			}
			if ( is_array( $rule ) ) {
				// A button: its words and where it goes.
				Block_Tree::set_link( $b, (string) $rule['url'], (string) $rule['text'] );
				return;
			}
			Block_Tree::set_content( $b, (string) $rule );
		}
	);
	// The structure, by the paths the section has as it was taken: where a link goes, the attributes the library reads, the icons.
	foreach ( (array) ( $spec['links'] ?? array() ) as $p => $url ) {
		$node = &Block_Tree::at( $root, $path_of( (string) $p ) );
		Block_Tree::set_link( $node, (string) $url );
		unset( $node );
	}
	foreach ( (array) ( $spec['attrs'] ?? array() ) as $p => $set ) {
		$node = &Block_Tree::at( $root, $path_of( (string) $p ) );
		foreach ( (array) $set as $k => $v ) {
			$node['attrs'][ $k ] = $v;
		}
		unset( $node );
	}
	foreach ( (array) ( $spec['icons'] ?? array() ) as $p => $name ) {
		$node = &Block_Tree::at( $root, $path_of( (string) $p ) );
		// The size the team gave the drawing is the block's own setting (the editor writes it from these): it stays, though a picture swapped
		// in for a design's drops the pixel size of the one it replaces.
		$size = array_intersect_key( (array) $node['attrs'], array_flip( array( 'width', 'height' ) ) );
		Block_Tree::set_image( $node, '{{icon_url:' . $name . '}}', '' );
		$node['attrs'] = array_merge( (array) $node['attrs'], $size );
		$node['attrs']['dxaiIcon'] = (string) $name;
		unset( $node['attrs']['id'] );
		$node['innerHTML']    = (string) preg_replace( '/\bwp-image-\d+\b/', 'wp-image-{{icon_id:' . $name . '}}', (string) $node['innerHTML'] );
		$node['innerContent'] = array( $node['innerHTML'] );
		unset( $node );
	}
	$drops = array_merge( $drops, (array) ( $spec['drop'] ?? array() ) );
	// A block is removed from the last path to the first, so the ones before it keep their numbers.
	usort( $drops, static fn( $a, $b ) => strnatcmp( $b, $a ) );
	foreach ( array_unique( $drops ) as $p ) {
		Block_Tree::remove( $root, $path_of( $p ) );
	}
	foreach ( (array) ( $spec['repeat'] ?? array() ) as $p => $rule ) {
		// The parent keeps its first N children; the first is the one the library repeats.
		$parent = &Block_Tree::at( $root, $path_of( (string) $p ) );
		for ( $k = count( $parent['innerBlocks'] ) - 1; $k >= (int) $rule['keep']; $k-- ) {
			Block_Tree::drop( $parent, $k );
		}
		$parent['innerBlocks'][0]['attrs']['dxaiRepeat'] = (string) $rule['name'];
		unset( $parent );
	}
	$tidy( $root );
	// What belongs to one company or one site: the fonts it names (the site's own are used), its colours (the theme's presets, or a
	// neutral), and the classes only its site has.
	$visit(
		$root,
		static function ( array &$b ) use ( $strip_fonts ): void {
			$strip_fonts( $b );
		}
	);
	foreach ( (array) ( $spec['colors'] ?? array() ) as $from => $to ) {
		$visit(
			$root,
			static function ( array &$b ) use ( $from, $to, $each_string, $to_preset ): void {
				if ( is_array( $to ) ) {
					$kind = isset( $to['text'] ) ? 'text' : 'bg';
					$slug = (string) ( $to['text'] ?? $to['bg'] );
					$have = strtolower( (string) ( $b['attrs']['style']['color'][ $kind === 'text' ? 'text' : 'background' ] ?? '' ) );
					if ( $have === strtolower( (string) $from ) ) {
						$to_preset( $b, $kind, $slug );
					}

					return;
				}
				$each_string( $b, static fn( string $s ): string => str_ireplace( (string) $from, (string) $to, $s ) );
			}
		);
	}
	foreach ( (array) ( $spec['drop_classes'] ?? array() ) as $class ) {
		$visit(
			$root,
			static function ( array &$b ) use ( $class, $each_string ): void {
				$each_string( $b, static fn( string $s ): string => (string) preg_replace( '/(?<![\w-])' . preg_quote( (string) $class, '/' ) . '(?![\w-])\s?/', '', $s ) );
				if ( isset( $b['attrs']['className'] ) && trim( (string) $b['attrs']['className'] ) === '' ) {
					unset( $b['attrs']['className'] );
				}
			}
		);
	}
	// What marks it as the library's (Pages\Block_Library, Blocks\Library_View): the section, so what the library's CSS says is said
	// of it and of nothing else; and a container whose children sit with no gap between them, which the theme's default gap must not reach.
	$add_class( $root, 'dxai-lib' );
	$visit(
		$root,
		static function ( array &$b ) use ( $add_class, &$errors, $id ): void {
			$gap = $b['attrs']['style']['spacing']['blockGap'] ?? null;
			if ( $gap === null || ! in_array( (string) $b['blockName'], array( 'core/group', 'core/column' ), true ) || in_array( (string) ( $b['attrs']['layout']['type'] ?? 'default' ), array( 'flex', 'grid' ), true ) ) {
				return;
			}
			if ( is_string( $gap ) && preg_match( '/^0(?:px|rem|em)?$/', trim( $gap ) ) === 1 ) {
				$add_class( $b, 'dxai-lib-tight' );
			} else {
				$errors[] = "$id: a container has a block gap of its own (" . wp_json_encode( $gap ) . '): the library\'s CSS knows a gap of nothing, and the theme\'s';
			}
		}
	);
	$markup = trim( serialize_blocks( array( $root ) ) ) . "\n";
	foreach ( (array) ( $spec['replace'] ?? array() ) as $from => $to ) {
		$markup = str_replace( (string) $from, (string) $to, $markup );
	}
	// 3. What must not be there.
	$plain = html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' ' . $markup;
	foreach ( $forbidden as $label => $re ) {
		if ( preg_match( $re, $plain, $m, PREG_OFFSET_CAPTURE ) === 1 ) {
			$at       = (int) $m[0][1];
			$errors[] = "$id: $label is still in it: “" . substr( $m[0][0], 0, 60 ) . '” … ' . str_replace( "\n", ' ', substr( $plain, max( 0, $at - 70 ), 160 ) );
		}
	}
	if ( substr_count( $markup, '{{' ) !== preg_match_all( '/\{\{[^{}]*\}\}/', $markup ) ) {
		$errors[] = "$id: a token is not closed";
	}
	// 4. What the section needs from the site it is on.
	$classes = array();
	$names   = array();
	$tokens  = array();
	$walk    = static function ( array $b ) use ( &$walk, &$classes, &$names ): void {
		$names[ (string) $b['blockName'] ] = true;
		foreach ( preg_split( '/\s+/', trim( (string) ( $b['attrs']['className'] ?? '' ) ) ) ?: array() as $c ) {
			if ( $c !== '' ) {
				$classes[ $c ] = true;
			}
		}
		foreach ( $b['innerBlocks'] as $child ) {
			if ( ! empty( $child['blockName'] ) ) {
				$walk( $child );
			}
		}
	};
	$walk( $root );
	if ( preg_match_all( '/\{\{\s*([a-z_]+)/i', $markup, $m ) ) {
		$tokens = array_values( array_unique( $m[1] ) );
	}
	sort( $tokens );
	$class_list = array_keys( $classes );
	sort( $class_list );
	$block_list = array_keys( $names );
	sort( $block_list );
	$index[ $id ] = array(
		'id'      => $id,
		'role'    => (string) $spec['role'],
		'label'   => (string) $spec['label'],
		'from'    => $site . '#' . $at,
		'builder' => ! empty( $spec['builder'] ),
		// What kind of company its words are for: they are one kind's (a restoration company's), and are not used for another's.
		'industry' => (string) ( $spec['industry'] ?? 'restoration' ),
		'needs'   => array_values( (array) ( $spec['needs'] ?? array() ) ),
		'pages'   => array_values( (array) ( $spec['pages'] ?? array() ) ),
		'tokens'  => $tokens,
		'blocks'  => $block_list,
		'classes' => $class_list,
		'bytes'   => strlen( $markup ),
	);
	if ( ! $dry && $errors === array() ) {
		wp_mkdir_p( $out_dir . '/blocks' );
		file_put_contents( $out_dir . '/blocks/' . $id . '.html', $markup );
		// The section as an editor pattern: a file the pattern registry reads when the editor asks (Pages\Library_Patterns).
		wp_mkdir_p( $out_dir . '/patterns' );
		file_put_contents(
			$out_dir . '/patterns/' . $id . '.php',
			"<?php\n/**\n * The library's section \"$id\" as a block pattern, made for the site it is inserted on (generated by bin/build-block-library.php).\n *\n * @package DXAI_UI\n */\n\ndefined( 'ABSPATH' ) || exit;\n\necho \\DXAI_UI\\Pages\\Block_Library::pattern( '$id' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped\n"
		);
	}
	echo sprintf( "  %-22s %-8s %5d B  %2d blocks  tokens: %s\n", $id, $spec['role'], strlen( $markup ), count( $block_list ), implode( ' ', $tokens ) );
}
if ( $errors !== array() ) {
	fwrite( STDERR, "\nNOT WRITTEN:\n  " . implode( "\n  ", $errors ) . "\n" );
	exit( 1 );
}
if ( ! $dry ) {
	ksort( $index );
	$doc = array(
		'version' => 1,
		'themes'  => array( 'american-restoration' ),
		'entries' => array_values( $index ),
	);
	file_put_contents( $out_dir . '/library.json', wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo 'wrote ' . count( $index ) . " entries to data/block-library\n";
} else {
	echo "dry run: nothing written\n";
}

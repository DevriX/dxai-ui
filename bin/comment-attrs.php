<?php
/**
 * Does the block comment we write hold anything Gutenberg's own serializer
 * would leave out?
 *
 * PHP's serialize_block() writes every attribute it is handed. The editor's
 * getCommentAttributes() consults the block's schema first and drops two
 * kinds:
 *
 *   1. Any attribute declaring a `source`. It is re-read from the markup on
 *      parse, so a copy in the comment is never read — and PHP's
 *      parse_blocks() does NOT apply sources, so that copy becomes a second,
 *      stale source of truth for the same text.
 *   2. Any attribute whose value equals its declared `default`.
 *
 * Either one means the markup we store is not the markup the editor writes,
 * so the user's first save silently rewrites post_content. No other check in
 * bin/ can see this: block-parity compares the block TYPES, and the geometry
 * and round-trip checks both run over markup we produced ourselves.
 *
 * usage: bin/wp-php.sh bin/comment-attrs.php <file-or-dir-of-block-markup>...
 * exit:  0 clean, 1 something the editor would drop, 2 bad usage
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$paths = array_slice( $argv, 1 );
if ( $paths === array() ) {
	fwrite( STDERR, "usage: bin/wp-php.sh bin/comment-attrs.php <file-or-dir>...\n" );
	exit( 2 );
}

$files = array();
foreach ( $paths as $path ) {
	if ( is_dir( $path ) ) {
		foreach ( (array) glob( rtrim( $path, '/\\' ) . '/*' ) as $found ) {
			if ( is_file( $found ) && preg_match( '/\.(html|txt|astrip|bstrip)$/', (string) $found ) === 1 ) {
				$files[] = (string) $found;
			}
		}
		continue;
	}
	if ( is_file( $path ) ) {
		$files[] = $path;
	}
}
if ( $files === array() ) {
	fwrite( STDERR, "comment-attrs: no readable block markup in the given paths\n" );
	exit( 2 );
}

/** @var array<string, WP_Block_Type> $types */
$types = WP_Block_Type_Registry::get_instance()->get_all_registered();

/**
 * Every block in the tree, flattened.
 *
 * @param array<int, array<string, mixed>> $blocks
 * @return array<int, array<string, mixed>>
 */
function flatten( array $blocks ): array {
	$out = array();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		if ( (string) ( $block['blockName'] ?? '' ) !== '' ) {
			$out[] = $block;
		}
		$out = array_merge( $out, flatten( (array) ( $block['innerBlocks'] ?? array() ) ) );
	}

	return $out;
}

$sourced  = array();
$defaults = array();
$bytes    = 0;
$counted  = 0;

foreach ( $files as $file ) {
	$markup = (string) file_get_contents( $file );
	if ( ! str_contains( $markup, '<!-- wp:' ) ) {
		continue;
	}
	$label = basename( $file );
	foreach ( flatten( parse_blocks( $markup ) ) as $block ) {
		$name  = (string) $block['blockName'];
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( $attrs === array() || ! isset( $types[ $name ] ) ) {
			continue;
		}
		++$counted;
		$schema = (array) $types[ $name ]->attributes;
		foreach ( $attrs as $key => $value ) {
			$spec = is_array( $schema[ $key ] ?? null ) ? $schema[ $key ] : null;
			if ( $spec === null ) {
				continue;
			}
			// serialize_block_attributes() is what actually wrote these bytes,
			// so it is what measures them.
			$cost = strlen( serialize_block_attributes( array( $key => $value ) ) ) - 2;
			if ( isset( $spec['source'] ) ) {
				$sourced[ $name . ' ' . $key ][ $label ] = ( $sourced[ $name . ' ' . $key ][ $label ] ?? 0 ) + 1;
				$bytes                                  += $cost;
				continue;
			}
			if ( array_key_exists( 'default', $spec ) && $spec['default'] === $value ) {
				$defaults[ $name . ' ' . $key ][ $label ] = ( $defaults[ $name . ' ' . $key ][ $label ] ?? 0 ) + 1;
				$bytes                                   += $cost;
			}
		}
	}
}

printf( "blocks with attributes: %d across %d file(s)\n", $counted, count( $files ) );

$report = static function ( string $heading, array $rows ): void {
	printf( "\n%s: %d\n", $heading, count( $rows ) );
	if ( $rows === array() ) {
		print "  (none)\n";
		return;
	}
	ksort( $rows );
	foreach ( $rows as $key => $per_file ) {
		printf( "  %-30s %d  (%s)\n", $key, array_sum( $per_file ), implode( ', ', array_map(
			static fn( $file, $n ) => $file . ' ' . $n,
			array_keys( $per_file ),
			$per_file
		) ) );
	}
};

$report( 'attributes declaring a source', $sourced );
$report( 'attributes equal to their default', $defaults );

if ( $sourced === array() && $defaults === array() ) {
	print "\nclean: the comment holds nothing the editor would drop\n";
	exit( 0 );
}

printf( "\n%d bytes the editor deletes on the first save\n", $bytes );
exit( 1 );

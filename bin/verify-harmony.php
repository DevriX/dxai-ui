<?php
/**
 * The same style for the same kind of element: headings and the spacing of sections, judged by the design's Home.
 *
 *   bash bin/wp-php.sh bin/verify-harmony.php        (or: wp eval-file bin/verify-harmony.php --user=1)
 *
 * Makes a Home and takes pages through Native_Blocks::convert_content() with it as their design: the title most of the Home's
 * sections have is the title of every section of every page, and the padding most of its sections have is theirs. Everything that
 * must be left alone is checked as much as what changes: a label, a card's title, a title set by hand, the header and the footer, a
 * hero, a thin bar, a section padded by a class on every side. Writes only the Home, and removes it. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Compiler\Style_Hoister;

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

// ---- Markup ---------------------------------------------------------------------------------------------------------------------
$h = static function ( int $level, string $class, string $css, string $text = 'A title', array $extra = array() ): string {
	$attrs     = array( 'level' => $level ) + ( $class !== '' ? array( 'className' => $class ) : array() ) + ( $css !== '' ? array( 'dxaiCss' => $css ) : array() ) + $extra;
	$tag_class = trim( 'wp-block-heading ' . $class . ' ' . ( $css !== '' ? Style_Hoister::css_class( $css ) : '' ) );

	return '<!-- wp:heading ' . wp_json_encode( $attrs ) . ' --><h' . $level . ' class="' . $tag_class . '">' . $text . '</h' . $level . '><!-- /wp:heading -->';
};
$g = static function ( string $class, string $css, string $inner, string $tag = 'section', array $extra = array() ): string {
	$attrs     = ( $class !== '' ? array( 'className' => $class ) : array() ) + ( $css !== '' ? array( 'dxaiCss' => $css ) : array() ) + array( 'tagName' => $tag ) + $extra;
	$tag_class = trim( 'wp-block-group ' . $class . ' ' . ( $css !== '' ? Style_Hoister::css_class( $css ) : '' ) );

	return '<!-- wp:group ' . wp_json_encode( $attrs ) . ' --><' . $tag . ' class="' . $tag_class . '">' . $inner . '</' . $tag . '><!-- /wp:group -->';
};
$p = '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->';

$TITLE = "font-family:'Barlow Condensed',sans-serif;font-size:clamp(32px,4vw,52px)";
$PAD_T = 'padding-top:clamp(52px,7vw,92px)';
$PAD_B = 'padding-bottom:clamp(40px,5vw,64px)';
$PAD   = $PAD_T . ';' . $PAD_B;

// The Home: a hero with a page title, five sections of which four are padded 92/64 and four titles are the same (the fifth is another), a label, a card.
$home_content = implode(
	"\n\n",
	array(
		$g( 'bg-dxai-brand', 'padding-top:clamp(60px,8vw,110px);padding-bottom:clamp(40px,5vw,70px)', $h( 1, 'mb-5 uppercase fw-800 lh-1-02', "font-family:'Barlow Condensed',sans-serif;font-size:clamp(36px,5vw,64px)", 'The Home' ) ),
		$g( '', $PAD, $h( 2, 'mb-3 uppercase fw-800 lh-1', $TITLE ) . $p ),
		$g( '', $PAD, $h( 2, 'mb-3 uppercase fw-800 lh-1', $TITLE ) . $h( 2, 'mb-2 uppercase text-13 fw-800 ls-0-16em', '', 'A label' ) . $p ),
		$g( '', $PAD, $h( 2, 'mb-3 uppercase fw-800 lh-1', $TITLE ) . $p ),
		$g( '', $PAD, $h( 2, 'mb-3 uppercase fw-800 lh-1', "font-family:'Barlow Condensed',sans-serif;font-size:clamp(32px,4vw,54px)" ) . $h( 3, 'text-24 fw-800', '', 'A card' ) ),
		$g( '', 'padding-top:clamp(30px,4vw,56px);padding-bottom:clamp(30px,4vw,56px)', $p ),
	)
);
$home_id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Harmony fixture Home', 'post_content' => wp_slash( $home_content ) ) );

$run   = static function ( string $content ) use ( $home_id ): array {
	return Native_Blocks::convert_content( $content, array( 'home' => $home_id, 'post' => 0 ) );
};
$blocks_of = static function ( string $content ): array {
	return array_values( array_filter( parse_blocks( $content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
};
/** The first heading of a page after the pass: [attrs, markup]. */
$first = static function ( array $blocks, string $name = 'core/heading' ) use ( &$first ): ?array {
	foreach ( $blocks as $b ) {
		if ( ( $b['blockName'] ?? '' ) === $name ) {
			return $b;
		}
		$in = $first( (array) ( $b['innerBlocks'] ?? array() ), $name );
		if ( $in !== null ) {
			return $in;
		}
	}

	return null;
};
$tag_class = static fn( array $b ): string => preg_match( '/class="([^"]*)"/', (string) ( $b['innerHTML'] ?? '' ), $m ) === 1 ? $m[1] : '';

// ---- Headings ---------------------------------------------------------------------------------------------------------------------
echo "Section titles\n";
$page = $g( '', '', $h( 2, 'mb-4 text-3xl font-bold md:text-5xl', 'font-size:40px;color:#123456', 'Off' ) ) . "\n\n" . $g( '', '', $h( 2, 'mb-3 uppercase fw-800 lh-1', $TITLE, 'Same' ) );
$r    = $run( $page );
$out  = $blocks_of( $r['content'] );
$a    = $first( array( $out[0] ), 'core/heading' );
$expect( 'a title with a style of its own takes the Home\'s: its size, weight, line height, case and font', ( $a['attrs']['className'] ?? '' ) === 'mb-4 uppercase fw-800 lh-1' && str_contains( (string) ( $a['attrs']['dxaiCss'] ?? '' ), 'font-size:clamp(32px,4vw,52px)' ) && str_contains( (string) ( $a['attrs']['dxaiCss'] ?? '' ), "'Barlow Condensed'" ), wp_json_encode( $a['attrs'] ?? null ) );
$expect( '…what is not typography stays: its margin class and its colour', str_contains( (string) ( $a['attrs']['className'] ?? '' ), 'mb-4' ) && str_contains( (string) ( $a['attrs']['dxaiCss'] ?? '' ), 'color:#123456' ) );
$expect( '…and the tag follows: the new classes and the hash of the new CSS, the old ones gone', $tag_class( $a ) === 'wp-block-heading mb-4 uppercase fw-800 lh-1 ' . Style_Hoister::css_class( (string) $a['attrs']['dxaiCss'] ), $tag_class( $a ) );
$expect( 'one that is the Home\'s already is left as it is', ( $r['counts']['heading_scale'] ?? 0 ) === 1, wp_json_encode( $r['counts'] ) );
$again = $run( $r['content'] );
$expect( 'a second pass changes nothing', $again['counts'] === array() && $again['content'] === $r['content'], wp_json_encode( $again['counts'] ) );
$expect( 'parse and serialize give the same bytes', serialize_blocks( parse_blocks( $r['content'] ) ) === $r['content'] );

echo "\nWhat is left alone\n";
$keep = static function ( string $inner, string $why ) use ( $run, $expect, $g ): void {
	$r = $run( $g( '', '', $inner ) );
	$expect( $why, ( $r['counts']['heading_scale'] ?? 0 ) === 0, wp_json_encode( $r['counts'] ) );
};
$keep( $h( 2, 'mb-2 uppercase text-13 fw-800 ls-0-16em', '', 'Eyebrow' ), 'a label set as a heading (13px)' );
$keep( $h( 2, 'text-24 fw-800', '', 'A card' ), 'a card\'s title written as an H2 (24px against 52px)' );
$keep( $h( 2, '', 'font-size:90px', 'Huge' ), 'a title far above the Home\'s' );
$keep( $h( 2, 'text-3xl', '', 'Set by hand', array( 'fontSize' => 'large' ) ), 'a title whose size was chosen in the Typography panel' );
$keep( $h( 3, 'text-3xl font-bold', '', 'Level three' ), 'a heading of level three' );
$r = $run( $g( 'bg-dxai-brand', '', $h( 2, 'text-3xl', '', 'In a footer' ), 'footer' ) );
$expect( 'a title in the footer', ( $r['counts']['heading_scale'] ?? 0 ) === 0 );
$r = $run( $g( '', '', $h( 2, 'text-3xl', '', 'In the nav' ), 'nav' ) );
$expect( '…and in the navigation', ( $r['counts']['heading_scale'] ?? 0 ) === 0 );
$edited = str_replace( 'wp-block-heading text-4xl', 'wp-block-heading', $g( '', '', $h( 2, 'text-4xl font-bold', '', 'Edited' ) ) );
$r      = $run( $edited );
$expect( 'a block whose stored tag is not what its attributes say (edited by hand)', ( $r['counts']['heading_scale'] ?? 0 ) === 0 );
$r = $run( $g( '', '', $h( 2, 'hover:text-5xl mb-2 text-4xl', '', 'Hover' ) ) );
$f = $first( $blocks_of( $r['content'] ), 'core/heading' );
$expect( 'a variant of a state (hover:) is not part of the style: it stays', str_contains( (string) ( $f['attrs']['className'] ?? '' ), 'hover:text-5xl' ), wp_json_encode( $f['attrs'] ?? null ) );

// A colour set in the Colour panel puts its classes after the block's own (has-primary-color has-text-color): they stay where they are.
$coloured = preg_replace( '/(<h2 class="[^"]*)"/', '$1 has-primary-color has-text-color"', $h( 2, 'mb-3 text-4xl', 'font-size:40px', 'Coloured', array( 'textColor' => 'primary' ) ) );
$r        = $run( $g( '', '', $coloured ) );
$f        = $first( $blocks_of( $r['content'] ), 'core/heading' );
$expect( 'a title coloured in the Colour panel takes the Home\'s style too, and keeps the colour\'s classes where they were', ( $r['counts']['heading_scale'] ?? 0 ) === 1 && $tag_class( $f ) === 'wp-block-heading mb-3 uppercase fw-800 lh-1 ' . Style_Hoister::css_class( (string) ( $f['attrs']['dxaiCss'] ?? '' ) ) . ' has-primary-color has-text-color', $tag_class( $f ) );

echo "\nThe page's title\n";
$r = $run( $g( '', '', $h( 1, 'text-5xl font-semibold', 'font-size:70px', 'Another page' ) ) );
$f = $first( $blocks_of( $r['content'] ), 'core/heading' );
$expect( 'a page title has the style of the Home\'s page title', ( $f['attrs']['className'] ?? '' ) === 'uppercase fw-800 lh-1-02' && str_contains( (string) ( $f['attrs']['dxaiCss'] ?? '' ), 'clamp(36px,5vw,64px)' ), wp_json_encode( $f['attrs'] ?? null ) );

echo "\nA design that styles its titles by class\n";
$by_class_home = implode( "\n\n", array( $g( '', '', $h( 2, 'display display-lg mt-8', '' ) ), $g( '', '', $h( 2, 'display display-lg', '' ) ), $g( '', '', $h( 2, 'display display-lg max-w-3xl', '' ) ), $g( '', '', $h( 2, 'display display-md', '' ) ) ) );
wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( $by_class_home ) ) );
$r = $run( $g( '', '', $h( 2, 'mt-4 reveal is-in display display-xl lg:col-span-8', '' ) ) );
$f = $first( $blocks_of( $r['content'] ), 'core/heading' );
$expect( 'the design\'s own style class is the title\'s style: display-xl becomes display-lg', ( $f['attrs']['className'] ?? '' ) === 'mt-4 reveal is-in lg:col-span-8 display display-lg', wp_json_encode( $f['attrs'] ?? null ) );
wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( $home_content ) ) );

echo "\nSwitched off, and without a Home\n";
add_filter( 'dxai_ui_harmony', '__return_false' );
$r = $run( $page );
$expect( 'a design can switch it off', ( $r['counts']['heading_scale'] ?? 0 ) === 0 && ( $r['counts']['section_spacing'] ?? 0 ) === 0 );
remove_filter( 'dxai_ui_harmony', '__return_false' );
$r = Native_Blocks::convert_content( $page, array( 'home' => 0, 'post' => 0 ) );
$expect( 'a page with no design to judge by is left as it is', ( $r['counts']['heading_scale'] ?? 0 ) === 0 && ( $r['counts']['section_spacing'] ?? 0 ) === 0 );

// ---- Sections ---------------------------------------------------------------------------------------------------------------------
echo "\nSections\n";
$sections = implode(
	"\n\n",
	array(
		$g( 'bg-dxai-brand', 'padding-top:clamp(90px,10vw,150px);padding-bottom:clamp(60px,8vw,110px)', $h( 1, '', '', 'Its hero' ) ),
		$g( 'px-0', 'padding-top:clamp(52px,7vw,130px);padding-bottom:clamp(40px,5vw,92px);background:#fff', $p ),
		$g( '', 'padding:clamp(40px,5vw,76px) 24px', $p ),
		$g( 'py-24 mx-auto', '', $p ),
		$g( '', 'padding-top:12px;padding-bottom:12px', $p ),
		$g( 'p-8', '', $p ),
		$g( '', 'padding-top:var(--gap);padding-bottom:var(--gap)', $p ),
		$g( '', 'background:#eee', $p ),
		$g( '', $PAD, $p ),
		$g( '', 'padding-top:90px;padding-bottom:90px', $p, 'footer' ),
	)
);
$r      = $run( $sections );
$blocks = $blocks_of( $r['content'] );
$css    = static fn( int $i ): string => (string) ( $blocks[ $i ]['attrs']['dxaiCss'] ?? '' );
$cls    = static fn( int $i ): string => (string) ( $blocks[ $i ]['attrs']['className'] ?? '' );
$expect( 'a section padded another way takes the Home\'s padding, above and below', str_contains( $css( 1 ), $PAD_T ) && str_contains( $css( 1 ), $PAD_B ) && ! str_contains( $css( 1 ), '130px' ), $css( 1 ) );
$expect( '…and keeps the rest of its own CSS and classes', str_contains( $css( 1 ), 'background:#fff' ) && $cls( 1 ) === 'px-0' );
$expect( 'a shorthand is written as its sides: the top and bottom change, the left and right stay', str_contains( $css( 2 ), $PAD_T ) && str_contains( $css( 2 ), 'padding-right:24px' ) && str_contains( $css( 2 ), 'padding-left:24px' ) && ! str_contains( $css( 2 ), 'padding:clamp' ), $css( 2 ) );
$expect( 'a padding class is replaced by the Home\'s CSS', $cls( 3 ) === 'mx-auto' && str_contains( $css( 3 ), $PAD_T ), $cls( 3 ) . ' | ' . $css( 3 ) );
$expect( 'the tag follows each: the hash of the new CSS', preg_match( '/' . preg_quote( Style_Hoister::css_class( $css( 1 ) ), '/' ) . '/', (string) $blocks[1]['innerHTML'] ) === 1 );
$expect( 'the page\'s hero (it holds the title) is left as it is', str_contains( $css( 0 ), '150px' ) );
$expect( 'a thin bar (12px above and below) is left as it is', str_contains( $css( 4 ), 'padding-top:12px' ) );
$expect( 'a section padded on every side by one class is left as it is', $cls( 5 ) === 'p-8' );
$expect( 'a value that cannot be told (a variable) is left as it is', str_contains( $css( 6 ), 'var(--gap)' ) );
$expect( 'a section with no padding of its own is left as it is', $css( 7 ) === 'background:#eee' );
$expect( 'one that is the Home\'s already is left as it is', $css( 8 ) === $PAD );
$expect( 'a footer is left as it is', str_contains( $css( 9 ), 'padding-top:90px' ), $css( 9 ) );
$expect( 'the engine counts them', ( $r['counts']['section_spacing'] ?? 0 ) === 3, wp_json_encode( $r['counts'] ) );
$again = $run( $r['content'] );
$expect( 'a second pass changes nothing', ( $again['counts']['section_spacing'] ?? 0 ) === 0 && $again['content'] === $r['content'], wp_json_encode( $again['counts'] ) );
$expect( 'parse and serialize give the same bytes', serialize_blocks( parse_blocks( $r['content'] ) ) === $r['content'] );

echo "\nA page written as one group around everything\n";
$framed = $g( '', '', $g( '', 'padding-top:60px;padding-bottom:60px', $p ) . $g( '', 'padding-top:100px;padding-bottom:70px', $p ) . $g( '', 'padding-top:80px;padding-bottom:80px', $p ), 'div', array( 'className' => 'wp-frame' ) );
$r      = $run( $framed );
$expect( 'the sections inside the wrapper are the page\'s sections', ( $r['counts']['section_spacing'] ?? 0 ) === 3, wp_json_encode( $r['counts'] ) );

echo "\nWhen the Home repeats nothing\n";
$odd_titles = implode( "\n\n", array( $g( '', '', $h( 2, 'one', '' ) ), $g( '', '', $h( 2, 'two', '' ) ), $g( '', '', $h( 2, 'three', '' ) ) ) );
wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( $odd_titles ) ) );
$r = $run( $g( '', '', $h( 2, 'four', '' ) ) );
$expect( 'no style of the section titles is the Home\'s when each has one of its own: they are left as they are', ( $r['counts']['heading_scale'] ?? 0 ) === 0, wp_json_encode( $r['counts'] ) );
$odd_pads = implode( "\n\n", array( $g( '', 'padding-top:20px;padding-bottom:30px', $p ), $g( '', 'padding-top:60px;padding-bottom:50px', $p ), $g( '', 'padding-top:80px;padding-bottom:70px', $p ), $g( '', 'padding-top:120px;padding-bottom:110px', $p ), $g( '', 'padding-top:40px;padding-bottom:40px', $p ) ) );
wp_update_post( array( 'ID' => $home_id, 'post_content' => wp_slash( $odd_pads ) ) );
$r      = $run( $g( '', 'padding-top:90px;padding-bottom:60px', $p ) . "\n\n" . $g( '', 'padding-top:10px;padding-bottom:10px', $p ) );
$blocks = $blocks_of( $r['content'] );
$expect( 'sections padded by hand, each its own: the one in the middle is the rhythm (60px above, 50px below)', str_contains( (string) ( $blocks[0]['attrs']['dxaiCss'] ?? '' ), 'padding-top:60px' ) && str_contains( (string) ( $blocks[0]['attrs']['dxaiCss'] ?? '' ), 'padding-bottom:50px' ), (string) ( $blocks[0]['attrs']['dxaiCss'] ?? '' ) );
$expect( '…and a thin bar stays a thin bar', str_contains( (string) ( $blocks[1]['attrs']['dxaiCss'] ?? '' ), 'padding-top:10px' ) );

wp_delete_post( $home_id, true );
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

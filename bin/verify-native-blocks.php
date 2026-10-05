<?php
/**
 * Native_Blocks converters against fixtures: what converts, what must not, and that a second pass changes nothing.
 *
 *   bash bin/wp-php.sh bin/verify-native-blocks.php        (or: wp eval-file bin/verify-native-blocks.php)
 *
 * Runs on a site whose theme registers the blocks a converter writes (american-restoration: amr/link-box, amr/span
 * and the button styles); a converter whose native block is missing here is reported as skipped, not failed. Exit
 * code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Blocks\Native\Converter;

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
$convert = static fn( string $markup ): array => Native_Blocks::convert_content( $markup, array( 'home' => 0, 'post' => 0 ) );
$names   = static fn( string $markup ): array => array_values( array_filter( array_map( static fn( $b ) => (string) $b['blockName'], parse_blocks( $markup ) ) ) );
$have    = array();
foreach ( Native_Blocks::converters() as $c ) {
	$have[ $c->id() ] = true;
}
echo "converters on this site: " . implode( ', ', array_keys( $have ) ) . "\n";

// --- Fixtures: the stored forms the importer writes. ---------------------------------------------------------------
$card = '<!-- wp:dxai-ui/link {"url":"https://example.org/a/","className":"rounded-md d-block text-dxai-ink bg-dxai-neutral-100","hasInner":true} -->'
	. '<a class="wp-block-dxai-ui-link rounded-md d-block text-dxai-ink bg-dxai-neutral-100" href="https://example.org/a/">'
	. '<!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph --></a><!-- /wp:dxai-ui/link -->';
$card_bare = str_replace( array( ' d-block', ' text-dxai-ink' ), '', $card );
$card_data = str_replace( '"hasInner":true', '"hasInner":true,"dxaiData":{"data-x":"1"}', $card );
$span      = '<!-- wp:dxai-ui/text {"className":"text-14 fw-900"} --><span class="text-14 fw-900">Learn more →</span><!-- /wp:dxai-ui/text -->';
$span_data = '<!-- wp:dxai-ui/text {"className":"x","ariaHidden":"true","dxaiData":{"data-plus":""},"attrOrder":["class"]} --><span aria-hidden="true" data-plus="" class="x">+</span><!-- /wp:dxai-ui/text -->';
$box       = '<!-- wp:dxai-ui/box {"className":"px-4 d-flex"} --><div class="px-4 d-flex"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:dxai-ui/box -->';
$box_empty = '<!-- wp:dxai-ui/box {"className":"absolute inset-0","ariaHidden":"true"} --><div aria-hidden="true" class="absolute inset-0"></div><!-- /wp:dxai-ui/box -->';
$box_ctrl  = '<!-- wp:dxai-ui/box {"className":"t","ariaExpanded":"false"} --><div class="t" aria-expanded="false"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:dxai-ui/box -->';
$cta       = static fn( string $cls, string $css, string $text ): string => '<!-- wp:dxai-ui/link {"url":"tel:5551234","text":"' . $text . '","className":"' . $cls . '"' . ( $css !== '' ? ',"dxaiCss":"' . $css . '"' : '' ) . '} -->'
	. '<a class="wp-block-dxai-ui-link ' . $cls . '" href="tel:5551234">' . $text . '</a><!-- /wp:dxai-ui/link -->';
$filled    = $cta( 'rounded-5px px-7 py-4 bg-dxai-accent text-dxai-ink fw-900', '', 'Call now' );
$ghost     = $cta( 'rounded-5px px-6 text-white', 'background:transparent;padding-top:17px;padding-bottom:17px;border:2px solid var(--dxai-neutral-100-a55)', 'Request a callback' );
$navlink   = '<!-- wp:dxai-ui/link {"url":"#about","text":"About","className":"px-3 fw-700","dxaiData":{"data-dxai-set":"null"}} --><a class="wp-block-dxai-ui-link px-3 fw-700" href="#about" data-dxai-set="null">About</a><!-- /wp:dxai-ui/link -->';
$skip      = $cta( 'px-4 py-3 absolute top-0 bg-dxai-accent', 'left:-9999px;z-index:999', 'Skip to content' );

echo "\nLink box\n";
if ( isset( $have['link-box'] ) ) {
	$r = $convert( $card );
	$expect( 'a link around content becomes amr/link-box', $names( $r['content'] ) === array( 'amr/link-box' ), implode( ',', $names( $r['content'] ) ) );
	$expect( 'its inner block is kept', str_contains( $r['content'], '<p>Inside</p>' ) );
	$expect( 'without display and colour of its own it stays a DX Link', $names( $convert( $card_bare )['content'] ) === array( 'dxai-ui/link' ) );
	$expect( 'with data attributes it stays a DX Link', $names( $convert( $card_data )['content'] ) === array( 'dxai-ui/link' ) );
} else {
	echo "  skip  amr/link-box is not registered here\n";
}

echo "\nSpan\n";
if ( isset( $have['span'] ) ) {
	$expect( 'a span of words becomes amr/span', $names( $convert( $span )['content'] ) === array( 'amr/span' ) );
	$expect( 'a span with aria-hidden and data attributes stays a DX Text', $names( $convert( $span_data )['content'] ) === array( 'dxai-ui/text' ) );
} else {
	echo "  skip  amr/span is not registered here\n";
}

echo "\nGroup\n";
$expect( 'a container with blocks becomes core/group', $names( $convert( $box )['content'] ) === array( 'core/group' ) );
$expect( 'an empty box stays a DX Box (an empty group opens as a layout picker)', $names( $convert( $box_empty )['content'] ) === array( 'dxai-ui/box' ) );
$expect( 'a control with aria-expanded stays a DX Box', $names( $convert( $box_ctrl )['content'] ) === array( 'dxai-ui/box' ) );

echo "\nButtons\n";
if ( isset( $have['button'] ) ) {
	$r = $convert( $filled );
	$expect( 'a filled CTA becomes core/buttons', $names( $r['content'] ) === array( 'core/buttons' ), implode( ',', $names( $r['content'] ) ) );
	$expect( 'in the theme\'s primary style', str_contains( $r['content'], 'is-style-primary-button' ) );
	$expect( 'an outlined CTA is the secondary style', str_contains( $convert( $ghost )['content'], 'is-style-secondary-button' ) );
	$r = $convert( $filled . "\n\n" . $ghost );
	$blocks = array_values( array_filter( parse_blocks( $r['content'] ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	$expect( 'two adjacent CTAs are one Buttons block', count( $blocks ) === 1 && $blocks[0]['blockName'] === 'core/buttons' && count( $blocks[0]['innerBlocks'] ) === 2 );
	$expect( 'a menu item (data attributes) stays a DX Link', $names( $convert( $navlink )['content'] ) === array( 'dxai-ui/link' ) );
	$expect( 'the skip link stays a DX Link', $names( $convert( $skip )['content'] ) === array( 'dxai-ui/link' ) );
	$r = $convert( $filled . "\n\n" . $navlink . "\n\n" . $ghost );
	$expect( 'a menu item between two CTAs keeps them apart', count( array_filter( $names( $r['content'] ), static fn( $n ) => $n === 'core/buttons' ) ) === 2 );
	$expect( 'the row stacks on a small screen, as the theme\'s pages do', str_contains( $convert( $filled )['content'], 'sm-flex-column' ) );
	$grow = $cta( 'rounded-5px px-6 text-white', 'flex:1 1 200px;background:transparent;border:2px solid var(--dxai-neutral-100-a55)', 'Request a callback' );
	$expect( 'a button the design sizes along the row (flex: 1 1 200px) does not stack: in a column that basis is a height', ! str_contains( $convert( $grow )['content'], 'sm-flex-column' ) );
	$scroller = static fn( string $inner ): string => '<!-- wp:group {"className":"flex gap-2 overflow-x-auto"} --><div class="wp-block-group flex gap-2 overflow-x-auto">' . $inner . '</div><!-- /wp:group -->';
	$expect( 'links in a row that scrolls sideways stay as the design wrote them', ! str_contains( $convert( $scroller( $filled . "\n\n" . $ghost ) )['content'], 'wp:buttons' ) );
	$expect( 'four links side by side are a strip of chips, not calls to action', ! str_contains( $convert( $filled . "\n\n" . $ghost . "\n\n" . $filled . "\n\n" . $ghost )['content'], 'wp:buttons' ) );
	$expect( 'two links side by side are still one Buttons block', str_contains( $convert( $filled . "\n\n" . $ghost )['content'], 'wp:buttons' ) );
} else {
	echo "  skip  the theme has no button styles here\n";
}

echo "\nLayout\n";
$para  = static fn( string $cls = 'm-0' ): string => '<!-- wp:paragraph {"className":"' . $cls . '"} --><p class="' . $cls . '">A</p><!-- /wp:paragraph -->';
$group = static fn( string $cls, string $inner, string $css = '' ): string => '<!-- wp:group {"className":"' . $cls . '"' . ( $css !== '' ? ',"dxaiCss":"' . $css . '"' : '' ) . '} -->'
	. '<div class="wp-block-group ' . $cls . ( $css !== '' ? ' ' . \DXAI_UI\Compiler\Style_Hoister::css_class( $css ) : '' ) . '">' . $inner . '</div><!-- /wp:group -->';
$attrs_of = static function ( string $markup ): array {
	$b = array_values( array_filter( parse_blocks( $markup ), static fn( $x ) => ! empty( $x['blockName'] ) ) );

	return $b === array() ? array() : (array) $b[0]['attrs'];
};
$row = $group( 'd-flex items-center justify-between gap-6 mb-4', $para() . $para() );
$r   = $convert( $row )['content'];
$a   = $attrs_of( $r );
$expect( 'a row (flex, centred, spaced, gap) becomes a Flex layout', ( $a['layout']['type'] ?? '' ) === 'flex' && ( $a['layout']['justifyContent'] ?? '' ) === 'space-between' && ( $a['layout']['verticalAlignment'] ?? '' ) === 'center' && ( $a['layout']['flexWrap'] ?? '' ) === 'nowrap', json_encode( $a ) );
$expect( 'its gap is written as the block gap, the rest of its classes stay', isset( $a['style']['spacing']['blockGap'] ) && $a['style']['spacing']['blockGap'] !== '' && ( $a['className'] ?? '' ) === 'mb-4', json_encode( $a ) );
$expect( 'and its markup is a plain group', str_contains( $r, '<div class="wp-block-group mb-4">' ), $r );
$col = $convert( $group( 'd-flex flex-col items-start gap-2', $para() . $para() ) )['content'];
$ca  = $attrs_of( $col );
$expect( 'a column becomes a vertical Flex layout', ( $ca['layout']['orientation'] ?? '' ) === 'vertical' && ( $ca['layout']['justifyContent'] ?? '' ) === 'left', json_encode( $ca ) );
$wrap = $attrs_of( $convert( $group( 'd-flex flex-wrap', $para() ) )['content'] );
$expect( 'wrapping and no gap are written out, not left to the theme\'s defaults', ( $wrap['layout']['flexWrap'] ?? '' ) === 'wrap' && ( $wrap['style']['spacing']['blockGap'] ?? '' ) === '0px', json_encode( $wrap ) );
$expect( 'a row whose child has a margin stays as it is', $attrs_of( $convert( $group( 'd-flex gap-4', $para( 'mb-4' ) . $para() ) )['content'] ) === array( 'className' => 'd-flex gap-4' ) );
$expect( 'a row that changes at a breakpoint stays as it is', ! isset( $attrs_of( $convert( $group( 'd-flex sm-flex-column', $para() ) )['content'] )['layout'] ) );
$expect( 'a row with a display set in its own CSS is read from there too', ( $attrs_of( $convert( $group( 'items-center', $para(), 'display:flex;gap:12px' ) )['content'] )['layout']['type'] ?? '' ) === 'flex' );
$wrapper = $group( 'mx-auto my-0 px-7 max-w-1280px', $para() . $para() );
$ba  = $attrs_of( $convert( $wrapper )['content'] );
// The design's container is 1280px; the site's design system stops at the widest the theme has (1200px on american-restoration).
$sys  = \DXAI_UI\Compiler\Utility_Classes::system();
$want = ( $sys !== null ? min( 1280, $sys['max_width'] ) : 1280 ) - 56;
$expect( 'a centred container becomes a Constrained layout of the width its children had, within the site\'s design system', ( $ba['layout']['type'] ?? '' ) === 'constrained' && ( $ba['layout']['contentSize'] ?? '' ) === $want . 'px' && ( $ba['style']['spacing']['blockGap'] ?? '' ) === '0px', json_encode( $ba ) . ' want ' . $want );
$expect( 'and keeps its padding and its other classes', ( $ba['className'] ?? '' ) === 'my-0 px-7', json_encode( $ba ) );
$expect( 'a container with a narrower child stays as it is', ! isset( $attrs_of( $convert( $group( 'mx-auto max-w-1280px px-7', $para( 'max-w-720px m-0' ) ) )['content'] )['layout'] ) );
$expect( 'a container with a child that has a vertical margin stays as it is', ! isset( $attrs_of( $convert( $group( 'mx-auto max-w-1280px px-7', $para( 'mb-3' ) ) )['content'] )['layout'] ) );
$expect( 'a container with a background stays as it is', ! isset( $attrs_of( $convert( $group( 'mx-auto max-w-1280px px-7 bg-dxai-brand', $para() ) )['content'] )['layout'] ) );
$once = $convert( $row . "\n\n" . $box )['content'];
$expect( 'a second pass changes nothing', $convert( $once )['counts'] === array() && $convert( $once )['content'] === $once );
$expect( 'parse and serialize give the same bytes', serialize_blocks( parse_blocks( $once ) ) === $once );

echo "\nIcons\n";
if ( isset( $have['svg'] ) ) {
	// An icon is converted for a design, and a design whose stylesheet is read; a throwaway page stands for one that has none.
	$icon_home    = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'dxai-ui icon test' ) );
	$convert_icon = static fn( string $markup ): array => Native_Blocks::convert_content( $markup, array( 'home' => $icon_home, 'post' => $icon_home ) );
	$known_files  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_dxai_ui_svg_hash', 'fields' => 'ids', 'numberposts' => -1 ) );
	$svg = static function ( array $pairs, string $inner, array $hoisted = array() ): string {
		$attrs = array( 'svgAttrs' => $pairs, 'svgInner' => $inner );
		if ( $hoisted !== array() ) {
			$attrs['dxaiInner'] = $hoisted;
		}
		$tag = '<svg';
		foreach ( $pairs as $p ) {
			$tag .= ' ' . $p[0] . '="' . esc_attr( $p[1] ) . '"';
		}

		return '<!-- wp:dxai-ui/svg ' . wp_json_encode( $attrs ) . ' -->' . $tag . '>' . $inner . '</svg><!-- /wp:dxai-ui/svg -->';
	};
	$icon_pairs = array( array( 'viewBox', '0 0 24 24' ), array( 'width', '24' ), array( 'height', '24' ), array( 'fill', 'none' ), array( 'stroke', '#0a7c4a' ), array( 'stroke-width', '2' ), array( 'aria-hidden', 'true' ) );
	$icon_path  = '<path d="M5 12h14M12 5l7 7-7 7"/>';
	$plain      = $svg( $icon_pairs, $icon_path );
	$r          = $convert_icon( $plain );
	$ia         = $attrs_of( $r['content'] );
	$expect( 'a drawing with its own colours and size becomes core/image', $names( $r['content'] ) === array( 'core/image' ), implode( ',', $names( $r['content'] ) ) );
	$expect( 'pointing at a file in the media library, with the icon marker', ! empty( $ia['id'] ) && str_contains( (string) ( $ia['className'] ?? '' ), 'dxai-icon' ) && str_contains( (string) ( $ia['className'] ?? '' ), 'dxai-part-img' ), json_encode( $ia ) );
	$file = ! empty( $ia['id'] ) ? (string) get_attached_file( (int) $ia['id'] ) : '';
	$body = $file !== '' && is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	$expect( 'the file is a standalone SVG with the drawing and its colours', str_contains( $body, 'xmlns="http://www.w3.org/2000/svg"' ) && str_contains( $body, 'stroke="#0a7c4a"' ) && str_contains( $body, '<path' ) && ! str_contains( $body, 'aria-hidden' ), $body );
	$expect( 'the picture says nothing to a screen reader, as the icon did', str_contains( $r['content'], 'alt=""' ) );
	$expect( 'the same drawing twice is one file', ( $attrs_of( $convert_icon( $plain . "\n\n" . $plain )['content'] )['id'] ?? 0 ) === ( $ia['id'] ?? -1 ) );
	$expect( 'a label on the icon becomes the alt text', str_contains( $convert_icon( $svg( array_merge( $icon_pairs, array( array( 'aria-label', 'Next step' ) ) ), $icon_path ) )['content'], 'alt="Next step"' ) );
	$expect( 'without a design to read the stylesheet of, an icon stays as it is', $names( $convert( $plain )['content'] ) === array( 'dxai-ui/svg' ) );
	$expect( 'a second pass changes nothing', $convert_icon( $r['content'] )['counts'] === array() );
	$expect( 'parse and serialize give the same bytes', serialize_blocks( parse_blocks( $r['content'] ) ) === $r['content'] );

	$sized_by_css = $svg( array( array( 'viewBox', '0 0 24 24' ), array( 'fill', '#111' ), array( 'class', 'dxs-ab12cd' ) ), $icon_path, array( 'dxs-ab12cd' => 'width:2rem;height:2rem' ) );
	$sa           = $attrs_of( $convert_icon( $sized_by_css )['content'] );
	$expect( 'an icon sized by a rule of its own keeps that rule on the picture', ( $sa['dxaiCss'] ?? '' ) !== '' && str_contains( (string) ( $sa['dxaiCss'] ?? '' ), 'width:2rem' ), json_encode( $sa ) );
	$expect( 'and the picture keeps the display the svg had, and no width limit it never had', str_contains( (string) ( $sa['dxaiCss'] ?? '' ), 'display:inline' ) && str_contains( (string) ( $sa['dxaiCss'] ?? '' ), 'max-width:none' ), json_encode( $sa ) );
	$utility      = $svg( array( array( 'viewBox', '0 0 24 24' ), array( 'fill', '#111' ), array( 'class', 'w-20px h-20px mr-2' ) ), $icon_path );
	$expect( 'an icon sized by utilities converts and keeps them', str_contains( (string) ( $attrs_of( $convert_icon( $utility )['content'] )['className'] ?? '' ), 'w-20px h-20px mr-2' ) );

	$stays = array(
		'a colour taken from the text around it (currentColor)' => $svg( array_merge( array_slice( $icon_pairs, 0, 4 ), array( array( 'stroke', 'currentColor' ) ) ), $icon_path ),
		'a colour on a shape that is currentColor'              => $svg( $icon_pairs, '<path stroke="currentColor" d="M1 1h5"/>' ),
		'a colour from a variable of the design'                => $svg( $icon_pairs, '<path stroke="var(--brand)" d="M1 1h5"/>' ),
		'a shape with a class of its own'                       => $svg( $icon_pairs, '<path class="p1" d="M1 1h5"/>' ),
		'a shape with a style'                                  => $svg( $icon_pairs, '<path style="opacity:.5" d="M1 1h5"/>' ),
		'a reference to a symbol (use)'                         => $svg( $icon_pairs, '<use href="#i-arrow"/>' ),
		'an animation'                                          => $svg( $icon_pairs, '<path d="M1 1h5"><animate attributeName="opacity" from="0" to="1" dur="1s"/></path>' ),
		'a gradient'                                            => $svg( $icon_pairs, '<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs><path fill="url(#g)" d="M1 1h5"/>' ),
		'a script'                                              => $svg( $icon_pairs, '<script>alert(1)</script>' ),
		'an event handler'                                      => $svg( $icon_pairs, '<path onload="x()" d="M1 1h5"/>' ),
		'a size nothing says'                                   => $svg( array( array( 'viewBox', '0 0 24 24' ), array( 'fill', '#111' ) ), $icon_path ),
		'a data attribute on the root'                          => $svg( array_merge( $icon_pairs, array( array( 'data-icon', 'arrow' ) ) ), $icon_path ),
		'a class of the design on the root'                     => $svg( array_merge( $icon_pairs, array( array( 'class', 'card-icon' ) ) ), $icon_path ),
		'a style on the root'                                   => $svg( array_merge( $icon_pairs, array( array( 'style', 'color:red' ) ) ), $icon_path ),
		'a drawing stretched to its box (the wave between sections)' => $svg( array( array( 'viewBox', '0 0 1440 80' ), array( 'preserveAspectRatio', 'none' ), array( 'fill', '#0a56b8' ), array( 'class', 'dxs-wave01' ) ), '<path d="M0 80C360 0 1080 0 1440 80z"/>', array( 'dxs-wave01' => 'width:100%;height:80px' ) ),
		'a class the theme does not have (w-6)'               => $svg( array_merge( $icon_pairs, array( array( 'class', 'w-6 h-6' ) ) ), $icon_path ),
		'a label that is a script'                              => $svg( array_merge( $icon_pairs, array( array( 'aria-label', 'x" onerror="y' ) ) ), $icon_path ),
	);
	foreach ( $stays as $label => $markup ) {
		$expect( "$label stays a DX Icon", $names( $convert_icon( $markup )['content'] ) === array( 'dxai-ui/svg' ), implode( ',', $names( $convert_icon( $markup )['content'] ) ) );
	}

	$fresh  = $svg( $icon_pairs, '<path d="M3 3h18M3 9h18M3 15h12"/>' );
	$before = count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_dxai_ui_svg_hash', 'fields' => 'ids', 'numberposts' => -1 ) ) );
	$plan   = Native_Blocks::convert_content( $fresh, array( 'home' => $icon_home, 'post' => $icon_home, 'dry' => 1 ) );
	$expect( 'planning counts the icon', ( $plan['counts']['svg'] ?? 0 ) === 1, json_encode( $plan['counts'] ) );
	$expect( 'and makes no file and no attachment', count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_dxai_ui_svg_hash', 'fields' => 'ids', 'numberposts' => -1 ) ) ) === $before );

	echo "\nIcons: what the design's stylesheet says about svgs\n";
	$read = static fn( string $css ): array => \DXAI_UI\Blocks\Native\Svg_Image::read( $css );
	$m    = $read( '[data-stats] svg{width:32px;height:32px}' );
	$expect( 'a rule for svgs in a place is written again for the picture', $m['safe'] && str_contains( $m['mirror'], '[data-stats] .dxai-icon img{width:32px;height:32px}' ), json_encode( $m ) );
	$m = $read( '.a svg,.b svg{width:1px}' );
	$expect( 'a list of selectors is written for each', $m['safe'] && str_contains( $m['mirror'], '.a .dxai-icon img,.b .dxai-icon img{width:1px}' ), json_encode( $m ) );
	$m = $read( '@media (max-width:600px){.card svg{width:10px}}' );
	$expect( 'inside a media query it stays inside', $m['safe'] && str_contains( $m['mirror'], '@media (max-width:600px){.card .dxai-icon img{width:10px}}' ), json_encode( $m ) );
	$m = $read( '.card svg:hover{opacity:.5}' );
	$expect( 'a hover state is kept', $m['safe'] && str_contains( $m['mirror'], '.card .dxai-icon img:hover{opacity:.5}' ), json_encode( $m ) );
	$m = $read( 'svg{display:block}' );
	$expect( 'the display a rule gives svgs is the picture\'s', $m['safe'] && $m['display'] === 'block', json_encode( $m ) );
	$expect( 'without a rule an svg is inline', $read( '.x{color:red}' ) === array( 'safe' => true, 'display' => 'inline', 'mirror' => '' ) );
	$expect( 'a rule that styles what is inside an svg is a reason to leave every icon inline', ! $read( '.a svg path{fill:red}' )['safe'] );
	$expect( 'so is a colour given to an svg', ! $read( '.a svg{color:red}' )['safe'] && ! $read( '.a svg{fill:red}' )['safe'] && ! $read( '.a svg{stroke:#000}' )['safe'] );
	$expect( 'so is a child selector or a pseudo-element', ! $read( '.a svg>path{x:y}' )['safe'] && ! $read( '.a svg::after{content:"x"}' )['safe'] );
	$expect( 'a rule that names an image as well reaches the picture already and adds nothing', $read( '.a svg,.a img{width:20px}' ) === array( 'safe' => true, 'display' => 'inline', 'mirror' => '' ) );
	$expect( 'comments are not rules', $read( '/* .a svg path{fill:red} */.b{color:red}' )['safe'] );

	// The files this run made are taken away again; the ones that were there are not touched.
	$made = array_diff( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_dxai_ui_svg_hash', 'fields' => 'ids', 'numberposts' => -1 ) ), $known_files );
	foreach ( $made as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	wp_delete_post( $icon_home, true );
} else {
	echo "  skip  the icon converter is not available here (no SVG support)\n";
}

echo "\nSections and leftovers\n";
$tpl   = '<!-- wp:group {"tagName":"template","anchor":"__bundler_thumbnail"} --><template id="__bundler_thumbnail" class="wp-block-group"><!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group --></template><!-- /wp:group -->';
$shell = '<!-- wp:group --><div class="wp-block-group">'
	. '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">About us</h2><!-- /wp:heading --></section><!-- /wp:group -->'
	. '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Our work</h2><!-- /wp:heading --></section><!-- /wp:group --></div><!-- /wp:group -->';
$r = $convert( $tpl . "\n\n" . $shell );
$top = array_values( array_filter( parse_blocks( $r['content'] ), static fn( $x ) => ! empty( $x['blockName'] ) ) );
$expect( 'the export\'s never-rendered template is taken out', count( $top ) === 1 && ( $top[0]['attrs']['tagName'] ?? '' ) !== 'template', json_encode( array_map( static fn( $x ) => $x['blockName'], $top ) ) );
$expect( 'a wrapper around the page\'s parts is not named; its sections are', empty( $top[0]['attrs']['metadata']['name'] ) && ( $top[0]['innerBlocks'][0]['attrs']['metadata']['name'] ?? '' ) === 'About us' && ( $top[0]['innerBlocks'][1]['attrs']['metadata']['name'] ?? '' ) === 'Our work', json_encode( $top[0]['attrs'] ) );
$expect( 'a template with text in it is left alone', str_contains( $convert( '<!-- wp:group {"tagName":"template"} --><template class="wp-block-group"><!-- wp:paragraph --><p>Keep</p><!-- /wp:paragraph --></template><!-- /wp:group -->' )['content'], '<p>Keep</p>' ) );

echo "\nTwo passes, and a stable round trip\n";
foreach ( array( 'card' => $card, 'span' => $span, 'box' => $box, 'filled' => $filled, 'filled+ghost' => $filled . "\n\n" . $ghost, 'row' => $row ) as $label => $markup ) {
	$once  = $convert( $markup )['content'];
	$twice = $convert( $once );
	$expect( "$label: a second pass changes nothing", $twice['counts'] === array() && $twice['content'] === $once );
	$expect( "$label: parse and serialize give the same bytes", serialize_blocks( parse_blocks( $once ) ) === $once );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

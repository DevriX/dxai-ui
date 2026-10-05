<?php
/**
 * A design's text colours are set in the block's Colour panel, not by classes (Native\Text_Color).
 *
 *   bash bin/wp-php.sh bin/verify-text-color.php        (or: wp eval-file bin/verify-text-color.php --user=1)
 *
 * Makes one draft page (the design's Home, with a palette of its own) and removes it; changes the theme's palette and a binding
 * for a moment and puts them back. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Blocks\Native\Text_Color;
use DXAI_UI\Compiler\Color_Usage;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Theme\Theme_Binding;
use DXAI_UI\Theme\Theme_Palette;

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

$home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture text colours' ) );
update_post_meta(
	$home,
	Token_Styles::META,
	array(
		'colors' => array(
			'#022663' => array( 'slug' => 'brand', 'name' => 'Brand', 'value' => '#022663', 'uses' => 10, 'preset' => true ),
			'#4a5566' => array( 'slug' => 'muted', 'name' => 'Muted', 'value' => '#4a5566', 'uses' => 10, 'preset' => true ),
			'#0a56b8' => array( 'slug' => 'sky-38', 'name' => 'Sky', 'value' => '#0a56b8', 'uses' => 10, 'preset' => true ),
		),
		'roles'  => array(),
	)
);
$fin = static function () use ( &$home ): void {
	remove_all_filters( 'wp_theme_json_data_theme' );
	\WP_Theme_JSON_Resolver::clean_cached_data();
	wp_cache_flush_group( 'theme_json' );
	Text_Color::forget();
	if ( $home > 0 ) {
		wp_delete_post( $home, true );
	}
};

$run = static function ( string $content ) use ( &$home ): array {
	Text_Color::forget();

	return Native_Blocks::convert_content( $content, array( 'home' => $home, 'post' => $home ) );
};
$p    = static fn( string $class, string $extra = '' ) => '<!-- wp:paragraph {"className":"' . $class . '"' . $extra . '} --><p class="' . $class . '">Words</p><!-- /wp:paragraph -->';
$only = static function ( string $content ): array {
	foreach ( parse_blocks( $content ) as $b ) {
		if ( ! empty( $b['blockName'] ) ) {
			return $b;
		}
	}

	return array();
};
$same = static fn( string $c ) => serialize_blocks( parse_blocks( $c ) ) === $c;
$var  = static fn( string $t ) => 'var(--dxai-' . $t . '--fg,var(--dxai-' . $t . '))';
// A group with a group's other half: the group holds a paragraph, and a group page needs a group for the engine to look at the content at all.
$wrap = static fn( string $inner ) => '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';

echo "The text blocks\n";
$r = $run( $wrap( $p( 'm-0 text-dxai-muted fw-800' ) ) );
$b = $only( $r['content'] );
$q = $b['innerBlocks'][0] ?? array();
$expect( 'a paragraph with a colour class gets the colour in its Colour panel', ( $q['attrs']['style']['color']['text'] ?? '' ) === $var( 'muted' ) && ( $r['counts']['text-color'] ?? 0 ) === 1, wp_json_encode( $r['counts'] ) . ' ' . wp_json_encode( $q['attrs'] ?? null ) );
$expect( '…and the class is gone, the others stay', ( $q['attrs']['className'] ?? '' ) === 'm-0 fw-800' && ! str_contains( $r['content'], 'text-dxai-muted' ) );
$expect( '…its tag says it: the colour class of the block, the style as the class\'s rule had it', str_contains( $r['content'], '<p class="m-0 fw-800 has-text-color" style="color:' . $var( 'muted' ) . '">Words</p>' ), $r['content'] );
$expect( '…and the markup parses back to itself', $same( $r['content'] ) );
$expect( 'a paragraph with nothing else but the colour has no class left', ( function () use ( $run, $wrap, $p, $only ) {
	$out = $only( $run( $wrap( $p( 'text-dxai-brand' ) ) )['content'] );
	$q   = $out['innerBlocks'][0] ?? array();

	return ! isset( $q['attrs']['className'] ) && str_contains( (string) $q['innerHTML'], '<p class="has-text-color" style=' );
} )() );

$h = '<!-- wp:heading {"level":3,"anchor":"why","className":"uppercase text-dxai-sky-38"} --><h3 id="why" class="wp-block-heading uppercase text-dxai-sky-38">Why</h3><!-- /wp:heading -->';
$r = $run( $wrap( $h ) );
$expect( 'a heading keeps its level and its anchor', str_contains( $r['content'], '<h3 id="why" class="wp-block-heading uppercase has-text-color" style="color:' . $var( 'sky-38' ) . '">Why</h3>' ), $r['content'] );

$g  = '<!-- wp:group {"tagName":"footer","className":"px-0 text-dxai-muted","dxaiCss":"padding-top:52px"} --><footer class="wp-block-group px-0 text-dxai-muted dxs-1gsmrbj"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$r  = $run( $g );
$fh = \DXAI_UI\Compiler\Style_Hoister::css_class( 'padding-top:52px' );
$g  = str_replace( 'dxs-1gsmrbj', $fh, $g );
$r  = $run( $g );
$expect( 'a group keeps its tag and the class of its own CSS', str_contains( $r['content'], '<footer class="wp-block-group px-0 ' . $fh . ' has-text-color" style="color:' . $var( 'muted' ) . '">' ), $r['content'] );
$expect( '…with its CSS still on the block', str_contains( $r['content'], '"dxaiCss":"padding-top:52px"' ) );

$l = '<!-- wp:list {"className":"text-dxai-muted"} --><ul class="wp-block-list text-dxai-muted"><!-- wp:list-item --><li>a</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
$r = $run( $wrap( $l ) );
$expect( 'a list', str_contains( $r['content'], '<ul class="wp-block-list has-text-color" style="color:' . $var( 'muted' ) . '">' ) && $same( $r['content'] ), $r['content'] );
$lo = '<!-- wp:list {"ordered":true,"className":"text-dxai-muted"} --><ol class="wp-block-list text-dxai-muted"><!-- wp:list-item --><li>a</li><!-- /wp:list-item --></ol><!-- /wp:list -->';
$r  = $run( $wrap( $lo ) );
$expect( '…and an ordered one', str_contains( $r['content'], '<ol class="wp-block-list has-text-color" style="color:' ) );

if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'amr/span' ) ) {
	$s = '<!-- wp:amr/span {"className":"fw-800 text-dxai-brand"} --><span class="wp-block-amr-span amr-span fw-800 text-dxai-brand">Go</span><!-- /wp:amr/span -->';
	$r = $run( $wrap( $s ) );
	$expect( 'the theme\'s Span', str_contains( $r['content'], '<span class="wp-block-amr-span amr-span fw-800 has-text-color" style="color:' . $var( 'brand' ) . '">Go</span>' ), $r['content'] );
} else {
	echo "  (this theme has no amr/span: not exercised)\n";
}

echo "\nWhat is left as it is\n";
$left = static function ( string $what, string $block ) use ( $run, $wrap, $expect ): void {
	$r = $run( $wrap( $block ) );
	$expect( $what, ( $r['counts']['text-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'text-dxai-' ), wp_json_encode( $r['counts'] ) );
};
$left( 'a variant of a text class is stronger than a style attribute would let it be', $p( 'text-dxai-muted hover:text-dxai-brand' ) );
$left( 'a breakpoint variant', $p( 'text-dxai-muted md:text-white' ) );
$left( 'two colour classes', $p( 'text-dxai-muted text-dxai-brand' ) );
$left( 'a block whose own CSS sets a colour', $p( 'text-dxai-muted', ',"dxaiCss":"color:red"' ) );
$left( 'a block with a colour of its own already', '<!-- wp:paragraph {"className":"text-dxai-muted","textColor":"primary"} --><p class="text-dxai-muted has-primary-color has-text-color">Words</p><!-- /wp:paragraph -->' );
$left( 'a block with an attribute that would change its tag', '<!-- wp:paragraph {"className":"text-dxai-muted","align":"center"} --><p class="has-text-align-center text-dxai-muted">Words</p><!-- /wp:paragraph -->' );
$left( 'a paragraph someone edited (a class in the tag the attributes do not say)', '<!-- wp:paragraph {"className":"text-dxai-muted"} --><p class="text-dxai-muted extra">Words</p><!-- /wp:paragraph -->' );
$left( 'a paragraph with a style of its own', '<!-- wp:paragraph {"className":"text-dxai-muted"} --><p class="text-dxai-muted" style="margin:0">Words</p><!-- /wp:paragraph -->' );
$left( 'a group with a layout', '<!-- wp:group {"className":"text-dxai-muted","layout":{"type":"flex"}} --><div class="wp-block-group text-dxai-muted is-layout-flex wp-block-group-is-layout-flex"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
$r = $run( $wrap( $p( 'no-colour here' ) ) );
$expect( 'a block with no colour class is not touched', ( $r['counts']['text-color'] ?? 0 ) === 0 && str_contains( $r['content'], '<p class="no-colour here">' ) || ( $r['counts']['text-color'] ?? 0 ) === 0 );
$custom = $run( $wrap( '<!-- wp:dxai-ui/text {"className":"text-dxai-muted"} --><p class="text-dxai-muted">Words</p><!-- /wp:dxai-ui/text -->' ) );
$expect( 'a DX block, which has no Colour panel, is not touched', ( $custom['counts']['text-color'] ?? 0 ) === 0 );

echo "\nThe site's palette\n";
// The brand design's colours as presets of the site (Design_Theme_Json registers them where the theme lets a design bring its palette).
$palette = static function ( array $rows ): void {
	remove_all_filters( 'wp_theme_json_data_theme' );
	add_filter(
		'wp_theme_json_data_theme',
		static function ( $data ) use ( $rows ) {
			return $data->update_with( array( 'version' => 3, 'settings' => array( 'color' => array( 'palette' => $rows ) ) ) );
		},
		1000 // after the theme's own (american-restoration writes its palette from its options at 100)
	);
	\WP_Theme_JSON_Resolver::clean_cached_data();
	wp_cache_flush_group( 'theme_json' );
	Text_Color::forget();
};
$no_theme = ! Theme_Binding::follows( $home );
$palette( array( array( 'slug' => 'brand', 'name' => 'Brand', 'color' => '#022663' ), array( 'slug' => 'muted', 'name' => 'Muted', 'color' => '#999999' ) ) );
$r = $run( $wrap( $p( 'm-0 text-dxai-brand' ) ) );
$q = ( $only( $r['content'] )['innerBlocks'][0] ?? array() );
$expect( 'a colour that is a preset of the site (the same name and the same colour) is the preset: textColor', ( $q['attrs']['textColor'] ?? '' ) === 'brand' && ! isset( $q['attrs']['style'] ), wp_json_encode( $q['attrs'] ?? null ) );
$expect( '…the tag has the preset\'s class and no style', str_contains( $r['content'], '<p class="m-0 has-brand-color has-text-color">Words</p>' ), $r['content'] );
$r = $run( $wrap( $p( 'text-dxai-muted' ) ) );
$q = ( $only( $r['content'] )['innerBlocks'][0] ?? array() );
$expect( 'a preset with the design\'s name and another colour is not the design\'s: the colour as the class had it', ( $q['attrs']['style']['color']['text'] ?? '' ) === $var( 'muted' ) && ! isset( $q['attrs']['textColor'] ) );
$r = $run( $wrap( $p( 'text-dxai-sky-38' ) ) );
$q = ( $only( $r['content'] )['innerBlocks'][0] ?? array() );
$expect( 'a colour the site has no preset for is the colour as the class had it', ( $q['attrs']['style']['color']['text'] ?? '' ) === $var( 'sky-38' ) );
$palette( array() );
remove_all_filters( 'wp_theme_json_data_theme' );
\WP_Theme_JSON_Resolver::clean_cached_data();
	wp_cache_flush_group( 'theme_json' );
Text_Color::forget();

echo "\nA design that follows its theme\n";
if ( ! Theme_Palette::current()['has_palette'] ) {
	echo "  (this theme has no colour settings: nothing follows it)\n";
} else {
	$had = get_post_meta( $home, Theme_Binding::META, true );
	$set = array(
		get_stylesheet() => array(
			'schema' => Theme_Binding::SCHEMA,
			'mode'   => 'follow',
			'design' => array( 'brand' => '#022663', 'muted' => '#4a5566' ),
			'tokens' => array( 'brand' => array( 'to' => 'primary' ), 'muted' => array( 'to' => '' ) ),
			'usage'  => array( 'tokens' => array( 'brand' => array( 'fg' => 3, 'bg' => 0, 'border' => 0 ), 'muted' => array( 'fg' => 3, 'bg' => 0, 'border' => 0 ) ) ),
		),
	);
	update_post_meta( $home, Theme_Binding::META, $set );
	$r = $run( $wrap( $p( 'text-dxai-muted' ) . $p( 'text-dxai-brand' ) ) );
	$kids = $only( $r['content'] )['innerBlocks'] ?? array();
	$expect( 'a colour the theme does not give a class of its own is the colour as the class had it, which is bound by the design\'s token (it follows the theme where the design\'s colours do)', ( $kids[0]['attrs']['style']['color']['text'] ?? '' ) === $var( 'muted' ), wp_json_encode( $kids[0]['attrs'] ?? null ) );
	$expect( 'one that Theme_Class_Swap gives the theme\'s own class or colour is left for it: the class is there to find', str_contains( (string) ( $kids[1]['innerHTML'] ?? '' ), 'text-dxai-brand' ) && ! isset( $kids[1]['attrs']['style'] ), wp_json_encode( $kids[1]['attrs'] ?? null ) );
	$palette( array( array( 'slug' => 'muted', 'name' => 'Muted', 'color' => '#4a5566' ) ) );
	$r = $run( $wrap( $p( 'text-dxai-muted' ) ) );
	$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
	$expect( 'a design that follows its theme keeps its colours the design\'s even when the site has a preset of that name (they are the theme\'s there)', ( $q['attrs']['style']['color']['text'] ?? '' ) === $var( 'muted' ) && ! isset( $q['attrs']['textColor'] ) );
	remove_all_filters( 'wp_theme_json_data_theme' );
	\WP_Theme_JSON_Resolver::clean_cached_data();
	wp_cache_flush_group( 'theme_json' );
	if ( $had === '' || $had === false ) {
		delete_post_meta( $home, Theme_Binding::META );
	} else {
		update_post_meta( $home, Theme_Binding::META, $had );
	}
	Text_Color::forget();
}

echo "\nWhat reads the colours\n";
$page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture text colours page', 'post_content' => wp_slash( $wrap( $p( 'text-dxai-muted' ) ) ) ) );
update_post_meta( $page, \DXAI_UI\Structures\Page_Scope::META, $home );
$before = Color_Usage::scan( $home );
$conv   = Native_Blocks::convert_content( (string) get_post_field( 'post_content', $page ), array( 'home' => $home, 'post' => $page ) );
wp_update_post( array( 'ID' => $page, 'post_content' => wp_slash( $conv['content'] ) ) );
$after = Color_Usage::scan( $home );
$expect( 'the contrast check still sees a colour in the Colour panel as the token it names', ( $before['tokens']['muted']['fg'] ?? 0 ) === ( $after['tokens']['muted']['fg'] ?? -1 ) && ( $after['tokens']['muted']['fg'] ?? 0 ) >= 1, wp_json_encode( array( $before['tokens'] ?? null, $after['tokens'] ?? null ) ) );
wp_delete_post( $page, true );

echo "\nThe engine\n";
$content = $wrap( $p( 'm-0 text-dxai-muted' ) . $h );
$page    = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture text colours engine', 'post_content' => wp_slash( $content ) ) );
update_post_meta( $page, \DXAI_UI\Structures\Page_Scope::META, $home );
Text_Color::forget();
$applied = Native_Blocks::apply( $home );
$now     = (string) get_post_field( 'post_content', $page );
$expect( 'the pass converts the posts of a design', ! str_contains( $now, 'text-dxai-' ) && substr_count( $now, 'has-text-color' ) === 2, $now );
$expect( '…and says what it did', is_array( $applied ) );
$back = Native_Blocks::revert( $home );
$expect( 'and reverting puts the content back as it was, byte for byte', (string) get_post_field( 'post_content', $page ) === $content );
wp_delete_post( $page, true );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * A design's background colours are set in the block's Colour panel, not by classes (Native\Background_Color).
 *
 *   bash bin/wp-php.sh bin/verify-background-color.php        (or: wp eval-file bin/verify-background-color.php --user=1)
 *
 * The other half of verify-text-color.php. Makes one draft page (the design's Home, with a palette of its own) and removes it;
 * changes the theme's palette for a moment and puts it back. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Native\Background_Color;
use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Blocks\Native\Text_Color;
use DXAI_UI\Compiler\Token_Styles;

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

$home = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture background colours' ) );
update_post_meta(
	$home,
	Token_Styles::META,
	array(
		'colors' => array(
			'#022663' => array( 'slug' => 'brand', 'name' => 'Brand', 'value' => '#022663', 'uses' => 10, 'preset' => true ),
			'#f3f4f6' => array( 'slug' => 'neutral-96', 'name' => 'Neutral 96', 'value' => '#f3f4f6', 'uses' => 10, 'preset' => true ),
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
		$home = 0;
	}
};
register_shutdown_function( $fin );

$run = static function ( string $content ) use ( &$home ): array {
	Text_Color::forget();

	return Native_Blocks::convert_content( $content, array( 'home' => $home, 'post' => $home ) );
};
$g    = static fn( string $class, string $inner = '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->', string $extra = '', string $tag = 'div' ): string => '<!-- wp:group {' . ( $tag !== 'div' ? '"tagName":"' . $tag . '",' : '' ) . '"className":"' . $class . '"' . $extra . '} --><' . $tag . ' class="wp-block-group ' . $class . '">' . $inner . '</' . $tag . '><!-- /wp:group -->';
$only = static function ( string $content ): array {
	foreach ( parse_blocks( $content ) as $b ) {
		if ( ! empty( $b['blockName'] ) ) {
			return $b;
		}
	}

	return array();
};
$same = static fn( string $c ) => serialize_blocks( parse_blocks( $c ) ) === $c;
$var  = static fn( string $t ) => 'var(--dxai-' . $t . ')';
// A group page needs a group for the engine to look at the content at all: the outer group wraps, the inner one is the fixture.
$wrap = static fn( string $inner ) => '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';

echo "The blocks\n";
$conv = array_map( static fn( $c ) => $c->id(), Native_Blocks::converters() );
$expect( 'the converter runs after the text colours and before the sizes', in_array( 'background-color', $conv, true ) && array_search( 'background-color', $conv, true ) > array_search( 'text-color', $conv, true ) && array_search( 'background-color', $conv, true ) < array_search( 'sizes', $conv, true ), implode( ',', $conv ) );
$r = $run( $wrap( $g( 'px-6 bg-dxai-neutral-96 py-10' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a group with a background class gets the colour in its Colour panel, as the class had it', ( $q['attrs']['style']['color']['background'] ?? '' ) === $var( 'neutral-96' ) && ! isset( $q['attrs']['backgroundColor'] ), wp_json_encode( $q['attrs'] ?? null ) );
$expect( '…the class is gone, the others stay', ( $q['attrs']['className'] ?? '' ) === 'px-6 py-10' && ! str_contains( $r['content'], 'bg-dxai-' ) );
$expect( '…its tag says it: has-background and the style', str_contains( $r['content'], '<div class="wp-block-group px-6 py-10 has-background" style="background-color:' . $var( 'neutral-96' ) . '">' ), $r['content'] );
$expect( '…and the markup parses back to itself', $same( $r['content'] ) );
$expect( '…and it is counted', ( $r['counts']['background-color'] ?? 0 ) === 1 );
$r = $run( $wrap( $g( 'bg-dxai-brand' ) ) );
$expect( 'a group with nothing else but the colour has no class left', str_contains( $r['content'], '<div class="wp-block-group has-background" style="background-color:' . $var( 'brand' ) . '">' ) && ! isset( $only( $r['content'] )['innerBlocks'][0]['attrs']['className'] ), $r['content'] );
$r = $run( $wrap( $g( 'bg-dxai-brand text-dxai-sky-38' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a group with a text colour too gets both settings (the text colour first, the background after)', ( $q['attrs']['style']['color']['background'] ?? '' ) === $var( 'brand' ) && ( $q['attrs']['style']['color']['text'] ?? '' ) === 'var(--dxai-sky-38--fg,var(--dxai-sky-38))' && str_contains( $r['content'], 'has-text-color' ) && str_contains( $r['content'], 'has-background' ), wp_json_encode( $q['attrs'] ?? null ) . ' ' . $r['content'] );
// A block with CSS of its own carries the hoisted class of that CSS on its tag (Style_Hoister), as the importer writes it.
$fh = \DXAI_UI\Compiler\Style_Hoister::css_class( 'padding-top:52px' );
$r  = $run( $wrap( '<!-- wp:group {"tagName":"section","className":"px-0 bg-dxai-brand","dxaiCss":"padding-top:52px"} --><section class="wp-block-group px-0 bg-dxai-brand ' . $fh . '"><!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph --></section><!-- /wp:group -->' ) );
$expect( 'a section keeps its tag and the class of its own CSS', str_contains( $r['content'], '<section class="wp-block-group px-0 ' . $fh . ' has-background" style="background-color:' . $var( 'brand' ) . '">' ) && str_contains( $r['content'], '"dxaiCss":"padding-top:52px"' ), $r['content'] );
$r = $run( $wrap( '<!-- wp:group {"className":"bg-dxai-brand","style":{"color":{"text":"var(--dxai-sky-38--fg,var(--dxai-sky-38))"}}} --><div class="wp-block-group bg-dxai-brand has-text-color" style="color:var(--dxai-sky-38--fg,var(--dxai-sky-38))"><!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a group whose text colour is set already (the other converter\'s) gets the background beside it, both kept on the tag', ( $q['attrs']['style']['color']['text'] ?? '' ) === 'var(--dxai-sky-38--fg,var(--dxai-sky-38))' && ( $q['attrs']['style']['color']['background'] ?? '' ) === $var( 'brand' ) && str_contains( $r['content'], '<div class="wp-block-group has-text-color has-background" style="color:var(--dxai-sky-38--fg,var(--dxai-sky-38));background-color:' . $var( 'brand' ) . '">' ), $r['content'] );
$r = $run( $wrap( '<!-- wp:group {"className":"bg-dxai-brand","style":{"spacing":{"padding":{"top":"1rem"}}}} --><div class="wp-block-group bg-dxai-brand" style="padding-top:1rem"><!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph --></div><!-- /wp:group -->' ) );
$expect( 'a block with a style someone wrote (a padding) is left as the class', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'bg-dxai-brand' ) );
$r = $run( $wrap( $g( 'bg-dxai-brand', '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->', ',"layout":{"type":"constrained"}' ) ) );
$expect( 'a group with a layout setting is converted too: the layout adds its classes at render, not to the stored tag', ( $only( $r['content'] )['innerBlocks'][0]['attrs']['layout']['type'] ?? '' ) === 'constrained' && str_contains( $r['content'], 'has-background' ), $r['content'] );
$cols = '<!-- wp:columns {"className":"bg-dxai-neutral-96"} --><div class="wp-block-columns bg-dxai-neutral-96"><!-- wp:column {"className":"bg-dxai-brand"} --><div class="wp-block-column bg-dxai-brand"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
$r    = $run( $wrap( $cols ) );
$expect( 'the columns and a column', str_contains( $r['content'], '<div class="wp-block-columns has-background" style="background-color:' . $var( 'neutral-96' ) . '">' ) && str_contains( $r['content'], '<div class="wp-block-column has-background" style="background-color:' . $var( 'brand' ) . '">' ) && $same( $r['content'] ), $r['content'] );

echo "\nWhat is left as it is\n";
foreach ( array(
	'a variant of a background class (a state, a breakpoint)' => 'bg-dxai-brand hover:bg-dxai-sky-38',
	'another background with it (a gradient, a picture)'       => 'bg-dxai-brand bg-gradient-to-r',
	'two colours'                                              => 'bg-dxai-brand bg-dxai-sky-38',
	'a setting already there'                                  => 'bg-dxai-brand has-background',
) as $what => $class ) {
	$r = $run( $wrap( $g( $class ) ) );
	$expect( $what . ' is left as the class', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'class="wp-block-group ' . $class . '"' ), $r['content'] );
}
$r = $run( $wrap( $g( 'bg-dxai-brand', '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->', ',"dxaiCss":"background-image:url(x.png)"' ) ) );
$expect( 'a block whose own CSS names a background is left as the class', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'bg-dxai-brand' ) );
$r = $run( $wrap( $g( 'bg-dxai-brand', '<!-- wp:paragraph --><p>Words</p><!-- /wp:paragraph -->', ',"align":"full"' ) ) );
$expect( 'a block with an attribute this does not move is left as the class', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'bg-dxai-brand' ) );
$r = $run( $wrap( '<!-- wp:paragraph {"className":"bg-dxai-brand"} --><p class="bg-dxai-brand">Words</p><!-- /wp:paragraph -->' ) );
$expect( 'a paragraph has no background setting the design can use this way: left as the class', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], 'bg-dxai-brand' ) );
$r = $run( $wrap( $g( 'px-6 py-10' ) ) );
$expect( 'a block with no background class is not touched', ( $r['counts']['background-color'] ?? 0 ) === 0 && str_contains( $r['content'], '<div class="wp-block-group px-6 py-10">' ) );

echo "\nThe site's palette\n";
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
$palette( array( array( 'slug' => 'brand', 'name' => 'Brand', 'color' => '#022663' ), array( 'slug' => 'neutral-96', 'name' => 'Neutral', 'color' => '#999999' ) ) );
$r = $run( $wrap( $g( 'bg-dxai-brand' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a colour that is a preset of the site (the same name and the same colour) is the preset: backgroundColor', ( $q['attrs']['backgroundColor'] ?? '' ) === 'brand' && ! isset( $q['attrs']['style'] ), wp_json_encode( $q['attrs'] ?? null ) );
$expect( '…the tag has the preset\'s class and no style', str_contains( $r['content'], '<div class="wp-block-group has-brand-background-color has-background">' ), $r['content'] );
$r = $run( $wrap( $g( 'bg-dxai-neutral-96' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a preset with the design\'s name and another colour is not the design\'s: the colour as the class had it', ( $q['attrs']['style']['color']['background'] ?? '' ) === $var( 'neutral-96' ) && ! isset( $q['attrs']['backgroundColor'] ) );
$r = $run( $wrap( $g( 'bg-dxai-sky-38' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( 'a colour the site has no preset for is the colour as the class had it', ( $q['attrs']['style']['color']['background'] ?? '' ) === $var( 'sky-38' ) );
remove_all_filters( 'wp_theme_json_data_theme' );
\WP_Theme_JSON_Resolver::clean_cached_data();
wp_cache_flush_group( 'theme_json' );
Text_Color::forget();

echo "\nThe brand design's presets reach theme.json after a theme's own filter\n";
// A theme that writes its palette from its options on the same hook (American Restoration, priority 100) replaces the palette whole:
// what the plugin added before it was gone. Design_Theme_Json registers after it now. The brand option is put back whatever happens.
$brand_was = get_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION );
$put_back  = static function () use ( $brand_was ): void {
	if ( is_array( $brand_was ) ) {
		update_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION, $brand_was, false );
	} else {
		delete_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION );
	}
	\DXAI_UI\Theme\Design_Theme_Json::flush();
};
register_shutdown_function( $put_back );
( new \DXAI_UI\Theme\Design_Theme_Json() )->register();
add_filter(
	'wp_theme_json_data_theme',
	static fn( $data ) => $data->update_with( array( 'version' => 3, 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'theme-primary', 'name' => 'Primary', 'color' => '#016BC4' ) ) ) ) ) ),
	100
);
update_option( \DXAI_UI\Theme\Design_Theme_Json::OPTION, array( 'tokens' => array( 'brand' => '#022663', 'accent' => '#0A56B8' ), 'page_id' => $home, 'source' => 'fixture-background.zip' ), false );
\DXAI_UI\Theme\Design_Theme_Json::flush();
$slugs = array_column( (array) ( wp_get_global_settings( array( 'color', 'palette' ) )['theme'] ?? array() ), 'slug' );
$expect( 'the theme\'s own palette and the brand design\'s presets (its palette\'s, as the Home records them) are both in theme.json', in_array( 'theme-primary', $slugs, true ) && in_array( 'brand', $slugs, true ) && in_array( 'neutral-96', $slugs, true ), wp_json_encode( $slugs ) );
$r = $run( $wrap( $g( 'bg-dxai-brand' ) ) );
$q = $only( $r['content'] )['innerBlocks'][0] ?? array();
$expect( '…so the design\'s brand colour is a preset the converter binds to, on such a theme too', ( $q['attrs']['backgroundColor'] ?? '' ) === 'brand', wp_json_encode( $q['attrs'] ?? null ) );
$put_back();
remove_all_filters( 'wp_theme_json_data_theme' );
\WP_Theme_JSON_Resolver::clean_cached_data();
wp_cache_flush_group( 'theme_json' );
Text_Color::forget();

echo "\nThe engine\n";
$content = $wrap( $g( 'px-6 bg-dxai-neutral-96' ) );
$page    = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture background colours engine', 'post_content' => wp_slash( $content ) ) );
update_post_meta( $page, \DXAI_UI\Structures\Page_Scope::META, $home );
Text_Color::forget();
$applied = Native_Blocks::apply( $home );
$now     = (string) get_post_field( 'post_content', $page );
$expect( 'the pass converts the posts of a design', ! str_contains( $now, 'bg-dxai-' ) && str_contains( $now, 'has-background' ), $now );
$back = Native_Blocks::revert( $home );
$expect( 'and reverting puts the content back as it was, byte for byte', (string) get_post_field( 'post_content', $page ) === $content );
wp_delete_post( $page, true );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

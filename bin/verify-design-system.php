<?php
/**
 * The classes the plugin makes for a max-width or a padding keep within the site's design system.
 *
 *   bash bin/wp-php.sh bin/verify-design-system.php        (or: wp eval-file bin/verify-design-system.php --user=1)
 *
 * The theme's utilities (assets/src/sass/base/_utilities.scss of american-restoration) have a `max-w-<n>px` for every multiple of 5 up to
 * 1200 and a spacing scale that ends at 24.5 (98px). Where the theme has no class the plugin adds its own (`max-w-1280px`): a width the
 * site does not use anywhere else. Reads only; nothing is written. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Utility_Classes;

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
$fit = static fn( string $css ): string => Utility_Classes::fit_system( $css );

echo "The bounds\n";
$sys = Utility_Classes::system();
$expect( 'are read from the theme\'s classes: 1200px wide, 98px of padding', $sys !== null && $sys['max_width'] === 1200 && abs( $sys['padding'] - 98.0 ) < 0.001, wp_json_encode( $sys ) );

echo "\nmax-width\n";
$expect( 'a width above the widest the theme has is that width', $fit( 'max-width:1280px' ) === 'max-width:1200px' );
$expect( '…whatever the width', $fit( 'max-width:1500px' ) === 'max-width:1200px' && $fit( 'max-width:1240px' ) === 'max-width:1200px' );
$expect( 'a width the theme has is left as it is, byte for byte', $fit( 'max-width:1200px' ) === 'max-width:1200px' && $fit( 'max-width: 640px ; margin:0 auto' ) === 'max-width: 640px ; margin:0 auto' );
$expect( 'a width off the theme\'s grid of 5px goes to the grid', $fit( 'max-width:1186px' ) === 'max-width:1185px' && $fit( 'max-width:743px' ) === 'max-width:745px' );
$expect( '!important is kept', $fit( 'max-width:1280px !important' ) === 'max-width:1200px !important' );
$expect( 'what is not a plain px length is not touched: %, ch, none, clamp(), rem', $fit( 'max-width:100%' ) === 'max-width:100%' && $fit( 'max-width:56ch' ) === 'max-width:56ch' && $fit( 'max-width:none' ) === 'max-width:none' && $fit( 'max-width:clamp(300px,50vw,1400px)' ) === 'max-width:clamp(300px,50vw,1400px)' && $fit( 'max-width:80rem' ) === 'max-width:80rem' );
$expect( 'a tiny width is not rounded to nothing', $fit( 'max-width:3px' ) === 'max-width:3px' );
$expect( 'the declarations around it keep their order', $fit( 'margin:0 auto;max-width:1280px;color:red' ) === 'margin:0 auto;max-width:1200px;color:red' );

echo "\npadding\n";
$expect( 'a side above the largest padding the theme has is that padding', $fit( 'padding-top:120px' ) === 'padding-top:98px' && $fit( 'padding-bottom:110px' ) === 'padding-bottom:98px' );
$expect( '…in a shorthand, side by side', $fit( 'padding:110px 24px' ) === 'padding:98px 24px' && $fit( 'padding:0 120px' ) === 'padding:0 98px' && $fit( 'padding:120px 130px 26px 0' ) === 'padding:98px 98px 26px 0' );
$expect( 'a padding the theme has is left as it is', $fit( 'padding:98px' ) === 'padding:98px' && $fit( 'padding:26px 24px' ) === 'padding:26px 24px' && $fit( 'padding-left:13px' ) === 'padding-left:13px' );
$expect( '!important is kept', $fit( 'padding:110px 0 !important' ) === 'padding:98px 0 !important' );
$expect( 'what is not a plain px length is not touched: clamp(), %, rem, a variable', $fit( 'padding:clamp(56px,7vw,140px) 0' ) === 'padding:clamp(56px,7vw,140px) 0' && $fit( 'padding:5%' ) === 'padding:5%' && $fit( 'padding:8rem' ) === 'padding:8rem' && $fit( 'padding:var(--pad)' ) === 'padding:var(--pad)' );
$expect( 'margin is not padding', $fit( 'margin:120px 0' ) === 'margin:120px 0' && $fit( 'margin-top:200px' ) === 'margin-top:200px' );

echo "\nThe classes\n";
$m = Utility_Classes::match( 'max-width:1280px;margin-left:auto;margin-right:auto' );
$expect( 'a 1280px container is the theme\'s max-w-1200px, not a new max-w-1280px', in_array( 'max-w-1200px', $m['classes'], true ) && ! in_array( 'max-w-1280px', $m['classes'], true ) && $m['remainder'] === '', wp_json_encode( $m ) );
$m = Utility_Classes::match( 'max-width:1500px' );
$expect( '…and a wider one', $m['classes'] === array( 'max-w-1200px' ), wp_json_encode( $m ) );
$m = Utility_Classes::match( 'padding-top:110px;padding-bottom:110px' );
$expect( 'a padding above the scale is the scale\'s largest class', in_array( 'py-24-5', $m['classes'], true ) && $m['remainder'] === '', wp_json_encode( $m ) );
$m = Utility_Classes::match( 'max-width:1186px' );
$expect( 'a width off the grid is the theme\'s class on the grid', $m['classes'] === array( 'max-w-1185px' ), wp_json_encode( $m ) );
$m = Utility_Classes::match( 'max-width:56ch;color:red' );
$expect( 'what has no class stays in the block\'s own CSS, as it was', $m['classes'] === array() && $m['remainder'] === 'max-width:56ch;color:red', wp_json_encode( $m ) );
$m = Utility_Classes::match( 'font-size:14px;max-width:640px' );
$expect( 'a value the theme has is its class, as before', in_array( 'max-w-640px', $m['classes'], true ) && in_array( 'text-14', $m['classes'], true ), wp_json_encode( $m ) );

echo "\nWhat is already on a page\n";
$expect( 'a class of an earlier import (max-w-1280px) still has its rule: the page is not changed under its owner', Utility_Classes::family( 'max-w-1280px' ) !== null && ( Utility_Classes::family( 'max-w-1280px' )['value'] ?? '' ) === '1280px' );
$expect( 'the plugin\'s other families are as before (a 58px height, a 14.5px size, a 900 weight)', Utility_Classes::family( 'h-58px' ) !== null && Utility_Classes::family( 'text-14-5' ) !== null && Utility_Classes::family( 'fw-900' ) !== null );

echo "\nThe pass over a design imported before\n";
$run  = static fn( string $content ) => \DXAI_UI\Blocks\Native\Native_Blocks::convert_content( $content, array( 'home' => 0, 'post' => 0 ) );
$wrap = static fn( string $inner ) => '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
$box  = static fn( string $class, string $extra = '', string $css = '' ) => '<!-- wp:group {"className":"' . $class . '"' . $extra . '} --><div class="wp-block-group ' . $class . ( $css !== '' ? ' ' . \DXAI_UI\Compiler\Style_Hoister::css_class( $css ) : '' ) . '"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
// A group with a layout of its own is not one the Layout converter takes (it would turn a centred container into a constrained layout): these are about the classes.
$lay = ',"layout":{"type":"default"}';
$r = $run( $wrap( $box( 'max-w-1280px mx-auto px-6', $lay ) ) );
$expect( 'a max-w-1280px class is the theme\'s max-w-1200px, in the attributes and in the tag', str_contains( $r['content'], '"className":"max-w-1200px mx-auto px-6"' ) && str_contains( $r['content'], '<div class="wp-block-group max-w-1200px mx-auto px-6">' ) && ( $r['counts']['sizes'] ?? 0 ) === 1, wp_json_encode( $r['counts'] ) . ' ' . $r['content'] );
$r = $run( $wrap( $box( 'max-w-1186px', $lay ) ) );
$expect( 'a width off the theme\'s grid goes to its class on the grid', str_contains( $r['content'], 'max-w-1185px' ) && ! str_contains( $r['content'], 'max-w-1186px' ) );
$r = $run( $wrap( $box( 'max-w-1200px mx-auto', $lay ) . $box( 'max-w-640px', $lay ) ) );
$expect( 'a class the theme has is left alone (the page comes back byte for byte)', ( $r['counts']['sizes'] ?? 0 ) === 0 && $r['content'] === $wrap( $box( 'max-w-1200px mx-auto', $lay ) . $box( 'max-w-640px', $lay ) ) );
$r = $run( $wrap( $box( 'max-w-1280px mx-auto px-6' ) ) );
$expect( 'a centred container of 1280px that the Layout converter takes is a constrained layout of the site\'s 1200px, less its padding', str_contains( $r['content'], '"contentSize":"1152px"' ) && ! str_contains( $r['content'], '1232px' ), $r['content'] );
$r = $run( $wrap( $box( 'max-w-1200px mx-auto px-6' ) ) );
$expect( '…and one of the theme\'s own width is as before (1200px less its padding)', str_contains( $r['content'], '"contentSize":"1152px"' ), $r['content'] );
$css = 'padding:110px 24px;color:red';
$r   = $run( $wrap( $box( 'rounded', ',"dxaiCss":"' . $css . '"', $css ) ) );
$new = 'padding:98px 24px;color:red';
$expect( 'a block\'s own padding above the scale is fitted, and its dxs- class follows (the class is the hash of the CSS)', str_contains( $r['content'], '"dxaiCss":"' . $new . '"' ) && str_contains( $r['content'], \DXAI_UI\Compiler\Style_Hoister::css_class( $new ) ) && ! str_contains( $r['content'], \DXAI_UI\Compiler\Style_Hoister::css_class( $css ) ), $r['content'] );
$r = $run( $wrap( '<!-- wp:group {"className":"max-w-1280px"} --><div class="wp-block-group">x</div><!-- /wp:group -->' ) );
$expect( 'a block whose tag does not carry the class its attributes name (it was edited) is left as it is', ( $r['counts']['sizes'] ?? 0 ) === 0 && str_contains( $r['content'], 'max-w-1280px' ) );
$r = $run( $wrap( '<!-- wp:paragraph {"className":"max-w-1500px"} --><p class="max-w-1500px">x</p><!-- /wp:paragraph -->' ) );
$expect( 'any block with a class list, a paragraph too', str_contains( $r['content'], '<p class="max-w-1200px">' ) && str_contains( $r['content'], '"className":"max-w-1200px"' ), $r['content'] );

echo "\nSwitched off\n";
add_filter( 'dxai_ui_design_system', '__return_null' );
$expect( 'a site can opt out: the value is kept, and the plugin makes its own class', $fit( 'max-width:1280px' ) === 'max-width:1280px' && in_array( 'max-w-1280px', Utility_Classes::match( 'max-width:1280px' )['classes'], true ) );
remove_filter( 'dxai_ui_design_system', '__return_null' );
add_filter( 'dxai_ui_design_system', static fn( $s ) => array( 'max_width' => 1400, 'padding' => 120 ) );
$expect( '…or set its own bounds', $fit( 'max-width:1500px;padding:130px 0' ) === 'max-width:1400px;padding:120px 0' );
remove_all_filters( 'dxai_ui_design_system' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * Converter quality: nested phrasing islands + restyle ladder helpers.
 *
 * usage: bin/wp-php.sh bin/smoke-converter-quality.php
 * exit:  0 pass, 1 fail, 2 bad usage
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Html_To_Blocks;
use DXAI_UI\Structures\Live_Page_Restyler;
use DXAI_UI\Verification\Import_Audit;

$fail = 0;
$ok   = 0;
$assert = static function ( bool $cond, string $msg ) use ( &$fail, &$ok ): void {
	if ( $cond ) {
		echo "OK  {$msg}\n";
		++$ok;
	} else {
		echo "FAIL {$msg}\n";
		++$fail;
	}
};

// Nested chip with phrase child + loose text must not stay dxai-ui/html.
$chip_html = '<span class="rv-chip"><strong>Legal</strong> Liability</span>';
$chip      = ( new Html_To_Blocks() )->convert( $chip_html );
$assert( ! str_contains( $chip, 'wp:dxai-ui/html' ), 'nested chip is not a raw-HTML island' );
$assert(
	str_contains( $chip, 'wp:dxai-ui/text' ) || str_contains( $chip, 'wp:dxai-ui/box' ),
	'nested chip becomes dxai-ui/text or dxai-ui/box'
);
$assert( str_contains( $chip, 'Legal' ) && str_contains( $chip, 'Liability' ), 'nested chip keeps copy' );

// Pure nested phrasing without loose text (text_run whitespace-normalized path).
$nested = ( new Html_To_Blocks() )->convert( '<span class="x"><em>Emphasis</em><strong>Bold</strong></span>' );
$assert( ! str_contains( $nested, 'wp:dxai-ui/html' ), 'nested em/strong span is not an island' );

// Invented-color pressure: hexes outside allow-list.
$pressure = Live_Page_Restyler::invented_color_pressure(
	'style="color:#FF00AA;background:#0284C7"',
	array( 'allowed_colors' => array( '#0284C7', '#FFFFFF' ) ),
	false
);
$assert( $pressure >= 1, 'invented hex counted' );
$pressure_ok = Live_Page_Restyler::invented_color_pressure(
	'style="color:#0284C7;background:#FFFFFF"',
	array( 'allowed_colors' => array( '#0284C7', '#FFFFFF' ) ),
	false
);
$assert( $pressure_ok === 0, 'palette hexes not invented' );

// home_guide: denser role recipes, no mid-chunk slash truncation marker.
$guide = Live_Page_Restyler::home_guide(
	array(
		array(
			'type'        => 'section',
			'title'       => 'Hero',
			'source_html' => '<section style="padding: 64px 24px; background: #111111; min-height: 420px"><h1 style="font-size: 48px; font-weight: 700; color: #FFFFFF">Title</h1><a style="background: #F2A34A; color: #111; padding: 12px 24px; border-radius: 999px" href="#">Call</a></section>',
		),
		array(
			'type'        => 'section',
			'title'       => 'Cards',
			'source_html' => '<div style="box-shadow: 0 8px 24px rgba(0,0,0,.12); border-radius: 16px; padding: 24px" class="card"><p>Card</p></div>',
		),
	),
	'',
	'Home'
);
$assert( is_array( $guide['style_recipes'] ?? null ), 'style_recipes present' );
$assert( count( $guide['style_recipes'] ) <= 5, 'style_recipes capped to role samples' );
$assert( ! str_contains( (string) ( $guide['home_html'] ?? '' ), '<!-- truncated -->' ), 'home_html not mid-truncated' );

// Site_From_Menu source: design-fallback before pass1.
$menu_src = (string) file_get_contents( dirname( __DIR__ ) . '/src/Structures/Site_From_Menu.php' );
$pos_fb   = strpos( $menu_src, "'design-fallback'" );
$pos_p1   = strpos( $menu_src, "restyle_engine'] = 'pass1'" );
$assert( $pos_fb !== false && $pos_p1 !== false && $pos_fb < $pos_p1, 'design-fallback ordered before pass1 in Site_From_Menu' );

// Import_Audit soft-fail threshold: >30% opaque becomes a finding.
$auditor = new Import_Audit();
$ref     = new ReflectionClass( $auditor );
$method  = $ref->getMethod( 'editability_pressure' );
$method->setAccessible( true );
$low = $method->invoke(
	$auditor,
	'<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->'
	. '<!-- wp:dxai-ui/html --><div>x</div><!-- /wp:dxai-ui/html -->'
	. '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->'
	. '<!-- wp:paragraph --><p>c</p><!-- /wp:paragraph -->'
);
$assert( $low['finding'] === null && is_array( $low['notice'] ), '25% opaque is notice only' );
$high = $method->invoke(
	$auditor,
	'<!-- wp:dxai-ui/html --><div>1</div><!-- /wp:dxai-ui/html -->'
	. '<!-- wp:dxai-ui/html --><div>2</div><!-- /wp:dxai-ui/html -->'
	. '<!-- wp:paragraph --><p>ok</p><!-- /wp:paragraph -->'
);
$assert( is_array( $high['finding'] ) && $high['notice'] === null, '>30% opaque soft-fails editability' );

echo "\n{$ok} ok, {$fail} fail\n";
exit( $fail === 0 ? 0 : 1 );

<?php
/**
 * A page composed from its Home's sections has its words written by an AI place by place, in each place's measure
 * (Pages\Slot_Writer; docs/PLAN-NETWORK-PAGES.md, 11.2).
 *
 *   bash bin/wp-php.sh bin/verify-slot-writer.php        (or: wp eval-file bin/verify-slot-writer.php --user=1)
 *
 * A Home's hero like H2O Away's — a line over the title, the title, a sentence under it, a list of claims with ticks, the company's
 * figures — and an old page with a title and three paragraphs and no line over its title. Composed for an AI, every place keeps the
 * Home's words as its measure and the line over the title is kept to be written; the AI (a stub here: nothing is asked of a
 * provider) is shown each place with its length, the old page's text and the site's facts; what it answers goes in its place, what
 * runs over the measure is sent back once to be shortened, a place it leaves empty goes; the figures, the claims and the calls are
 * not its to write. Without an AI, or when it fails, the page is what the composer made before: the line over the title gone, the
 * old page's words in place. No mark of the work is ever left on the page. The crawl of an old site (Site_From_Menu) asks the AI
 * chosen in Settings, unless a site says no. Makes one page (a Home) and deletes it. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Page_Composer;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Slot_Writer;

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

$fig  = static fn( string $n, string $l ): string => '<!-- wp:group {"className":"fig"} --><div class="wp-block-group fig"><!-- wp:paragraph {"className":"num"} --><p class="num">' . $n . '</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"lab"} --><p class="lab">' . $l . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$hero = parse_blocks(
	'<!-- wp:group {"tagName":"section","className":"hero"} --><section class="wp-block-group hero"><!-- wp:group {"className":"copy"} --><div class="wp-block-group copy">'
	. '<!-- wp:paragraph {"className":"eyebrow"} --><p class="eyebrow">24/7 Emergency Response · Olympia, WA</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Water, Fire &amp; Mold Damage Restoration in Olympia, WA</h1><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Third-generation insurance contractors serving Thurston County. On site in about 60 minutes, day or night.</p><!-- /wp:paragraph -->'
	. '<!-- wp:dxai-ui/text {"tagName":"div","className":"checks"} --><div class="checks"><span class="row"><span aria-hidden="true" class="tick">✓</span>4.9-star Google rating from 800+ local reviews</span><span class="row"><span aria-hidden="true" class="tick">✓</span>We work with all insurance companies</span></div><!-- /wp:dxai-ui/text -->'
	. '<!-- wp:dxai-ui/text {"tagName":"a","url":"tel:+18555607463","className":"call"} --><a href="tel:+18555607463" class="call">Call Now: (855) 560-7463</a><!-- /wp:dxai-ui/text -->'
	. '</div><!-- /wp:group --><!-- wp:group {"className":"figures"} --><div class="wp-block-group figures">' . $fig( '4.9 ★', 'Google reviews' ) . $fig( '~60 min', 'On site, day or night' ) . $fig( '800+', 'Homes restored' ) . '</div><!-- /wp:group --></section><!-- /wp:group -->'
)[0];
// The old page's first part as the extractor reads it: its title, a long paragraph, two short lines; nothing over the title.
$blank = new ReflectionMethod( Page_Composer::class, 'blank' );
$blank->setAccessible( true );
$fill = new ReflectionMethod( Page_Composer::class, 'fill' );
$fill->setAccessible( true );
$unit = array_merge(
	$blank->invoke( null ),
	array(
		'kind'    => 'hero',
		'heading' => 'Emergency Fire &amp; Smoke Damage Restoration',
		'paras'   => array( 'Fire damage can affect far more than the areas touched directly by flames, as smoke, soot and odors spread into rooms the fire never reached, and H2O Away restores homes across Olympia and Tumwater.', 'Board-up within the hour', 'We bill your insurer directly' ),
		'buttons' => array( array( 'label' => 'Call Now: (855) 775-8614', 'href' => 'tel:+18557758614' ) ),
	)
);
$reference = "Emergency Fire & Smoke Damage Restoration
Fire damage can affect far more than the areas touched directly by flames, as smoke, soot and odors spread into rooms the fire never reached, and H2O Away restores homes across Olympia and Tumwater.
Board-up within the hour
We bill your insurer directly";
$compose  = static function ( bool $write ) use ( $hero, $unit, $fill ): array {
	$block = $fill->invoke( ( new Page_Composer( array(), static fn( string $src ): array => array( 'url' => $src, 'id' => 0 ) ) )->write_slots( $write ), Section_Library::analyze( $hero, 0 ), 0, $unit );

	return array( 'sections' => is_array( $block ) ? array( $block ) : array() );
};

echo "Composed for an AI\n";
$marked = $compose( true );
$json   = (string) wp_json_encode( $marked['sections'] );
$expect( 'the places keep the Home\'s words as their measure', str_contains( $json, '"' . Slot_Writer::WAS . '":"Third-generation insurance contractors' ) );
$expect( 'the line over the title, which the old page has nothing for, is kept to be written', str_contains( $json, 'Emergency Response' ) && str_contains( $json, '"' . Slot_Writer::WRITE . '":true' ) );
$plain = $compose( false );
$expect( 'composed without an AI, the line over the title goes and nothing is marked, as before', ! str_contains( (string) wp_json_encode( $plain['sections'] ), '24/7 Emergency Response' ) && ! str_contains( (string) wp_json_encode( $plain['sections'] ), '__dxai_' ) );

echo "Written by an AI\n";
$stub = new class() extends DXAI_UI\Engines\Abstract_Engine {
	/** @var array<int, string> */
	public array $asked = array();
	/** @var array<string, string> */
	public array $answer = array();
	public bool $broken  = false;
	public function get_id(): string { return 'stub'; }
	public function get_label(): string { return 'Stub'; }
	public function test_connection(): bool|\WP_Error { return true; }
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error { return new \WP_Error( 'x', 'no' ); }
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
		$this->asked[] = $user;
		if ( $this->broken ) {
			return new \WP_Error( 'stub_down', 'The stub is down.' );
		}
		$rows = json_decode( substr( $user, (int) strpos( $user, 'BLOCKS:' ) + 7 ), true );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			if ( isset( $r['max_words'] ) ) {
				// Asked to shorten: the first max_words words.
				$out[] = array( 'id' => $r['id'], 'text' => implode( ' ', array_slice( explode( ' ', (string) $r['text'] ), 0, (int) $r['max_words'] ) ) );
				continue;
			}
			$out[] = array( 'id' => $r['id'], 'text' => $this->answer[ $r['kind'] . ( $r['write'] ? '+write' : '' ) ] ?? ( 'Fire ' . str_repeat( 'word ', max( 1, (int) $r['words'] - 1 ) ) ) );
		}
		return (string) wp_json_encode( array( 'blocks' => $out ) );
	}
};
$long_para    = 'Fire and smoke damage restored across Olympia by our own crews, ' . str_repeat( 'with care and photographs for your insurer at every step, ', 8 );
$stub->answer = array(
	'paragraph+write' => '24/7 fire response in Olympia',
	'paragraph'       => $long_para,
	'heading'         => 'Emergency Fire &amp; Smoke Damage Restoration',
);
$done   = Slot_Writer::write( $marked['sections'], $reference, 'Emergency Fire & Smoke Damage Restoration', 0, $stub );
$asked  = (string) ( $stub->asked[0] ?? '' );
$blocks = json_decode( substr( $asked, (int) strpos( $asked, 'BLOCKS:' ) + 7 ), true );
$by     = array();
foreach ( (array) $blocks as $b ) {
	$by[ $b['kind'] . ( $b['write'] ? '+write' : '' ) ] = $b;
}
$expect( 'the AI is shown each place with the Home\'s length there', isset( $by['paragraph'] ) && (int) $by['paragraph']['words'] === 15 && isset( $by['heading'] ) && (int) $by['heading']['words'] === 9, wp_json_encode( $blocks ) );
$expect( '…the line over the title as one to write (the Home\'s 6 words, no text)', isset( $by['paragraph+write'] ) && (int) $by['paragraph+write']['words'] === 6 && $by['paragraph+write']['text'] === '', wp_json_encode( $by['paragraph+write'] ?? null ) );
$expect( '…and the old page\'s text to take the words from', str_contains( $asked, "THE OLD PAGE'S TEXT:" ) && str_contains( $asked, 'smoke, soot and odors spread into rooms' ) );
$expect( 'the claims, the call and the figures are not the AI\'s to write', ! str_contains( (string) wp_json_encode( $blocks ), 'Board-up within the hour' ) && ! str_contains( (string) wp_json_encode( $blocks ), 'Call Now' ) && ! str_contains( (string) wp_json_encode( $blocks ), 'On site, day or night' ) );
$html = implode( '', array_map( 'serialize_block', $done['sections'] ) );
$expect( 'its words are in their places: the line over the title written', str_contains( $html, '<p class="eyebrow">24/7 fire response in Olympia</p>' ), $html );
$expect( 'what ran over its place\'s measure was sent back once and came back within it', count( $stub->asked ) === 2 && $done['shortened'] === 1 && Slot_Writer::words( (string) preg_replace( '#.*<!-- wp:paragraph --><p>(.*?)</p>.*#s', '$1', $html ) ) <= 18, count( $stub->asked ) . ' ' . $done['shortened'] );
$expect( 'the claims keep their ticks, the figures and the call stay as composed', substr_count( $html, '<span aria-hidden="true" class="tick">✓</span>' ) === 2 && str_contains( $html, 'On site, day or night' ) && str_contains( $html, 'tel:+18557758614' ) );
$expect( 'no mark of the work is left on the page', ! str_contains( (string) wp_json_encode( $done['sections'] ), '__dxai_' ) );

echo "When there is nothing to write, or no AI\n";
$stub->asked  = array();
$stub->answer = array( 'paragraph+write' => '' );
$empty        = Slot_Writer::write( $compose( true )['sections'], $reference, 'Fire', 0, $stub );
$expect( 'a place the AI leaves empty is taken off', ! str_contains( implode( '', array_map( 'serialize_block', $empty['sections'] ) ), 'class="eyebrow"' ) && $empty['dropped'] === 1 );
$stub->broken = true;
$failed       = Slot_Writer::write( $compose( true )['sections'], $reference, 'Fire', 0, $stub );
$failed_html  = implode( '', array_map( 'serialize_block', $failed['sections'] ) );
$plain_html   = implode( '', array_map( 'serialize_block', $plain['sections'] ) );
$expect( 'an AI that does not answer leaves the page as the composer made it without one', $failed_html === $plain_html && $failed['error'] !== '', $failed['error'] );
$none = Slot_Writer::write( $compose( true )['sections'], $reference, 'Fire', 0, null );
$expect( '…and so does no AI at all', implode( '', array_map( 'serialize_block', $none['sections'] ) ) === $plain_html );

echo "The crawl of an old site writes its pages' words with the AI chosen in Settings\n";
// A Home with that hero; an old page's model; the page built as the crawl builds it (Site_From_Menu::compose_body()).
$home_id = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'Slot Writer Home ' . substr( md5( (string) microtime( true ) ), 0, 6 ),
		'post_content' => wp_slash( serialize_block( $hero ) ),
	)
);
register_shutdown_function( static fn() => wp_delete_post( $home_id, true ) );
$body = new ReflectionMethod( DXAI_UI\Structures\Site_From_Menu::class, 'compose_body' );
$body->setAccessible( true );
$crawl_model = ( new DXAI_UI\Pages\Content_Extractor() )->extract( '<html><body><main><section><h1>Emergency Fire Damage Restoration</h1><p>Fire damage can affect far more than the areas touched directly by flames, as smoke and soot spread into rooms the fire never reached.</p></section><section><h2>Smoke and soot</h2><p>Smoke and soot travel through a property and settle on surfaces the fire never touched.</p></section></main></body></html>', 'https://old.example.test/fire/' );
$stub->broken = false;
$stub->asked  = array();
$stub->answer = array( 'paragraph+write' => '24/7 fire response in Olympia' );
add_filter( 'dxai_ui_copy_engine', static fn() => $stub );
$summary = array();
$markup  = (string) $body->invokeArgs( null, array( $home_id, $crawl_model, &$summary, '/fire/' ) );
$expect( 'the page\'s words are written by it, in their places', count( $stub->asked ) >= 1 && ( $summary['words_engine'] ?? '' ) === 'stub' && str_contains( $markup, '24/7 fire response in Olympia' ) && ! str_contains( $markup, '__dxai_' ), wp_json_encode( $summary['compose_log'] ?? null ) );
add_filter( 'dxai_ui_crawl_words', '__return_false' );
$stub->asked = array();
$summary     = array();
$markup      = (string) $body->invokeArgs( null, array( $home_id, $crawl_model, &$summary, '/fire/' ) );
$expect( '…and when a site says no (dxai_ui_crawl_words), the composer\'s words stay and nothing is asked', $stub->asked === array() && ! isset( $summary['words_engine'] ) && ! str_contains( $markup, '24/7 fire response' ) && ! str_contains( $markup, '__dxai_' ) );
remove_all_filters( 'dxai_ui_crawl_words' );
remove_all_filters( 'dxai_ui_copy_engine' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

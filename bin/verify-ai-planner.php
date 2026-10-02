<?php
/**
 * The optional AI steps of the pages — the planner of a page's sections, the notes on a page, what they cost — against an engine
 * made for the purpose. Nothing here asks a provider: the engine is a stand-in given through the `dxai_ui_copy_engine` filter,
 * the same way the copy writer is tested, and a check at the start refuses to go on if another engine would answer.
 *
 *   bash bin/wp-php.sh bin/verify-ai-planner.php        (or: wp eval-file bin/verify-ai-planner.php --user=1)
 *
 * Makes a Home and the pages made for it and removes them again, and puts back the prices it set. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Pages\Ai_Budget;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Pages\Page_Critic;
use DXAI_UI\Pages\Page_Planner;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Page_Scope;

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

/** An engine that answers what it is told to and counts what it is asked. */
if ( ! class_exists( 'Dxai_Mock_Engine' ) ) {
	final class Dxai_Mock_Engine implements LLM_Provider_Interface {
		/** @var array<int, array{system:string, user:string}> */
		public static array $calls = array();
		/** @var mixed A string, a WP_Error, or a callable( string $user ): string|WP_Error. */
		public static $reply = '';

		public function get_id(): string {
			return 'mock';
		}
		public function get_label(): string {
			return 'Mock engine';
		}
		public function test_connection(): bool|\WP_Error {
			return true;
		}
		public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
			return new \WP_Error( 'mock', 'not used' );
		}
		public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
			self::$calls[] = array( 'system' => $system, 'user' => $user );
			$r = self::$reply;

			return is_callable( $r ) ? $r( $user ) : $r;
		}
	}
}
$engine = new Dxai_Mock_Engine();
add_filter( 'dxai_ui_copy_engine', static fn() => $engine );
$expect( 'the engine that answers is the stand-in (nothing here can ask a provider)', Copy_Writer::engine() === $engine );
if ( Copy_Writer::engine() !== $engine ) {
	exit( 1 );
}
$set = static function ( $reply ) use ( &$engine ): void {
	Dxai_Mock_Engine::$calls = array();
	Dxai_Mock_Engine::$reply = $reply;
};

$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$button  = static fn( string $t, string $url ): string => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $t . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$section = static fn( string $name, string $class, string $inner ): string => '<!-- wp:group {"metadata":{"name":"' . $name . '"},"className":"' . $class . '","tagName":"section"} --><section class="wp-block-group ' . $class . '">' . $inner . '</section><!-- /wp:group -->';
$card    = static fn( string $t, string $d ): string => '<!-- wp:column {"className":"svc-card"} --><div class="wp-block-column svc-card">' . $heading( $t, 3 ) . $para( $d ) . $button( 'Learn more', '#' ) . '</div><!-- /wp:column -->';
$place   = static fn( string $t ): string => '<!-- wp:group {"className":"place-card"} --><div class="wp-block-group place-card">' . $heading( $t, 3 ) . $para( 'Open 24/7' ) . '</div><!-- /wp:group -->';
$inner   = static fn( string $c ): string => '<!-- wp:group {"className":"inner"} --><div class="wp-block-group inner">' . $c . '</div><!-- /wp:group -->';

$header = '<!-- wp:group {"tagName":"header","className":"site-bar"} --><header class="wp-block-group site-bar"><!-- wp:paragraph --><p>Menu Home About</p><!-- /wp:paragraph --></header><!-- /wp:group -->';
$footer = '<!-- wp:group {"tagName":"footer","className":"site-foot"} --><footer class="wp-block-group site-foot"><!-- wp:paragraph --><p>© Fixture Co. 12 Elm St, Tyler, TX. All rights reserved.</p><!-- /wp:paragraph --></footer><!-- /wp:group -->';
$home   = $header
	. $section( 'Hero Section', 'hero-x bg-dark', $inner( $heading( 'Restoration in Tyler', 1 ) . $para( 'Family run since 1983. One team from the first call to the finished repair.' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) ) )
	. $section( 'Services grid', 'svc-x', $inner( $heading( 'Restoration Services We Provide' ) . $para( 'What we do.' ) . '<!-- wp:columns --><div class="wp-block-columns">' . $card( 'Water Damage', 'Extraction, structural drying and mold-safe cleanup.' ) . $card( 'Fire Damage', 'Soot, smoke and odor removal after a fire.' ) . $card( 'Mold Removal', 'Containment and removal to the standard.' ) . '</div><!-- /wp:columns -->' ) )
	. $section( 'Locations', 'loc-x', $inner( $heading( 'Where we work' ) . $place( 'Tyler, TX' ) . $place( 'Dallas, TX' ) ) )
	. $section( 'Reviews Section', 'rev-x', $inner( $heading( 'What customers say' ) . $para( '"Fast and kind." — A customer' ) ) )
	. $section( 'FAQ Section', 'faq-x', $inner( $heading( 'Frequently asked questions' ) . $para( 'How fast can you come?' ) . $para( 'Within the hour.' ) ) )
	. $section( 'CTA Banner', 'cta-x', $inner( $heading( 'Water where it should not be?' ) . $button( 'Call (555) 010-0101', 'tel:5550100101' ) ) )
	. $footer;

$home_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture AI Planner', 'post_content' => wp_slash( $home ) ) );
$made    = array( (int) $home_id );
$prices0 = get_option( Ai_Budget::PRICES, null );
$fin     = static function () use ( &$made, $prices0 ): void {
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	if ( $prices0 === null ) {
		delete_option( Ai_Budget::PRICES );
	} else {
		update_option( Ai_Budget::PRICES, $prices0, false );
	}
};
if ( ! is_int( $home_id ) || $home_id < 1 ) {
	echo "  FAIL  a Home to test on\n";
	exit( 1 );
}
update_post_meta( $home_id, Page_Scope::META, $home_id );
update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home_id, '_dxai_ui_css_url', 'dxai-ui/fixture-ai.css' );
$track = static function ( $out ) use ( &$made ): array {
	foreach ( is_wp_error( $out ) ? array() : (array) $out['pages'] as $p ) {
		$made[] = (int) $p['id'];
	}

	return is_wp_error( $out ) ? array() : (array) $out['pages'];
};
$titles_of = static function ( int $id ): array {
	$out = array();
	foreach ( array_values( Section_Library::for_page( $id ) ) as $c ) {
		$h = array();
		foreach ( (array) $c['panels'] as $p ) {
			$h[] = Block_Tree::own_text( Block_Tree::at( $c['block'], (array) $p['heading'] ) );
		}
		$out[] = $h[0] ?? '';
	}

	return $out;
};

echo "What a run may cost\n";
$expect( 'a token is four characters', Ai_Budget::tokens( str_repeat( 'a', 400 ) ) === 100 && Ai_Budget::tokens( '' ) === 0 );
delete_option( Ai_Budget::PRICES );
$e = Ai_Budget::estimate( array( array( 'in' => 3000, 'out' => 700 ), array( 'in' => 2000, 'out' => 700 ) ) );
$expect( 'the estimate counts the requests and the tokens in and out', $e['requests'] === 2 && $e['input'] === 5000 && $e['output'] === 1400 && $e['tokens'] === 6400, json_encode( $e ) );
$expect( 'with no price typed in, the cost is told in tokens alone (no price is made up)', $e['cost'] === null );
Ai_Budget::save_prices( 3.0, 15.0 );
$e = Ai_Budget::estimate( array( array( 'in' => 1000000, 'out' => 100000 ) ) );
$expect( 'with the price typed in (dollars for a million tokens), the cost follows', $e['cost'] === 4.5 && Ai_Budget::prices() === array( 'in' => 3.0, 'out' => 15.0 ), json_encode( $e ) );
Ai_Budget::save_prices( 0, 0 );
$expect( 'an empty price is forgotten', Ai_Budget::prices() === null );
$b = new Ai_Budget( 1000 );
$expect( 'a request within the ceiling is allowed, and counted when made', $b->allows( 300, 200 ) && ( function () use ( $b ) { $b->spend( 300, 200 ); return $b->spent()['tokens'] === 500 && $b->spent()['requests'] === 1; } )() );
$expect( 'a request that would pass the ceiling is not', ! $b->allows( 400, 200 ) && $b->allows( 300, 200 ) );

echo "\nThe plan an AI is asked for\n";
$library = Section_Library::for_page( $home_id );
$roles   = Team_Pages::home_roles( $library );
$item    = array( 'type' => 'service', 'title' => 'Water Damage', 'slug' => 'water-damage' );
$ctx     = Page_Planner::context( $library, $roles, $item, array( 'hero', 'cards', 'faq', 'cta' ), array( 'related' => true, 'contact' => true ) );
$req     = Page_Planner::request( $ctx );
$expect( 'it is told what the page is, and the Home\'s sections by number with what they say', str_contains( $req['user'], 'Page: "Water Damage", a service page' ) && str_contains( $req['user'], '1. hero' ) && str_contains( $req['user'], '"Restoration Services We Provide"' ) && str_contains( $req['user'], '6. cta' ) );
$expect( 'and what can be made for the page, only what can', str_contains( $req['user'], '- related:' ) && str_contains( $req['user'], '- contact:' ) && ! str_contains( Page_Planner::request( Page_Planner::context( $library, $roles, $item, array( 'hero' ), array( 'related' => false, 'contact' => false ) ) )['user'], '- related:' ) );
$expect( 'the rules are said, and it is told not to write words', str_contains( $req['user'], 'at most once' ) && str_contains( $req['user'], 'do not write any words' ) );
$expect( 'a system line that says it never invents content, and answers in JSON', str_contains( $req['system'], 'never invent' ) && str_contains( $req['system'], 'JSON' ) );

$ok = static fn( array $s, array $over = array() ): array => array_merge( array( 'sections' => $s, 'why' => 'because' ), $over );
$v  = static fn( array $data, ?array $c = null ) => Page_Planner::validate( $data, $c ?? $ctx );
$bad = static function ( $r, string $needle ): bool {
	return is_wp_error( $r ) && str_contains( $r->get_error_message(), $needle );
};
$good = $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'cta', 'home' => 6 ) ) ) );
$expect( 'a plan that follows the rules is accepted: the roles, and for each place the Home\'s section (none for a made one)', ! is_wp_error( $good ) && $good['roles'] === array( 'hero', 'cards', 'related', 'faq', 'cta' ) && $good['picks'] === array( 0, 1, null, 4, 5 ) && $good['why'] === 'because', json_encode( $good ) );
$expect( 'no sections: refused', $bad( $v( array() ), 'no list' ) && $bad( $v( array( 'sections' => 'hero' ) ), 'no list' ) );
$expect( 'too few sections, too many', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'sections (' ) && $bad( $v( $ok( array_fill( 0, 11, array( 'role' => 'hero', 'home' => 1 ) ) ) ), 'sections (' ) );
$expect( 'a role that is not one', $bad( $v( $ok( array( array( 'role' => 'banner', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'is not a role' ) );
$expect( 'a made section that cannot be made for the page', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'cta', 'home' => 6 ) ) ), Page_Planner::context( $library, $roles, $item, array(), array( 'related' => false, 'contact' => true ) ) ), 'cannot be made' ) );
$expect( 'a made section twice; a made role that does not say it is made; a made name that is not the role', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'twice' ) && $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'related', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'has to say so' ) && $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'contact', 'made' => 'related' ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'not a section that can be made' ) );
$expect( 'a section with neither a Home section nor a made one; a Home section that is not there', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards' ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'neither' ) && $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 99 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'has no section 99' ) );
$expect( 'a Home section given a role it cannot play (the cards as the questions)', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'faq', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'cannot play' ) );
$expect( 'a Home section twice, but a call to action may be (apart)', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'reviews', 'home' => 4 ), array( 'role' => 'cards', 'home' => 2 ) ) ) ), 'too often' ) && ! is_wp_error( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cta', 'home' => 6 ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) ) );
$expect( 'a call to action twice in a row', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cta', 'home' => 6 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'in a row' ) );
$expect( 'a call to action three times', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cta', 'home' => 6 ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'cta', 'home' => 6 ), array( 'role' => 'reviews', 'home' => 4 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'too often' ) );
$expect( 'a page that does not begin with the hero', $bad( $v( $ok( array( array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'not the hero' ) );
$expect( 'the list of the site\'s pages after the questions', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'after the faq' ) );
$expect( 'a FAQ page without the questions, when the Home has them', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ), Page_Planner::context( $library, $roles, array( 'type' => 'faq', 'title' => 'FAQ', 'slug' => 'faq' ), array(), array( 'related' => true, 'contact' => false ) ) ), 'without its faq' ) );
$expect( 'the page that lists the pages, without the list', $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ), Page_Planner::context( $library, $roles, array( 'type' => 'services', 'title' => 'Services', 'slug' => 'services' ), array(), array( 'related' => true, 'contact' => false ) ) ), 'no list of them' ) );
$expect( 'only the keys it knows matter: a home number as text is taken, a home that is text is not', ! is_wp_error( $v( $ok( array( array( 'role' => 'hero', 'home' => '1' ), array( 'role' => 'cards', 'home' => '2' ), array( 'role' => 'cta', 'home' => '6' ) ) ) ) ) && $bad( $v( $ok( array( array( 'role' => 'hero', 'home' => 'one' ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ), 'neither' ) );

echo "\nAsking\n";
$budget = new Ai_Budget( 100000 );
$set( wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) );
$r = Page_Planner::run( $engine, $ctx, $budget );
$expect( 'one request, and a plan', count( Dxai_Mock_Engine::$calls ) === 1 && $r['plan'] !== null && $r['requested'] && $r['reason'] === '', json_encode( $r ) );
$expect( 'and the run says what it spent', $budget->spent()['requests'] === 1 && $budget->spent()['tokens'] > 0 );
$set( 'I would start with the hero and then the cards.' );
$r = Page_Planner::run( $engine, $ctx, $budget );
$expect( 'an answer that is not JSON is dropped, with its reason', $r['plan'] === null && $r['requested'] && str_contains( $r['reason'], 'not JSON' ), json_encode( $r ) );
$set( '```json' . "\n" . wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) . "\n```" );
$expect( 'JSON in a code fence is read', Page_Planner::run( $engine, $ctx, $budget )['plan'] !== null );
$set( new WP_Error( 'x', 'rate limited' ) );
$r = Page_Planner::run( $engine, $ctx, $budget );
$expect( 'an engine that errors is dropped, with its reason', $r['plan'] === null && str_contains( $r['reason'], 'rate limited' ) );
$set( wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'faq', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) );
$r = Page_Planner::run( $engine, $ctx, $budget );
$expect( 'an answer that breaks a rule is dropped, with the rule', $r['plan'] === null && str_contains( $r['reason'], 'cannot play' ) );
$set( wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) );
$small = new Ai_Budget( 50 );
$r     = Page_Planner::run( $engine, $ctx, $small );
$expect( 'a run over its ceiling asks nothing', Dxai_Mock_Engine::$calls === array() && $r['plan'] === null && ! $r['requested'] && str_contains( $r['reason'], 'ceiling' ), json_encode( $r ) );

echo "\nThe pages made with a plan\n";
$services = array(
	array( 'type' => 'service', 'title' => 'Water Damage' ),
	array( 'type' => 'service', 'title' => 'Fire Damage' ),
	array( 'type' => 'service', 'title' => 'Mold Removal' ),
);
$set(
	static function ( string $user ) use ( $ok ) {
		if ( str_contains( $user, 'Page: "Fire Damage"' ) ) {
			return wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'faq', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ); // breaks a rule
		}
		return wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'reviews', 'home' => 4 ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'cta', 'home' => 6 ) ), array( 'why' => 'a short page that points to the others' ) ) );
	}
);
$none = Team_Pages::build( $home_id, $services );
$track( $none );
$expect( 'without asking for an AI, none is asked', Dxai_Mock_Engine::$calls === array() && ! is_wp_error( $none ) && $none['ai']['asked'] === false && $none['ai']['spent'] === null );
$usual_water = (string) get_post_field( 'post_content', (int) ( $none['pages'][0]['id'] ?? 0 ) );
$set(
	static function ( string $user ) use ( $ok ) {
		if ( str_contains( $user, 'Page: "Fire Damage"' ) ) {
			return wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'faq', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) );
		}
		return wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'reviews', 'home' => 4 ), array( 'role' => 'faq', 'home' => 5 ), array( 'role' => 'cta', 'home' => 6 ) ), array( 'why' => 'a short page that points to the others' ) ) );
	}
);
$out = Team_Pages::build( $home_id, $services, true, array( 'ai' => true ) );
$pg  = $track( $out );
$by  = array();
foreach ( $pg as $p ) {
	$by[ $p['title'] ] = $p;
}
$expect( 'asked to, one request for each page, no more', ! is_wp_error( $out ) && count( Dxai_Mock_Engine::$calls ) === 3 && $out['ai']['spent']['requests'] === 3, (string) count( Dxai_Mock_Engine::$calls ) );
$expect( 'two plans were used and one was not (it broke a rule): the summary says so', $out['ai']['accepted'] === 2 && $out['ai']['rejected'] === 1 && $out['ai']['skipped'] === 0, json_encode( $out['ai'] ) );
$expect( 'the log says why the plan was not used, and that the usual one was', (bool) array_filter( $out['log'], static fn( $l ) => str_contains( $l, 'Fire Damage: the AI plan was not used' ) && str_contains( $l, 'cannot play' ) && str_contains( $l, 'the usual plan' ) ) );
$expect( 'the log says what the AI planned', (bool) array_filter( $out['log'], static fn( $l ) => str_contains( $l, 'Water Damage: the AI planned the sections (a short page' ) ) );
$wt = $titles_of( (int) $by['Water Damage']['id'] );
$expect( 'the page follows the plan: the hero with its title, the list of the site\'s pages, the reviews, the questions, the call to action', count( $wt ) === 5 && $wt[0] === 'Water Damage' && $wt[1] === 'Our other services' && $wt[2] === 'What customers say' && $wt[3] === 'Frequently asked questions' && $wt[4] === 'Water where it should not be?', json_encode( $wt ) );
$expect( 'the list is the made one: it links the other services', str_contains( (string) get_post_field( 'post_content', (int) $by['Water Damage']['id'] ), (string) get_permalink( (int) $by['Fire Damage']['id'] ) ) );
$expect( 'the page says it was planned by an AI, and the one that was not, why', str_contains( (string) get_post_meta( (int) $by['Water Damage']['id'], Team_Pages::AI_META, true ), '"plan":"ai"' ) && str_contains( (string) get_post_meta( (int) $by['Fire Damage']['id'], Team_Pages::AI_META, true ), 'cannot play' ) && ( $by['Water Damage']['planned'] ?? '' ) === 'ai' && ( $by['Fire Damage']['planned'] ?? '' ) === 'usual' );
$expect( 'a page whose plan was not used is the usual page', $titles_of( (int) $by['Fire Damage']['id'] ) !== array() && count( $titles_of( (int) $by['Fire Damage']['id'] ) ) >= 3 );
$expect( 'the page is valid blocks and the Home\'s look all the same (the quality measure reads it clean)', ( function () use ( $home_id ) {
	$q = \DXAI_UI\Pages\Team_Quality::measure( $home_id );
	return ( $q['gates']['G1'] ?? array() ) === array() && ( $q['gates']['G7'] ?? array() ) === array() && ( $q['gates']['G9'] ?? array() ) === array();
} )() );
$again = Team_Pages::build( $home_id, $services, true );
$track( $again );
$expect( 'making the pages again without the AI makes the usual pages, and the mark of the AI is gone', get_post_meta( (int) $by['Water Damage']['id'], Team_Pages::AI_META, true ) === '' && (string) get_post_field( 'post_content', (int) $by['Water Damage']['id'] ) === $usual_water );

$set( new WP_Error( 'x', 'no key' ) );
$bro = Team_Pages::build( $home_id, $services, true, array( 'ai' => true ) );
$track( $bro );
$expect( 'an engine that fails costs the pages nothing: they are made the usual way, and the log says why', ! is_wp_error( $bro ) && $bro['ai']['rejected'] === 3 && count( $bro['pages'] ) === 3 && (string) get_post_field( 'post_content', (int) $by['Water Damage']['id'] ) === $usual_water && (bool) array_filter( $bro['log'], static fn( $l ) => str_contains( $l, 'no key' ) ) );
$set( wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) );
$cap = Team_Pages::build( $home_id, $services, true, array( 'ai' => true, 'cap' => 10 ) );
$track( $cap );
$expect( 'a ceiling too low for the requests stops them: none is made, every page is the usual one', Dxai_Mock_Engine::$calls === array() && $cap['ai']['skipped'] === 3 && count( $cap['pages'] ) === 3 );

echo "\nWhat it would cost, before it is asked\n";
$set( '' );
Ai_Budget::save_prices( 3.0, 15.0 );
$est = Team_Pages::estimate( $home_id, $services );
$expect( 'a request for each page, worked out from the requests that would be made, and nothing sent', ! is_wp_error( $est ) && $est['requests'] === 3 && $est['pages'] === 3 && $est['input'] > 600 && $est['output'] === 3 * Ai_Budget::PLAN_OUT && Dxai_Mock_Engine::$calls === array(), json_encode( $est ) );
$expect( 'with the cost, since the price was typed in; and the engine that would answer', is_float( $est['cost'] ) && $est['cost'] > 0 && $est['engine'] === 'Mock engine' && $est['within'] === true );
Ai_Budget::save_prices( 0, 0 );
$expect( 'without the price, in tokens alone', Team_Pages::estimate( $home_id, $services )['cost'] === null );
$expect( 'nothing asked for is an error', is_wp_error( Team_Pages::estimate( $home_id, array() ) ) );

echo "\nThe words of a made section\n";
$set(
	wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'contact', 'made' => 'contact' ), array( 'role' => 'related', 'made' => 'related' ), array( 'role' => 'cta', 'home' => 6 ) ) ) )
);
$cb  = Team_Pages::build( $home_id, array( array( 'type' => 'contact', 'title' => '' ), array( 'type' => 'service', 'title' => 'Water Damage' ), array( 'type' => 'service', 'title' => 'Fire Damage' ) ), true, array( 'ai' => true ) );
$cpg = $track( $cb );
$ct  = 0;
foreach ( $cpg as $p ) {
	$ct = $p['type'] === 'contact' ? (int) $p['id'] : $ct;
}
$cc = (string) get_post_field( 'post_content', $ct );
$expect( 'a plan can ask for the ways to reach the company on any page: the Home states a phone and an address', str_contains( $cc, 'Get in touch' ) && str_contains( $cc, '12 Elm St, Tyler, TX' ), substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $cc ) ), 0, 200 ) );
$items = Copy_Writer::inventory( $ct );
$texts = array_column( $items, 'text' );
$expect( 'the copy writer is given the title of a made section and nothing else of it (a phone number is the company\'s)', in_array( 'Get in touch', $texts, true ) && in_array( 'Our services', $texts, true ) && ! in_array( 'Call us', $texts, true ) && ! in_array( '(555) 010-0101', $texts, true ) && ! in_array( 'Visit us', $texts, true ) && ! in_array( 'Fire Damage', $texts, true ), json_encode( $texts ) );
$changes = array();
foreach ( $items as $i ) {
	$changes[ $i['id'] ] = 'NEW ' . $i['id'];
}
$applied = Copy_Writer::apply( $ct, $changes, Copy_Writer::fingerprint( $items ) );
$after   = (string) get_post_field( 'post_content', $ct );
$expect( 'what is applied reaches the titles of the made sections and the Home\'s words, not the pages they link or the facts they state', ! is_wp_error( $applied ) && $applied['applied'] === count( $items ) && str_contains( $after, 'Call us' ) && str_contains( $after, '12 Elm St, Tyler, TX' ) && str_contains( $after, '>Fire Damage<' ) && ! str_contains( $after, '>Get in touch<' ) );
$expect( 'and the words can be put back', Copy_Writer::revert( $ct ) === true && str_contains( (string) get_post_field( 'post_content', $ct ), '>Get in touch<' ) );
$expect( 'the request tells the AI that those cards are facts of the site', str_contains( Copy_Writer::request( $ct, $items )['user'], 'are facts of the site' ) );

echo "\nNotes on a page\n";
$page = (int) ( $by['Water Damage']['id'] ?? 0 );
$notes = Page_Critic::checks( $page );
$expect( 'a page made well has few notes (it is a list of strings)', is_array( $notes ) && count( $notes ) <= 3, json_encode( $notes ) );
$orig  = (string) get_post_field( 'post_content', $page );
$worse = $orig . "\n\n" . $section( 'CTA Banner', 'cta-x', $inner( $heading( 'Call now' ) . $button( 'Call', 'tel:5550100101' ) ) ) . "\n\n" . $section( 'CTA Banner', 'cta-x', $inner( $heading( 'Call now again' ) . $button( 'Call', 'tel:5550100101' ) ) );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => $worse ), array( 'ID' => $page ) );
clean_post_cache( $page );
$notes2 = Page_Critic::checks( $page );
$expect( 'two sections of one shape in a row, and two calls to action, are found', (bool) array_filter( $notes2, static fn( $n ) => str_contains( $n, 'same shape' ) ) && (bool) array_filter( $notes2, static fn( $n ) => str_contains( $n, 'Two calls to action' ) ), json_encode( $notes2 ) );
$long = str_repeat( 'word ', 120 );
$wpdb->update( $wpdb->posts, array( 'post_content' => $orig . '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Long</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph --></section><!-- /wp:group -->' ), array( 'ID' => $page ) );
clean_post_cache( $page );
$expect( 'a paragraph too long for a phone is found', (bool) array_filter( Page_Critic::checks( $page ), static fn( $n ) => str_contains( $n, 'a paragraph of 120 words' ) ) );
$wpdb->update( $wpdb->posts, array( 'post_content' => $orig ), array( 'ID' => $page ) );
clean_post_cache( $page );
$set( wp_json_encode( array( 'notes' => array( 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight' ) ) ) );
$cb2 = new Ai_Budget( 100000 );
$an  = Page_Critic::ask( $engine, $page, array(), $cb2 );
$expect( 'the AI\'s notes are taken: at most six, one request', is_array( $an ) && count( $an ) === 6 && count( Dxai_Mock_Engine::$calls ) === 1, json_encode( $an ) );
$expect( 'the request holds the outline of the page and the checks', str_contains( Dxai_Mock_Engine::$calls[0]['user'], '"role":"hero"' ) && str_contains( Dxai_Mock_Engine::$calls[0]['user'], 'Do not rewrite anything' ) );
$set( 'no idea' );
$expect( 'an answer that is not notes is an error, and writes nothing', is_wp_error( Page_Critic::ask( $engine, $page, array(), new Ai_Budget( 100000 ) ) ) && (string) get_post_field( 'post_content', $page ) === $orig );
$set( wp_json_encode( array( 'notes' => array( 'x' ) ) ) );
$expect( 'over the ceiling nothing is asked', is_wp_error( Page_Critic::ask( $engine, $page, array(), new Ai_Budget( 10 ) ) ) && Dxai_Mock_Engine::$calls === array() );
$ce = Page_Critic::estimate( $page, array() );
$expect( 'what it would cost is known before asking', $ce['requests'] === 1 && $ce['input'] > 100 && $ce['output'] === Ai_Budget::NOTES_OUT );

echo "\nOver REST\n";
wp_set_current_user( 1 );
$post = static function ( string $route, array $params ): WP_REST_Response {
	$r = new WP_REST_Request( 'POST', '/dxai-ui/v1/' . $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}

	return rest_do_request( $r );
};
$set( '' );
$res = $post( 'team-pages', array( 'action' => 'estimate', 'design' => $home_id, 'wanted' => $services, 'price_in' => 3, 'price_out' => 15 ) );
$d   = $res->get_data();
$expect( 'the estimate is asked for over REST: the price is kept, the cost comes back, and nothing is sent', $res->get_status() === 200 && ( $d['estimate']['requests'] ?? 0 ) === 3 && is_float( $d['estimate']['cost'] ?? null ) && ( $d['prices']['in'] ?? 0 ) === 3.0 && Dxai_Mock_Engine::$calls === array(), json_encode( $d ) );
Ai_Budget::save_prices( 0, 0 );
$set( wp_json_encode( $ok( array( array( 'role' => 'hero', 'home' => 1 ), array( 'role' => 'cards', 'home' => 2 ), array( 'role' => 'cta', 'home' => 6 ) ) ) ) );
$res = $post( 'team-pages', array( 'action' => 'build', 'design' => $home_id, 'wanted' => array( $services[0] ), 'force' => true, 'ai' => true ) );
$d   = $res->get_data();
$track( is_array( $d ) && isset( $d['pages'] ) ? $d : array() );
$expect( 'a build with the AI over REST: planned, and the summary comes back', $res->get_status() === 200 && ( $d['ai']['accepted'] ?? 0 ) === 1 && ( $d['pages'][0]['planned'] ?? '' ) === 'ai' && count( Dxai_Mock_Engine::$calls ) === 1, json_encode( $d['ai'] ?? $d ) );
$set( wp_json_encode( array( 'notes' => array( 'Mention the service area in the hero.' ) ) ) );
$res = $post( 'copy/' . $page, array( 'action' => 'critique' ) );
$d   = $res->get_data();
$expect( 'the notes on a page over REST: the free checks and the cost; no AI unless asked', $res->get_status() === 200 && is_array( $d['checks'] ?? null ) && ( $d['estimate']['requests'] ?? 0 ) === 1 && $d['notes'] === null && Dxai_Mock_Engine::$calls === array() );
$res = $post( 'copy/' . $page, array( 'action' => 'critique', 'ai' => true ) );
$d   = $res->get_data();
$expect( 'and with the AI asked: its note, one request', $res->get_status() === 200 && ( $d['notes'][0] ?? '' ) === 'Mention the service area in the hero.' && count( Dxai_Mock_Engine::$calls ) === 1 );

$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

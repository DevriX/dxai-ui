<?php
/**
 * The AI steps of the pages, measured: the planner of a page's sections (Page_Planner) and the copy writer (Copy_Writer), on the
 * cases in fixtures/ai-eval/cases.json — a Home and a page made for the purpose.
 *
 *   wp eval-file bin/eval-ai.php --user=1          the recorded replies: each is judged as its case says it must be (CI)
 *   wp eval-file bin/eval-ai.php live --user=1     the site's AI engine answers each case once (a few cents): every answer
 *                                                 must pass; SKIP (exit 77) when the site has no engine with a key
 *
 * A reply passes when the step's own checks take it (Page_Planner::validate, Copy_Writer::parse) and, for words, the eval's rules
 * hold on every block it changed: no phone number, e-mail address or link the site does not already show (contact); a heading,
 * label, link or button about as long as it was, a paragraph within reason (length); no placeholder left in (placeholder). The
 * recorded replies include ones that break each rule, so the rules are checked too: a rule that lets its bad reply through fails.
 * Makes the Home and the page as drafts and deletes them again. Exit code 1 when a case is judged otherwise than it must be.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Pages\Ai_Budget;
use DXAI_UI\Pages\Copy_Writer;
use DXAI_UI\Pages\Page_Planner;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Team_Pages;
use DXAI_UI\Structures\Page_Scope;

$live  = in_array( 'live', (array) ( $args ?? array() ), true );
$dir   = dirname( __DIR__ ) . '/fixtures/ai-eval/';
$cases = json_decode( (string) file_get_contents( $dir . 'cases.json' ), true );
if ( ! is_array( $cases ) ) {
	echo "  FAIL  fixtures/ai-eval/cases.json is read\n";
	exit( 1 );
}

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

/** The engine of the recorded replies: it answers what the case gives it. */
if ( ! class_exists( 'Dxai_Eval_Engine' ) ) {
	final class Dxai_Eval_Engine implements LLM_Provider_Interface {
		/** @var string What the next request is answered with. */
		public static string $reply = '';

		public function get_id(): string {
			return 'recorded';
		}
		public function get_label(): string {
			return 'Recorded replies';
		}
		public function test_connection(): bool|\WP_Error {
			return true;
		}
		public function generate( string $system, string $user, array $args = array() ): array|\WP_Error {
			return new \WP_Error( 'dxai_eval', 'not used' );
		}
		public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
			return self::$reply;
		}
	}
}

if ( $live ) {
	$engine = Copy_Writer::engine();
	if ( is_wp_error( $engine ) ) {
		echo 'SKIP: no AI engine with a key on this site (' . $engine->get_error_message() . ")\n";
		exit( 77 );
	}
	echo 'Live: ' . $engine->get_label() . " answers every case once.\n";
} else {
	$engine    = new Dxai_Eval_Engine();
	$recorded  = static fn() => $engine;
	add_filter( 'dxai_ui_copy_engine', $recorded );
	if ( Copy_Writer::engine() !== $engine ) {
		echo "  FAIL  the recorded replies answer (another engine would)\n";
		exit( 1 );
	}
}

// The Home and the page, as drafts, gone again at the end whatever happens.
$made = array();
register_shutdown_function(
	static function () use ( &$made ): void {
		foreach ( array_reverse( $made ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
);
$home_html = (string) file_get_contents( $dir . (string) $cases['home'] );
$home_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'AI eval Home', 'post_content' => wp_slash( $home_html ) ) );
if ( ! is_int( $home_id ) || $home_id < 1 ) {
	echo "  FAIL  a Home to measure on\n";
	exit( 1 );
}
$made[] = $home_id;
update_post_meta( $home_id, Page_Scope::META, $home_id );
update_post_meta( $home_id, '_dxai_ui_generated_page', '1' );
update_post_meta( $home_id, '_dxai_ui_css_url', 'dxai-ui/ai-eval.css' );

/**
 * The phone numbers (digits), e-mail addresses and links in a text.
 *
 * @return array<int, string>
 */
$contacts = static function ( string $text ): array {
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$out  = array();
	preg_match_all( '/\+?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b/', $text, $m );
	foreach ( $m[0] as $p ) {
		$out[] = 'tel:' . preg_replace( '/\D/', '', $p );
	}
	preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m );
	foreach ( $m[0] as $e ) {
		$out[] = 'mail:' . strtolower( $e );
	}
	preg_match_all( '#https?://[^\s"<>\')]+#i', $text, $m );
	foreach ( $m[0] as $u ) {
		$out[] = 'url:' . strtolower( rtrim( $u, '.,;' ) );
	}

	return array_values( array_unique( $out ) );
};

/**
 * The rules a proposal's changed blocks break, each with what broke it.
 *
 * @param array<int, array<string, mixed>> $blocks Copy_Writer::propose()['blocks'].
 * @return array<int, array{rule:string, detail:string}>
 */
$judge_words = static function ( array $blocks, string $facts ) use ( $contacts ): array {
	$known  = $contacts( $facts );
	$broken = array();
	foreach ( $blocks as $b ) {
		if ( empty( $b['changed'] ) ) {
			continue;
		}
		$before = trim( wp_strip_all_tags( (string) $b['before'] ) );
		$after  = trim( wp_strip_all_tags( (string) $b['after'] ) );
		foreach ( $contacts( (string) $b['after'] ) as $c ) {
			if ( ! in_array( $c, $known, true ) ) {
				$broken[] = array( 'rule' => 'contact', 'detail' => $c . ' in "' . mb_substr( $after, 0, 60 ) . '"' );
			}
		}
		$short = in_array( (string) $b['kind'], array( 'heading', 'button', 'link', 'label' ), true );
		$room  = $short ? max( 2 * mb_strlen( $before ), mb_strlen( $before ) + 40 ) : max( 4 * mb_strlen( $before ), mb_strlen( $before ) + 400 );
		if ( mb_strlen( $after ) > $room ) {
			$broken[] = array( 'rule' => 'length', 'detail' => sprintf( 'a %s of %d characters became %d', $b['kind'], mb_strlen( $before ), mb_strlen( $after ) ) );
		}
		if ( preg_match( '/\[[^\]]{2,}\]|\{\{|lorem ipsum|\bTBD\b|\bTODO\b|\bXXX\b|<insert|\binsert (your|the) /i', $after ) === 1 ) {
			$broken[] = array( 'rule' => 'placeholder', 'detail' => '"' . mb_substr( $after, 0, 60 ) . '"' );
		}
	}

	return $broken;
};

echo "\nThe planner of a page's sections\n";
$library = Section_Library::for_page( $home_id );
$roles   = Team_Pages::home_roles( $library );
foreach ( (array) $cases['planner'] as $case ) {
	$ctx = Page_Planner::context( $library, $roles, (array) $case['item'], (array) $case['usual'], (array) $case['made'] );
	if ( $live ) {
		$budget = new Ai_Budget( 100000 );
		$r      = Page_Planner::run( $engine, $ctx, $budget );
		$expect( $case['id'] . ': the engine\'s plan is one the page can be made from', $r['plan'] !== null, $r['plan'] !== null ? implode( ', ', $r['plan']['roles'] ) . ' — ' . $r['plan']['why'] : $r['reason'] );
		continue;
	}
	foreach ( (array) $case['replies'] as $reply ) {
		Dxai_Eval_Engine::$reply = (string) $reply['reply'];
		$r      = Page_Planner::run( $engine, $ctx, new Ai_Budget( 100000 ) );
		$took   = $r['plan'] !== null;
		$reason = (string) $r['reason'];
		$ok     = $reply['verdict'] === 'accept' ? $took : ( ! $took && str_contains( $reason, (string) ( $reply['reason'] ?? '' ) ) );
		$expect( $case['id'] . ': ' . $reply['why'] . ' — ' . ( $reply['verdict'] === 'accept' ? 'taken' : 'refused' ), $ok, $took ? implode( ', ', $r['plan']['roles'] ) : $reason );
	}
}

echo "\nThe copy writer\n";
foreach ( (array) $cases['copy'] as $case ) {
	$page_html = (string) file_get_contents( $dir . (string) $case['page'] );
	$page_id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => (string) $case['title'], 'post_content' => wp_slash( $page_html ) ) );
	if ( ! is_int( $page_id ) || $page_id < 1 ) {
		$expect( $case['id'] . ': a page to write on', false );
		continue;
	}
	$made[] = $page_id;
	update_post_meta( $page_id, Page_Scope::META, $home_id );
	$facts    = $home_html . "\n" . $page_html;
	$override = array( 'topic' => (string) $case['topic'] );
	if ( $live ) {
		$p = Copy_Writer::propose( $page_id, $override );
		if ( is_wp_error( $p ) ) {
			$expect( $case['id'] . ': the engine answers with words for the page', false, $p->get_error_message() );
			continue;
		}
		$broken = $judge_words( $p['blocks'], $facts );
		$expect( $case['id'] . ': the engine\'s words keep every rule', $broken === array(), $broken === array() ? $p['changed'] . ' blocks rewritten' : wp_json_encode( $broken ) );
		continue;
	}
	// A reply names a block by its words now; the eval gives it the block's id.
	$ids = array();
	foreach ( Copy_Writer::inventory( $page_id ) as $item ) {
		$ids[ $item['text'] ] ??= $item['id'];
	}
	foreach ( (array) $case['replies'] as $reply ) {
		if ( isset( $reply['raw'] ) ) {
			Dxai_Eval_Engine::$reply = (string) $reply['raw'];
		} else {
			$blocks = array();
			foreach ( (array) $reply['blocks'] as $before => $after ) {
				if ( ! isset( $ids[ $before ] ) ) {
					$expect( $case['id'] . ': the case names a block the page has: "' . $before . '"', false );
					continue 2;
				}
				$blocks[] = array( 'id' => $ids[ $before ], 'text' => (string) $after );
			}
			Dxai_Eval_Engine::$reply = (string) wp_json_encode( array( 'blocks' => $blocks, 'flags' => array() ) );
		}
		$p      = Copy_Writer::propose( $page_id, $override );
		$broken = is_wp_error( $p ) ? array( array( 'rule' => 'answer', 'detail' => $p->get_error_message() ) ) : $judge_words( $p['blocks'], $facts );
		$rules  = array_values( array_unique( array_column( $broken, 'rule' ) ) );
		$ok     = $reply['verdict'] === 'accept' ? $broken === array() : in_array( (string) $reply['rule'], $rules, true );
		$expect( $case['id'] . ': ' . $reply['why'] . ' — ' . ( $reply['verdict'] === 'accept' ? 'taken' : 'refused by "' . $reply['rule'] . '"' ), $ok, $broken === array() ? ( is_wp_error( $p ) ? '' : $p['changed'] . ' blocks rewritten' ) : wp_json_encode( $broken ) );
	}
}

if ( isset( $recorded ) ) {
	remove_filter( 'dxai_ui_copy_engine', $recorded );
}
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

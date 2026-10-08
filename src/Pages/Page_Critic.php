<?php
/**
 * Notes on a made page: what a person would see at a glance, said before they open it.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Json_Repair;
use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Theme\Capabilities;

/**
 * Two kinds of notes, neither of which changes the page:
 *
 *  - checks, free and always: what the markup says of itself (the same section twice in a row, a paragraph too long to read on a
 *    phone, a hero with no button, questions too few to be a list, a page that does not end in a call to action);
 *  - notes from an AI, when a person asks (one request): it is given the page as an outline and the checks, and says what else it
 *    would look at (a page about mold whose sections speak of water, proof that is missing, a section that says the same as another).
 *    It does not see the page drawn: that needs a browser, which a site on shared hosting has not got, and the outline holds
 *    what a drawing would show of the order and the weight of the sections.
 *
 * Notes are shown. Nothing is written to the page.
 */
final class Page_Critic {

	/** A paragraph of more words than this is long for a phone. */
	private const LONG = 90;

	/** The most notes taken from an AI. */
	private const MAX_NOTES = 6;

	/**
	 * The page as an outline: one row for each section.
	 *
	 * @return array<int, array{n:int, role:string, heading:string, words:int, longest:int, buttons:int, images:int, items:int, shape:string}>
	 */
	public static function outline( int $page_id ): array {
		$out = array();
		foreach ( array_values( Section_Library::for_page( $page_id ) ) as $i => $c ) {
			$block = (array) $c['block'];
			$head  = '';
			if ( ! empty( $c['panels'][0]['heading'] ) ) {
				$head = Block_Tree::own_text( Block_Tree::at( $block, (array) $c['panels'][0]['heading'] ) );
			}
			$words   = 0;
			$longest = 0;
			$buttons = 0;
			$images  = 0;
			$walk    = static function ( array $b ) use ( &$walk, &$words, &$longest, &$buttons, &$images ): void {
				$name = (string) ( $b['blockName'] ?? '' );
				if ( in_array( $name, array( 'core/paragraph', 'core/list-item', 'core/heading', 'dxai-ui/text' ), true ) ) {
					$n      = str_word_count( wp_strip_all_tags( (string) $b['innerHTML'] ) );
					$words += $n;
					if ( $name === 'core/paragraph' ) {
						$longest = max( $longest, $n );
					}
				}
				if ( $name === 'core/button' || ( $name === 'dxai-ui/link' && empty( $b['innerBlocks'] ) ) ) {
					++$buttons;
				}
				if ( in_array( $name, Capabilities::image_blocks(), true ) ) {
					++$images;
				}
				foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $child ) {
					if ( is_array( $child ) ) {
						$walk( $child );
					}
				}
			};
			$walk( $block );
			$items = 0;
			foreach ( (array) $c['repeats'] as $r ) {
				$items = max( $items, count( (array) $r['items'] ) );
			}
			$out[] = array(
				'n'       => $i + 1,
				'role'    => Section_Roles::of( $block, $i === 0 ),
				'heading' => mb_substr( $head, 0, 80 ),
				'words'   => $words,
				'longest' => $longest,
				'buttons' => $buttons,
				'images'  => $images,
				'items'   => $items,
				'shape'   => substr( md5( Section_Library::signature( $block ) ), 0, 8 ),
			);
		}

		return $out;
	}

	/**
	 * What the markup says of itself.
	 *
	 * @return array<int, string>
	 */
	public static function checks( int $page_id ): array {
		$o     = self::outline( $page_id );
		$notes = array();
		if ( $o === array() ) {
			return array( 'The page has no sections.' );
		}
		if ( count( $o ) < 4 ) {
			$notes[] = sprintf( 'The page has %d sections: short next to the team\'s pages (most have six or more).', count( $o ) );
		}
		foreach ( $o as $i => $s ) {
			$next = $o[ $i + 1 ] ?? null;
			if ( $next !== null && $next['shape'] === $s['shape'] ) {
				$notes[] = sprintf( 'Sections %d and %d have the same shape one after the other.', $s['n'], $next['n'] );
			}
			if ( $s['longest'] > self::LONG ) {
				$notes[] = sprintf( 'Section %d (%s) has a paragraph of %d words: long to read on a phone.', $s['n'], $s['role'], $s['longest'] );
			}
			if ( mb_strlen( $s['heading'] ) >= 80 ) {
				$notes[] = sprintf( 'Section %d has a heading of eighty characters or more.', $s['n'] );
			}
			if ( $s['role'] === 'faq' && $s['items'] > 0 && $s['items'] < 3 ) {
				$notes[] = sprintf( 'The questions (section %d) are %d: too few to be a list.', $s['n'], $s['items'] );
			}
		}
		if ( $o[0]['buttons'] === 0 ) {
			$notes[] = 'The hero has no button: nothing to press on the first screen.';
		}
		$roles = array_column( $o, 'role' );
		$last  = (string) end( $roles );
		if ( ! in_array( $last, array( 'cta', 'faq', 'form' ), true ) ) {
			$notes[] = 'The page does not end with a call to action, the questions or a form.';
		}
		for ( $i = 1, $n = count( $roles ); $i < $n; $i++ ) {
			if ( $roles[ $i ] === 'cta' && $roles[ $i - 1 ] === 'cta' ) {
				$notes[] = sprintf( 'Two calls to action in a row (sections %d and %d).', $i, $i + 1 );
			}
		}
		$h1 = preg_match_all( '/<h1\b/i', (string) get_post_field( 'post_content', $page_id ) );
		if ( $h1 !== 1 ) {
			$notes[] = $h1 === 0 ? 'The page has no main heading (h1).' : sprintf( 'The page has %d main headings (h1).', (int) $h1 );
		}

		return array_values( array_unique( $notes ) );
	}

	/**
	 * The request to an AI for notes on a page.
	 *
	 * @param array<int, string> $checks
	 * @return array{system:string, user:string}
	 */
	public static function request( int $page_id, array $checks ): array {
		$topic = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $page_id ) ), ENT_QUOTES, 'UTF-8' ) );
		$user  = sprintf( "Look at one page of a local service company's website and say what you would change.\n\nPage: \"%s\".\n", $topic )
			. "The page as an outline, one row for each section in order (role, heading, words, longest paragraph in words, buttons, pictures, items in its list, a code for its shape: the same code twice is the same layout):\n"
			. wp_json_encode( self::outline( $page_id ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n"
			. ( $checks === array() ? "The automatic checks found nothing.\n" : "The automatic checks already found:\n- " . implode( "\n- ", $checks ) . "\n" )
			. "\nGive at most " . self::MAX_NOTES . " short notes, each about something the checks did not say: a section that does not fit what the page is about, proof that is missing, two sections that say the same, a weak order. Do not rewrite anything.\n"
			. 'Answer with one JSON object and nothing else: {"notes":["..."]}';

		return array(
			'system' => 'You are a careful reviewer of local service company websites. You give short, concrete notes and answer with valid JSON only.',
			'user'   => $user,
		);
	}

	/**
	 * What asking would cost, without asking.
	 *
	 * @param array<int, string> $checks
	 * @return array{requests:int, input:int, output:int, tokens:int, cost:float|null, cap:int, within:bool}
	 */
	public static function estimate( int $page_id, array $checks ): array {
		$req = self::request( $page_id, $checks );

		return Ai_Budget::estimate( array( array( 'in' => Ai_Budget::tokens( $req['system'] . $req['user'] ), 'out' => Ai_Budget::NOTES_OUT ) ) );
	}

	/**
	 * Ask the engine for notes: one request.
	 *
	 * @param array<int, string> $checks
	 * @return array<int, string>|\WP_Error
	 */
	public static function ask( LLM_Provider_Interface $engine, int $page_id, array $checks, Ai_Budget $budget ) {
		$req = self::request( $page_id, $checks );
		$in  = Ai_Budget::tokens( $req['system'] . $req['user'] );
		if ( ! $budget->allows( $in, Ai_Budget::NOTES_OUT ) ) {
			return new \WP_Error( 'dxai_ui_critic_cap', __( 'This would pass the ceiling set for one run, so nothing was asked.', 'dxai-ui' ), array( 'status' => 402 ) );
		}
		$text = $engine->complete( $req['system'], $req['user'], array( 'max_tokens' => 1200 ) ); // phpcs:ignore -- engines have complete(); Copy_Writer::engine() checks.
		$budget->spend( $in, Ai_Budget::NOTES_OUT );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = Json_Repair::decode( (string) $text );
		if ( ! is_array( $data ) || ! is_array( $data['notes'] ?? null ) ) {
			return new \WP_Error( 'dxai_ui_critic_parse', __( 'The AI did not answer in the shape that was asked for.', 'dxai-ui' ), array( 'status' => 502 ) );
		}
		$notes = array();
		foreach ( $data['notes'] as $note ) {
			if ( is_string( $note ) && trim( $note ) !== '' ) {
				$notes[] = mb_substr( sanitize_text_field( $note ), 0, 240 );
			}
		}

		return array_slice( array_values( array_unique( $notes ) ), 0, self::MAX_NOTES );
	}
}

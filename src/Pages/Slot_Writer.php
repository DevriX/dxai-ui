<?php
/**
 * The words of a page composed from its Home's sections, written by an AI place by place, in each place's measure.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Theme\Capabilities;

/**
 * A page made from an old page and its Home's sections (Page_Composer) is the Home's build with the old page's words poured
 * in: a card the Home gives 25 words takes the old page's 70, and a place the old page has nothing for — the line over a title,
 * a sentence under it — is taken off, so the section is no longer the Home's (docs/PLAN-NETWORK-PAGES.md, 11.2).
 *
 * With an AI, the composer keeps every place and the Home's words in it (WAS: the measure), marks those the old page has
 * nothing for (WRITE), and this writes the page's words in one request: each place with the section it is in, what it is, the
 * words it holds now, how long the Home's words there are, and whether it is to be written; the old page's text and the site's
 * facts as the source. Each answer is held to its place's length (within 15 %; one more request shortens what came out longer),
 * nothing outside the source is to be invented, and a place nothing true can be written for is taken off — as the composer
 * would have. Without an AI, or when it does not answer, the composer's words stay and the marked places go: the page is what
 * it was before this.
 *
 * The marks never reach the saved page: every way out of write() takes them off.
 */
final class Slot_Writer {

	/** On a text place while a page is composed: the Home's words in it. */
	public const WAS = '__dxai_was';

	/** On a text place the old page had nothing for: the AI is to write it. */
	public const WRITE = '__dxai_write';

	/** How much longer than the Home's words a place may be before it is sent back to be shortened. */
	private const SLACK = 0.15;

	/** The text places written: name => what the AI is told it is. */
	private const PLACES = array(
		'core/heading'   => 'heading',
		'core/paragraph' => 'paragraph',
		'core/list-item' => 'list item',
		'dxai-ui/text'   => 'text',
	);

	/**
	 * Each text place of a section (a block with no blocks in it and words of its own) remembers the Home's words in it.
	 * Calls to action (links, buttons) are the composer's to write: their words come from the old page's own buttons.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function mark_was( array &$block ): void {
		if ( empty( $block['innerBlocks'] ) ) {
			if ( self::place( $block ) !== '' ) {
				$text = Block_Tree::own_text( $block );
				if ( $text !== '' ) {
					$block['attrs'][ self::WAS ] = $text;
				}
			}
			return;
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			if ( is_array( $inner ) ) {
				self::mark_was( $inner );
			}
		}
		unset( $inner );
	}

	/**
	 * The places that still hold the Home's words (nothing of the old page's was written in them) are marked to be written; their
	 * words, as the composer's sweep reads them, are answered so it keeps them.
	 *
	 * @param array<string, mixed> $block
	 * @param array<string, bool>  $allowed The words the composer wrote, lower case.
	 * @return array<int, string>
	 */
	public static function mark_write( array &$block, array $allowed ): array {
		$kept = array();
		if ( empty( $block['innerBlocks'] ) ) {
			$was = (string) ( $block['attrs'][ self::WAS ] ?? '' );
			$now = mb_strtolower( Block_Tree::own_text( $block ) );
			if ( $was !== '' && $now !== '' && ! isset( $allowed[ $now ] ) && preg_match( '/^[\s★☆✓✔✗+−–·•|→←↑↓\-\d%]+$/u', $now ) !== 1 ) {
				$block['attrs'][ self::WRITE ] = true;
				$kept[]                        = $now;
			}
			return $kept;
		}
		foreach ( $block['innerBlocks'] as &$inner ) {
			if ( is_array( $inner ) ) {
				array_push( $kept, ...self::mark_write( $inner, $allowed ) );
			}
		}
		unset( $inner );

		return $kept;
	}

	/**
	 * Write the page's words.
	 *
	 * @param array<int, array<string, mixed>> $sections  Page_Composer::compose()'s, composed with write_slots( true ).
	 * @param string                           $reference The old page's text.
	 * @param string                           $topic     What the page is about (its title).
	 * @param int                              $home      The design's Home (the site's facts are read from it).
	 * @param LLM_Provider_Interface|null      $engine    Null: no AI — the marked places go.
	 * @return array{sections: array<int, array<string, mixed>>, written: int, dropped: int, shortened: int, asked: int, error: string}
	 */
	public static function write( array $sections, string $reference, string $topic, int $home, ?LLM_Provider_Interface $engine ): array {
		$out  = array(
			'sections'  => $sections,
			'written'   => 0,
			'dropped'   => 0,
			'shortened' => 0,
			'asked'     => 0,
			'error'     => '',
		);
		$rows = self::rows( $sections );
		if ( $engine === null || $rows === array() ) {
			return self::finish( $out, $rows, array() );
		}
		$req  = self::request( $rows, $reference, $topic, $home );
		$text = $engine->complete( $req['system'], $req['user'], array( 'max_tokens' => 8000 ) );
		++$out['asked'];
		$got = is_wp_error( $text ) ? $text : Copy_Writer::parse( (string) $text );
		if ( is_wp_error( $got ) ) {
			$out['error'] = $got->get_error_message();

			return self::finish( $out, $rows, array() );
		}
		$answers = array();
		foreach ( $rows as $id => $row ) {
			if ( isset( $got['blocks'][ $id ] ) ) {
				$answers[ $id ] = trim( Copy_Writer::clean( (string) $got['blocks'][ $id ] ) );
			}
		}
		// What came out longer than its place: sent back once, to be shortened to the place's measure.
		$long = array();
		foreach ( $answers as $id => $answer ) {
			if ( $answer !== '' && self::words( $answer ) > self::most( (int) $rows[ $id ]['words'] ) ) {
				$long[ $id ] = $answer;
			}
		}
		if ( $long !== array() ) {
			$short = self::shorten( $engine, $long, $rows );
			++$out['asked'];
			foreach ( $short as $id => $answer ) {
				if ( $answer !== '' && self::words( $answer ) < self::words( $long[ $id ] ) ) {
					$answers[ $id ] = $answer;
					++$out['shortened'];
				}
			}
		}

		return self::finish( $out, $rows, $answers );
	}

	/**
	 * The places to write, by id: where each is, what it is, its words now, the Home's length there, whether it is to be written.
	 * A place that still holds the Home's words on purpose (the company's figures, kept by the composer) is not one: it has no
	 * WRITE mark and its words are the Home's.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return array<string, array{path: array<int, int>, section: string, kind: string, html: string, words: int, write: bool}>
	 */
	private static function rows( array $sections ): array {
		$rows = array();
		$n    = 0;
		foreach ( $sections as $i => $section ) {
			$head = self::heading_of( $section );
			$walk = static function ( array $b, array $path ) use ( &$walk, &$rows, &$n, $head ): void {
				if ( ! empty( $b['innerBlocks'] ) ) {
					foreach ( $b['innerBlocks'] as $k => $inner ) {
						if ( is_array( $inner ) ) {
							$walk( $inner, array_merge( $path, array( (int) $k ) ) );
						}
					}
					return;
				}
				$was = (string) ( $b['attrs'][ self::WAS ] ?? '' );
				if ( $was === '' ) {
					return;
				}
				$write = ! empty( $b['attrs'][ self::WRITE ] );
				$now   = Block_Tree::own_text( $b );
				// The Home's words kept on purpose, or a list of claims (one element, a row each): the composer's.
				if ( ( ! $write && $now === $was ) || Block_Tree::rows( $b ) !== null ) {
					return;
				}
				++$n;
				$rows[ 't' . $n ] = array(
					'path'    => $path,
					'section' => $head,
					'kind'    => self::place( $b ),
					'html'    => self::inner( $b ),
					'words'   => self::words( $was ),
					'write'   => $write,
				);
			};
			$walk( $section, array( (int) $i ) );
		}

		return $rows;
	}

	/**
	 * The request: the team's brief for the page, the old page's text as the source, and the places with their measure.
	 *
	 * @param array<string, array{path: array<int, int>, section: string, kind: string, html: string, words: int, write: bool}> $rows
	 * @return array{system: string, user: string}
	 */
	public static function request( array $rows, string $reference, string $topic, int $home ): array {
		$list = array();
		foreach ( $rows as $id => $row ) {
			$list[] = array(
				'id'      => $id,
				'section' => $row['section'],
				'kind'    => $row['kind'],
				'words'   => $row['words'],
				'write'   => $row['write'],
				'text'    => $row['write'] ? '' : $row['html'],
			);
		}
		$brief = $home > 0 ? Copy_Prompt::prompt( $home, array( 'topic' => $topic ), true ) : '';
		$user  = ( $brief !== '' ? $brief . "\n\n---\n" : '' )
			. "This page is built from the sections of the company's Home page. Each block below is one place of that design, and the design gives each place a length: `words` is how many words the Home has there. Write each block for this page's topic (" . $topic . "), from the old page's text below, in about that many words — never more than 15% over.\n"
			. "Blocks with text: say what they say, in their place's length (shorter when they run long, never padded with filler).\n"
			. "Blocks with \"write\": true have nothing yet (the old page has nothing for that place): write them from the old page's facts and the company's facts above, in the place's length. When nothing true can be said there, answer an empty text and the place is taken off.\n"
			. "Keep every fact exactly as the source has it (numbers, names, places, phone numbers, years). Do not invent services, prices, awards, guarantees or reviews. A heading stays a heading, a label a label. Do not add, remove or reorder blocks, and keep the ids.\n"
			. "Answer with one JSON object and nothing else: {\"blocks\":[{\"id\":\"t1\",\"text\":\"...\"}]}\n"
			. "\nTHE OLD PAGE'S TEXT:\n" . self::clip( $reference, 12000 )
			. "\n\nBLOCKS:\n" . wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return array(
			'system' => 'You are a careful website copywriter for local service companies. You follow the brief exactly and answer with valid JSON only.',
			'user'   => $user,
		);
	}

	/**
	 * Ask once more for the answers that came out too long, each to its place's measure.
	 *
	 * @param array<string, string>                 $long id => the answer.
	 * @param array<string, array<string, mixed>>   $rows
	 * @return array<string, string>
	 */
	private static function shorten( LLM_Provider_Interface $engine, array $long, array $rows ): array {
		$list = array();
		foreach ( $long as $id => $answer ) {
			$list[] = array(
				'id'        => $id,
				'kind'      => (string) $rows[ $id ]['kind'],
				'max_words' => self::most( (int) $rows[ $id ]['words'] ),
				'text'      => $answer,
			);
		}
		$text = $engine->complete(
			'You are a careful website copy editor. You answer with valid JSON only.',
			"Shorten each block to at most its max_words, keeping its meaning and every fact (numbers, names, places) exactly. Do not add anything.\n"
				. "Answer with one JSON object and nothing else: {\"blocks\":[{\"id\":\"t1\",\"text\":\"...\"}]}\n\nBLOCKS:\n"
				. wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			array( 'max_tokens' => 4000 )
		);
		$got = is_wp_error( $text ) ? $text : Copy_Writer::parse( (string) $text );
		if ( is_wp_error( $got ) ) {
			return array();
		}
		$out = array();
		foreach ( $got['blocks'] as $id => $answer ) {
			if ( isset( $long[ $id ] ) ) {
				$out[ $id ] = trim( Copy_Writer::clean( (string) $answer ) );
			}
		}

		return $out;
	}

	/**
	 * The answers in their places; a place to write that got nothing goes; every mark comes off.
	 *
	 * @param array{sections: array<int, array<string, mixed>>, written: int, dropped: int, shortened: int, asked: int, error: string} $out
	 * @param array<string, array{path: array<int, int>, section: string, kind: string, html: string, words: int, write: bool}>   $rows
	 * @param array<string, string>                                                                                                $answers
	 * @return array{sections: array<int, array<string, mixed>>, written: int, dropped: int, shortened: int, asked: int, error: string}
	 */
	private static function finish( array $out, array $rows, array $answers ): array {
		$sections = $out['sections'];
		$written  = array();
		foreach ( $rows as $id => $row ) {
			$answer = (string) ( $answers[ $id ] ?? '' );
			if ( trim( wp_strip_all_tags( $answer ) ) === '' ) {
				continue;
			}
			$path = $row['path'];
			$top  = (int) array_shift( $path );
			try {
				$node = &Block_Tree::at( $sections[ $top ], $path );
				Block_Tree::set_content( $node, $answer );
				unset( $node );
				++$out['written'];
				$written[] = $row['path'];
			} catch ( \RuntimeException $e ) {
				unset( $node );
			}
		}
		// A place to write nothing was written for (no AI, it did not answer, or nothing true could be said) goes, as the composer
		// would have taken it off.
		$drop = array();
		foreach ( $sections as $i => $section ) {
			foreach ( self::marked( $section, array( (int) $i ) ) as $path ) {
				if ( ! in_array( $path, $written, true ) ) {
					$drop[] = $path;
				}
			}
		}
		// The last first, a block before the box it is in: taking one out (and a box it leaves empty) moves none of the others.
		usort(
			$drop,
			static function ( array $a, array $b ): int {
				$n = min( count( $a ), count( $b ) );
				for ( $i = 0; $i < $n; $i++ ) {
					if ( $a[ $i ] !== $b[ $i ] ) {
						return $b[ $i ] <=> $a[ $i ];
					}
				}

				return count( $b ) <=> count( $a );
			}
		);
		foreach ( $drop as $path ) {
			$top = (int) array_shift( $path );
			if ( isset( $sections[ $top ] ) && $path !== array() ) {
				Block_Tree::remove( $sections[ $top ], $path );
				++$out['dropped'];
			}
		}
		foreach ( $sections as &$section ) {
			self::strip( $section );
		}
		unset( $section );
		$out['sections'] = array_values( $sections );

		return $out;
	}

	/**
	 * The paths of the places still marked to be written.
	 *
	 * @param array<string, mixed> $block
	 * @param array<int, int>      $path
	 * @return array<int, array<int, int>>
	 */
	private static function marked( array $block, array $path ): array {
		if ( empty( $block['innerBlocks'] ) ) {
			return ! empty( $block['attrs'][ self::WRITE ] ) ? array( $path ) : array();
		}
		$out = array();
		foreach ( $block['innerBlocks'] as $k => $inner ) {
			if ( is_array( $inner ) ) {
				array_push( $out, ...self::marked( $inner, array_merge( $path, array( (int) $k ) ) ) );
			}
		}

		return $out;
	}

	/**
	 * Every mark off a block and the blocks in it.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function strip( array &$block ): void {
		unset( $block['attrs'][ self::WAS ], $block['attrs'][ self::WRITE ] );
		foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $k => $inner ) {
			if ( is_array( $inner ) ) {
				self::strip( $block['innerBlocks'][ $k ] );
			}
		}
	}

	/**
	 * What a text place is ('' for a block that is none: a picture, a box, a link or a button).
	 *
	 * @param array<string, mixed> $b
	 */
	private static function place( array $b ): string {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) === 'a' ) {
			return '';
		}
		if ( isset( self::PLACES[ $name ] ) ) {
			return self::PLACES[ $name ];
		}

		return in_array( $name, Capabilities::blocks( 'span' ), true ) ? 'label' : '';
	}

	/** @param array<string, mixed> $b */
	private static function inner( array $b ): string {
		$html = trim( (string) ( $b['innerHTML'] ?? '' ) );
		if ( preg_match( '#^<([a-zA-Z][\w-]*)\b[^>]*>(.*)</\1>$#s', $html, $m ) === 1 ) {
			$html = $m[2];
		}
		$html = (string) preg_replace( '#<(\w+)\b[^>]*\baria-hidden(?:="(?:true)?"|(?=[\s/>]))[^>]*>.*?</\1>#s', '', $html );

		return trim( (string) preg_replace( '#</?(?!(?:strong|em|b|i|br)\b)[a-z][^>]*>#i', '', $html ) );
	}

	/**
	 * The words of a section's first heading.
	 *
	 * @param array<string, mixed> $section
	 */
	private static function heading_of( array $section ): string {
		$html = serialize_block( $section );
		if ( preg_match( '#<h[1-4]\b[^>]*>(.*?)</h[1-4]>#s', $html, $m ) === 1 ) {
			return trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		return '';
	}

	/** The most words a place of $words may hold. */
	private static function most( int $words ): int {
		return max( $words + 2, (int) ceil( $words * ( 1 + self::SLACK ) ) );
	}

	/** Words in a line of text or HTML. */
	public static function words( string $text ): int {
		$text = trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return $text === '' ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() );
	}

	/** At most $max characters of a text, cut at a word. */
	private static function clip( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );

		return mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) ) . ' …';
	}

	/**
	 * The text of an old page as the extractor read it (Content_Extractor::extract()): its headings, paragraphs, items, figures,
	 * questions and buttons, one per line.
	 *
	 * @param array<string, mixed> $model
	 */
	public static function text_of( array $model ): string {
		$lines = array();
		$walk  = static function ( $node ) use ( &$walk, &$lines ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( array( 'html', 'label', 'q', 'title', 'text', 'quote', 'name' ) as $key ) {
				if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
					$line = Content_Extractor::plain( $node[ $key ] );
					if ( $line !== '' ) {
						$lines[] = ( $key === 'label' && isset( $node['value'] ) && is_string( $node['value'] ) ? $node['value'] . ' ' : '' ) . $line;
					}
				}
			}
			foreach ( $node as $key => $child ) {
				if ( is_array( $child ) && ! in_array( $key, array( 'images', 'seo' ), true ) ) {
					$walk( $child );
				} elseif ( is_string( $child ) && is_int( $key ) ) {
					$line = Content_Extractor::plain( $child );
					if ( $line !== '' ) {
						$lines[] = $line;
					}
				}
			}
		};
		$walk( (array) ( $model['sections'] ?? array() ) );

		return implode( "\n", array_values( array_unique( $lines ) ) );
	}
}

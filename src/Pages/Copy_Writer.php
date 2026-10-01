<?php
/**
 * The words of a page, rewritten from the copy prompt.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Json_Repair;
use DXAI_UI\Engines\LLM_Provider_Interface;
use DXAI_UI\Structures\Live_Page_Restyler;

/**
 * The team's prompt (Copy_Prompt) asks an AI to go through a page's visible text and give back new copy block by
 * block. A person can paste that prompt into any assistant. This does the same through the engine chosen in Settings,
 * and puts the answer back on the page:
 *
 *  - the page's words are listed as numbered blocks (a heading, a paragraph, a list item, a button, a label), each with
 *    the section it sits in — nothing else about the page is sent or changed;
 *  - the AI answers with the new words for each id and a list of what did not fit (a wrong city, a placeholder review);
 *  - nothing is saved until a person applies it, block by block if they want;
 *  - the page keeps its content from before, and reverting puts it back, unless the page was edited since.
 *
 * Only the words change: the blocks, their classes and their styles stay as they are.
 */
final class Copy_Writer {

	/** On a page: its content before the copy was applied. */
	public const BEFORE = '_dxai_ui_before_copy';

	/** On a page: the hash of the content the copy wrote (a later edit is not undone). */
	public const HASH = '_dxai_ui_copy_hash';

	/** Blocks sent in one request. */
	private const PER_CALL = 140;

	/** Leaf blocks whose text is one element: name => kind. */
	private const LEAF = array(
		'core/heading'   => 'heading',
		'core/paragraph' => 'paragraph',
		'core/list-item' => 'list item',
		'amr/span'       => 'label',
		'dxai-ui/text'   => 'text',
		'core/button'    => 'button',
		'dxai-ui/link'   => 'link',
	);

	/**
	 * The words of a page, in reading order.
	 *
	 * @return array<int, array{id:string, section:string, kind:string, html:string, text:string}>
	 */
	public static function inventory( int $page_id ): array {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $page_id ) );
		$items  = array();
		$n      = 0;
		self::walk(
			$blocks,
			static function ( array &$b, string $section ) use ( &$items, &$n ): void {
				$leaf = self::leaf( $b );
				if ( $leaf === null ) {
					return;
				}
				++$n;
				$items[] = array(
					'id'      => 't' . $n,
					'section' => $section,
					'kind'    => $leaf['kind'],
					'html'    => $leaf['inner'],
					'text'    => trim( html_entity_decode( wp_strip_all_tags( $leaf['inner'] ), ENT_QUOTES, 'UTF-8' ) ),
				);
			}
		);

		return $items;
	}

	/** A fingerprint of the words the page has now: a proposal made for other words is not applied. */
	public static function fingerprint( array $items ): string {
		return md5( (string) wp_json_encode( array_map( static fn( $i ) => array( $i['id'], $i['html'] ), $items ) ) );
	}

	/**
	 * The request for the AI: the team's prompt, then the page's words as numbered blocks and how to answer.
	 *
	 * @param array<int, array{id:string, section:string, kind:string, html:string, text:string}> $items
	 * @param array{topic?:string, reference?:string}|null                                          $override
	 * @return array{system:string, user:string}
	 */
	public static function request( int $page_id, array $items, ?array $override = null, bool $facts = false, int $part = 1, int $parts = 1 ): array {
		$rows = array_map(
			static fn( $i ) => array(
				'id'      => $i['id'],
				'section' => $i['section'],
				'kind'    => $i['kind'],
				'text'    => $i['html'],
			),
			$items
		);
		$user = Copy_Prompt::prompt( $page_id, $override, $facts )
			. "\n\n---\n"
			. "You cannot open the page from here, so its visible text is listed below as numbered blocks: an id, the section it sits in, what kind of block it is, and its current text (the inline tags <strong>, <em> and <br> may appear in it).\n"
			. "Rewrite every block that does not fit the topic; return it unchanged when it already fits. Keep headings, labels and buttons about as long as they are. Do not add, remove or reorder blocks, and do not change the ids.\n"
			. "Answer with one JSON object and nothing else: {\"blocks\":[{\"id\":\"t1\",\"text\":\"...\"}],\"flags\":[\"one short note for each mismatch you noticed\"]}\n"
			. ( $parts > 1 ? sprintf( "This is part %d of %d of the page; the other parts are sent separately.\n", $part, $parts ) : '' )
			. "\nBLOCKS:\n" . wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return array(
			'system' => 'You are a careful website copywriter for local service companies. You follow the brief exactly and answer with valid JSON only.',
			'user'   => $user,
		);
	}

	/**
	 * Ask the engine and return what it proposes, without saving anything.
	 *
	 * @param array{topic?:string, reference?:string}|null $override
	 * @return array{engine:string, fingerprint:string, blocks:array<int, array<string, mixed>>, flags:array<int, string>, changed:int}|\WP_Error
	 */
	public static function propose( int $page_id, ?array $override = null, bool $facts = false ): array|\WP_Error {
		$items = self::inventory( $page_id );
		if ( $items === array() ) {
			return new \WP_Error( 'dxai_ui_copy_empty', __( 'This page has no text blocks to rewrite.', 'dxai-ui' ), array( 'status' => 400 ) );
		}
		$engine = self::engine();
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		$chunks = self::chunks( $items );
		$got    = array();
		$flags  = array();
		foreach ( $chunks as $i => $chunk ) {
			$req  = self::request( $page_id, $chunk, $override, $facts, $i + 1, count( $chunks ) );
			$text = $engine->complete( $req['system'], $req['user'], array( 'max_tokens' => 16000 ) );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			$parsed = self::parse( (string) $text );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$got   += $parsed['blocks'];
			$flags  = array_merge( $flags, $parsed['flags'] );
		}
		$out     = array();
		$changed = 0;
		foreach ( $items as $item ) {
			$after = isset( $got[ $item['id'] ] ) ? self::clean( $got[ $item['id'] ] ) : $item['html'];
			$diff  = $after !== $item['html'] && trim( wp_strip_all_tags( $after ) ) !== '';
			$changed += $diff ? 1 : 0;
			$out[]   = array(
				'id'      => $item['id'],
				'section' => $item['section'],
				'kind'    => $item['kind'],
				'before'  => $item['html'],
				'after'   => $diff ? $after : $item['html'],
				'changed' => $diff,
			);
		}

		return array(
			'engine'      => $engine->get_id(),
			'fingerprint' => self::fingerprint( $items ),
			'blocks'      => $out,
			'flags'       => array_values( array_unique( array_map( 'sanitize_text_field', $flags ) ) ),
			'changed'     => $changed,
		);
	}

	/**
	 * Put new words on the page.
	 *
	 * @param array<string, string> $changes     id => the new words.
	 * @param string                $fingerprint What self::fingerprint() said when the proposal was made.
	 * @return array{applied:int}|\WP_Error
	 */
	public static function apply( int $page_id, array $changes, string $fingerprint ): array|\WP_Error {
		$content = (string) get_post_field( 'post_content', $page_id );
		$items   = self::inventory( $page_id );
		if ( $fingerprint === '' || self::fingerprint( $items ) !== $fingerprint ) {
			return new \WP_Error( 'dxai_ui_copy_stale', __( 'The page changed since these words were written. Generate them again.', 'dxai-ui' ), array( 'status' => 409 ) );
		}
		$blocks  = parse_blocks( $content );
		$n       = 0;
		$applied = 0;
		self::walk(
			$blocks,
			static function ( array &$b, string $section ) use ( &$n, &$applied, $changes ): void {
				if ( self::leaf( $b ) === null ) {
					return;
				}
				++$n;
				$id = 't' . $n;
				if ( ! isset( $changes[ $id ] ) ) {
					return;
				}
				$new = self::clean( (string) $changes[ $id ] );
				if ( trim( wp_strip_all_tags( $new ) ) === '' ) {
					return;
				}
				if ( self::replace( $b, $new ) ) {
					++$applied;
				}
			}
		);
		if ( $applied === 0 ) {
			return array( 'applied' => 0 );
		}
		$new_content = serialize_blocks( $blocks );
		if ( get_post_meta( $page_id, self::BEFORE, true ) === '' ) {
			update_post_meta( $page_id, self::BEFORE, wp_slash( $content ) );
		}
		// The design's own content is written as it is: wp_update_post() would run KSES for someone without unfiltered HTML.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $page_id );
		// Written straight to the row: the rules cached for the page are for the content it had.
		\DXAI_UI\Blocks\Style_Rules::forget( $page_id );
		update_post_meta( $page_id, self::HASH, md5( $new_content ) );

		return array( 'applied' => $applied );
	}

	/** Whether the page has copy applied that can be put back. */
	public static function can_revert( int $page_id ): bool {
		$before = get_post_meta( $page_id, self::BEFORE, true );

		return is_string( $before ) && $before !== '';
	}

	/** Put the page's words back. False when there is nothing to put back, or the page was edited since. */
	public static function revert( int $page_id ): bool {
		$before = get_post_meta( $page_id, self::BEFORE, true );
		if ( ! is_string( $before ) || $before === '' ) {
			return false;
		}
		$now = (string) get_post_field( 'post_content', $page_id );
		if ( md5( $now ) !== (string) get_post_meta( $page_id, self::HASH, true ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $before ), array( 'ID' => $page_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $page_id );
		// Written straight to the row: the rules cached for the page are for the content it had.
		\DXAI_UI\Blocks\Style_Rules::forget( $page_id );
		delete_post_meta( $page_id, self::BEFORE );
		delete_post_meta( $page_id, self::HASH );

		return true;
	}

	/**
	 * The engine that answers: the one chosen in Settings (or the fallback the page restyler uses). A filter can give
	 * another, which is how this is tested without asking a provider.
	 */
	public static function engine(): LLM_Provider_Interface|\WP_Error {
		$given = apply_filters( 'dxai_ui_copy_engine', null );
		if ( $given instanceof LLM_Provider_Interface ) {
			return $given;
		}
		$engine = ( new Live_Page_Restyler() )->engine();
		if ( is_wp_error( $engine ) ) {
			return $engine;
		}
		if ( ! method_exists( $engine, 'complete' ) ) {
			return new \WP_Error( 'dxai_ui_copy_engine', __( 'The AI engine chosen in Settings cannot answer in plain text.', 'dxai-ui' ), array( 'status' => 501 ) );
		}

		return $engine;
	}

	/**
	 * The AI's answer as id => words and the notes.
	 *
	 * @return array{blocks:array<string, string>, flags:array<int, string>}|\WP_Error
	 */
	public static function parse( string $text ): array|\WP_Error {
		$data = Json_Repair::decode( $text );
		if ( ! is_array( $data ) || ! isset( $data['blocks'] ) || ! is_array( $data['blocks'] ) ) {
			return new \WP_Error( 'dxai_ui_copy_parse', __( 'The AI did not answer in the shape that was asked for, so nothing was changed.', 'dxai-ui' ), array( 'status' => 502 ) );
		}
		$blocks = array();
		foreach ( $data['blocks'] as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['text'] ) && is_string( $row['id'] ) && is_string( $row['text'] ) && preg_match( '/^t\d+$/', $row['id'] ) === 1 ) {
				$blocks[ $row['id'] ] = $row['text'];
			}
		}
		$flags = array();
		foreach ( is_array( $data['flags'] ?? null ) ? $data['flags'] : array() as $flag ) {
			if ( is_string( $flag ) && trim( $flag ) !== '' ) {
				$flags[] = trim( $flag );
			}
		}

		return array(
			'blocks' => $blocks,
			'flags'  => $flags,
		);
	}

	/**
	 * Only the tags a line of copy has: emphasis, a break, a link, a span (a mark in front of a line, hidden from readers
	 * of the page), and the paragraph and heading a list item can be made of (a step: its number, its title, its line).
	 */
	public static function clean( string $html ): string {
		$html = trim( $html );
		$html = wp_kses(
			$html,
			array(
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'u'      => array(),
				'br'     => array(),
				'span'   => array(
					'class'       => true,
					'aria-hidden' => true,
				),
				'p'      => array( 'class' => true ),
				'h1'     => array( 'class' => true ),
				'h2'     => array( 'class' => true ),
				'h3'     => array( 'class' => true ),
				'h4'     => array( 'class' => true ),
				'a'      => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
					'class'  => true,
				),
			)
		);

		return trim( $html );
	}

	/**
	 * The items in requests of a size the provider answers in full, cut between sections.
	 *
	 * @param array<int, array{id:string, section:string, kind:string, html:string, text:string}> $items
	 * @return array<int, array<int, array{id:string, section:string, kind:string, html:string, text:string}>>
	 */
	private static function chunks( array $items ): array {
		$out  = array();
		$cur  = array();
		$last = null;
		foreach ( $items as $item ) {
			if ( count( $cur ) >= self::PER_CALL && $item['section'] !== $last ) {
				$out[] = $cur;
				$cur   = array();
			}
			$cur[] = $item;
			$last  = $item['section'];
		}
		if ( $cur !== array() ) {
			$out[] = $cur;
		}

		return $out;
	}

	/**
	 * Every block in reading order, with the name of the section it is in. A page written as one group around its
	 * sections (a Claude Design export) has them one level down; the name is then the inner group's.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function walk( array &$blocks, callable $fn, int $depth = 0, string $section = '', int $at = -1, ?bool $wrapped = null ): void {
		if ( $wrapped === null ) {
			$real    = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
			$wrapped = count( $real ) === 1 && count( array_filter( (array) ( $real[0]['innerBlocks'] ?? array() ), static fn( $c ) => ! empty( $c['blockName'] ) ) ) >= 3 && empty( $real[0]['attrs']['metadata']['name'] );
			$at      = $wrapped ? 1 : 0;
		}
		$first = true;
		// The page's header and footer are the site's; their words are not the page's copy.
		$chrome = $depth === 0 ? array_merge( ...array_values( Section_Library::chrome_blocks( $blocks ) ) ) : array();
		foreach ( $blocks as $idx => &$b ) {
			if ( empty( $b['blockName'] ) || ( $depth === 0 && in_array( $idx, $chrome, true ) ) ) {
				continue;
			}
			$sec = $section;
			if ( $depth === $at ) {
				$name  = (string) ( $b['attrs']['metadata']['name'] ?? '' );
				$sec   = $name !== '' ? $name : ucfirst( Section_Roles::of( $b, $first ) );
				$first = false;
			}
			$fn( $b, $sec );
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], $fn, $depth + 1, $sec, $at, $wrapped );
			}
		}
		unset( $b );
	}

	/**
	 * The words of a block, when it is one the copy can be written into.
	 *
	 * @param array<string, mixed> $b
	 * @return array{kind:string, open:string, inner:string, close:string}|null
	 */
	private static function leaf( array $b ): ?array {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( ! isset( self::LEAF[ $name ] ) || ! empty( $b['innerBlocks'] ) ) {
			return null;
		}
		$content = is_array( $b['innerContent'] ?? null ) ? $b['innerContent'] : array();
		if ( count( $content ) !== 1 || ! is_string( $content[0] ) ) {
			return null;
		}
		$html = $content[0];
		if ( $name === 'core/button' || $name === 'dxai-ui/link' ) {
			if ( preg_match( '/^(.*?<a\b[^>]*>)(.*?)(<\/a>.*)$/s', $html, $m ) !== 1 ) {
				return null;
			}
		} elseif ( preg_match( '/^(\s*<(p|h[1-6]|li|span)\b[^>]*>)(.*)(<\/\2>\s*)$/s', $html, $m ) === 1 ) {
			$m = array( $m[0], $m[1], $m[3], $m[4] );
		} else {
			return null;
		}
		$inner = $m[2];
		// Icons and pictures are not words.
		if ( preg_match( '/<(?:svg|img|script|style|iframe)\b|data-lucide/i', $inner ) === 1 ) {
			return null;
		}
		if ( preg_match( '/\p{L}{2}/u', wp_strip_all_tags( $inner ) ) !== 1 ) {
			return null;
		}

		return array(
			'kind'  => self::LEAF[ $name ],
			'open'  => $m[1],
			'inner' => $inner,
			'close' => $m[3],
		);
	}

	/**
	 * Write new words into a leaf block: its markup and, for a link, the text attribute that repeats them.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function replace( array &$b, string $new ): bool {
		$leaf = self::leaf( $b );
		if ( $leaf === null ) {
			return false;
		}
		$html                  = $leaf['open'] . $new . $leaf['close'];
		$b['innerContent'][0]  = $html;
		$b['innerHTML']        = $html;
		if ( ( $b['blockName'] ?? '' ) === 'dxai-ui/link' && isset( $b['attrs']['text'] ) ) {
			$b['attrs']['text'] = trim( html_entity_decode( wp_strip_all_tags( $new ), ENT_QUOTES, 'UTF-8' ) );
		}

		return true;
	}
}

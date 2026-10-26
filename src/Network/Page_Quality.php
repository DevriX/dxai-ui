<?php
/**
 * How a page measures against the team's pages of its kind.
 *
 * @package DXAI_UI\Network
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * The quality gate of docs/PLAN-NETWORK-PAGES.md (N3), page by page: what the network's pages of the same kind have, and
 * what this one has —
 *
 *  sections   about as many as the team's pages of the kind (between the network's lower and upper quarter, one either way);
 *  words      no fewer than the network's lower quarter on sites whose Home is a design;
 *  questions  a FAQ with about as many questions as theirs (a service or a city page);
 *  name       its h1 says what the page is about (the service, the city), and so do its headings;
 *  to write   no line left for a person (a copy or a page made without an AI waits for its words);
 *  foreign    no other page's name in its own sections (a copy that still says the original's service);
 *  pictures   no picture that is the page's original's (a copy keeps them until a person changes them).
 *
 * Each check is ok, warn or bad, with what was found; the page is `good` with nothing bad and one warning at most, else
 * `check`. Nothing is changed.
 */
final class Page_Quality {

	/**
	 * The page measured.
	 *
	 * @return array{grade: string, kind: string, checks: array<int, array{id: string, state: string, text: string}>}
	 */
	public static function of( int $page_id ): array {
		$post = get_post( $page_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'grade'  => 'check',
				'kind'   => '',
				'checks' => array(),
			);
		}
		$kind    = Page_Copies::kind( $page_id );
		$content = (string) $post->post_content;
		$read    = Page_Reader::read( do_blocks( $content ) );
		$model   = Network_Model::get();
		$k       = is_array( $model ) ? ( $model['kinds'][ $kind ] ?? null ) : null;
		$title   = html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' );
		$name    = Page_Copies::names( $title, $kind )['full'];
		$checks  = array();
		$add     = static function ( string $id, string $state, string $text ) use ( &$checks ): void {
			$checks[] = array(
				'id'    => $id,
				'state' => $state,
				'text'  => $text,
			);
		};

		$n = count( $read['sections'] );
		if ( is_array( $k ) ) {
			$lo = max( 1, (int) ( $k['sections']['p25'] ?? 0 ) - 1 );
			$hi = (int) ( $k['sections']['p75'] ?? 99 ) + 1;
			/* translators: 1: sections of the page, 2: fewest, 3: most on the team's pages */
			$add( 'sections', $n >= $lo && $n <= $hi ? 'ok' : 'warn', sprintf( __( '%1$d sections (the team\'s pages of this kind have %2$d to %3$d)', 'dxai-ui' ), $n, $lo, $hi ) );
			$floor = (int) ( ( $k['words_design']['n'] ?? 0 ) > 0 ? ( $k['words_design']['p25'] ?? 0 ) : ( $k['words']['p25'] ?? 0 ) );
			/* translators: 1: words of the page, 2: words of the team's pages */
			$add( 'words', $read['words'] >= (int) round( 0.9 * $floor ) ? 'ok' : 'warn', sprintf( __( '%1$d words (the team\'s pages: %2$d or more)', 'dxai-ui' ), $read['words'], $floor ) );
			if ( in_array( $kind, array( 'service', 'location' ), true ) && ( $k['faq']['questions']['n'] ?? 0 ) > 0 ) {
				$q   = array_sum( array_column( array_filter( $read['sections'], static fn( array $s ): bool => $s['role'] === 'faq' ), 'questions' ) );
				$qlo = max( 1, (int) ( $k['faq']['questions']['p25'] ?? 0 ) - 1 );
				$qhi = (int) ( $k['faq']['questions']['p75'] ?? 99 ) + 2;
				/* translators: 1: questions of the page, 2: fewest, 3: most on the team's pages */
				$add( 'questions', $q >= $qlo && $q <= $qhi ? 'ok' : ( $q === 0 ? 'bad' : 'warn' ), sprintf( __( '%1$d questions in its FAQ (the team\'s: %2$d to %3$d)', 'dxai-ui' ), $q, $qlo, $qhi ) );
			}
		}

		if ( $name !== '' && in_array( $kind, array( 'service', 'location' ), true ) ) {
			$says  = static fn( string $text ): bool => mb_stripos( $text, $name ) !== false || ( Page_Copies::names( $title, $kind )['short'] !== '' && mb_stripos( $text, Page_Copies::names( $title, $kind )['short'] ) !== false );
			$heads = array_filter( array_column( $read['sections'], 'head' ), static fn( string $h ): bool => $h !== '' );
			$named = count( array_filter( $heads, $says ) );
			if ( $says( $read['h1'] ) ) {
				/* translators: 1: headings that name it, 2: what the page is about */
				$add( 'name', $named >= 2 ? 'ok' : 'warn', sprintf( __( '%1$d of its headings say %2$s, its h1 too', 'dxai-ui' ), $named, $name ) );
			} else {
				/* translators: %s: what the page is about */
				$add( 'name', 'bad', sprintf( __( 'its h1 does not say %s', 'dxai-ui' ), $name ) );
			}
		}

		$places = preg_match_all( '/"placeholder":"(?:Write for |A question about )/', $content );
		/* translators: %d: lines left for a person */
		$add( 'to-write', $places === 0 ? 'ok' : 'bad', $places === 0 ? __( 'no line left to write', 'dxai-ui' ) : sprintf( _n( '%d line still to write', '%d lines still to write', $places, 'dxai-ui' ), $places ) );

		$of = (int) get_post_meta( $page_id, Page_Copies::OF, true );
		if ( $of > 0 ) {
			$original = Page_Copies::names( html_entity_decode( (string) get_the_title( $of ), ENT_QUOTES, 'UTF-8' ), $kind );
			$foreign  = $original['full'] !== '' && $original['full'] !== $name && mb_stripos( self::own_text( $page_id ), $original['full'] ) !== false;
			/* translators: %s: the original's service or city */
			$add( 'foreign', $foreign ? 'bad' : 'ok', $foreign ? sprintf( __( 'its own sections still say %s', 'dxai-ui' ), $original['full'] ) : __( 'no other page\'s name in its own sections', 'dxai-ui' ) );
			$mine = self::pictures( $content );
			$same = array_intersect( $mine, self::pictures( (string) get_post_field( 'post_content', $of ) ) );
			/* translators: %d: pictures */
			$add( 'pictures', $same === array() ? 'ok' : 'warn', $same === array() ? __( 'its pictures are its own', 'dxai-ui' ) : sprintf( _n( '%d picture is the original\'s', '%d pictures are the original\'s', count( $same ), 'dxai-ui' ), count( $same ) ) );
		}

		// Before a page goes live (docs/PLAN-NETWORK-PAGES.md, 11.5): it is its Home's build, every class of it has its rule, no
		// place of it is empty, its words are about as long as the Home's in the same place.
		$home = (int) get_post_meta( $page_id, \DXAI_UI\Structures\Page_Scope::META, true );
		if ( $home > 0 && $home !== $page_id && get_post_type( $home ) === 'page' ) {
			foreach ( self::gates( $page_id, $home, $content, $read ) as $gate ) {
				$add( $gate['id'], $gate['state'], $gate['text'] );
			}
		}

		$bad  = count( array_filter( $checks, static fn( array $c ): bool => $c['state'] === 'bad' ) );
		$warn = count( array_filter( $checks, static fn( array $c ): bool => $c['state'] === 'warn' ) );

		return array(
			'grade'  => $bad === 0 && $warn <= 1 ? 'good' : 'check',
			'kind'   => $kind,
			'checks' => $checks,
		);
	}

	/** @var array<string, array{builds: array<string, true>, shapes: array<string, int>, names: array<int, string>}> A Home's measure, by Home and content. */
	private static array $homes = array();

	/**
	 * The checks a page of a design passes before it goes live, against its Home:
	 *
	 *  build    every section is one of the Home's builds (its block, its tag and its classes, the ground and the per-block
	 *           helpers aside) — a section of another look stands out on the page;
	 *  rules    no class of the page is taken for one the design styles itself (its recorded names): a name recorded that the
	 *           Home has not gets no rule, and the page breaks on a phone (H2O Away's crawled pages, +167 px sideways);
	 *  empty    no place left empty: a heading or a line with no words, a button with none, a picture with no file;
	 *  measure  a section built like one of the Home's holds about as many words (at most 1.6 times): a card of 25 words on the
	 *           Home is not 70 here.
	 *
	 * @param array{sections: array<int, array<string, mixed>>, words: int, h1: string} $read Page_Reader::read() of the page.
	 * @return array<int, array{id: string, state: string, text: string}>
	 */
	public static function gates( int $page_id, int $home, string $content, array $read ): array {
		$h   = self::home_measure( $home );
		$out = array();

		$sections = self::top( $content );
		$foreign  = count( array_filter( $sections, static fn( array $b ): bool => ! isset( $h['builds'][ self::build( $b ) ] ) ) );
		$out[]    = array(
			'id'    => 'build',
			'state' => $foreign === 0 ? 'ok' : 'warn',
			'text'  => $foreign === 0
				? __( 'every section is built like one of the Home\'s', 'dxai-ui' )
				/* translators: 1: sections not built like the Home's, 2: sections */
				: sprintf( _n( '%1$d of %2$d sections is not built like any of the Home\'s', '%1$d of %2$d sections are not built like any of the Home\'s', $foreign, 'dxai-ui' ), $foreign, count( $sections ) ),
		);

		$names  = get_post_meta( $page_id, \DXAI_UI\Compiler\Style_Hoister::DESIGN_NAMES_META, true );
		$names  = is_array( $names ) ? array_map( 'strval', $names ) : array();
		$orphan = array_values( array_diff( $names, $h['names'] ) );
		$out[]  = array(
			'id'    => 'rules',
			'state' => $orphan === array() ? 'ok' : 'bad',
			'text'  => $orphan === array()
				? __( 'every class has its rule', 'dxai-ui' )
				/* translators: 1: classes, 2: a few of them */
				: sprintf( _n( '%1$d class has no rule (%2$s): the page can break on a phone', '%1$d classes have no rule (%2$s): the page can break on a phone', count( $orphan ), 'dxai-ui' ), count( $orphan ), implode( ', ', array_slice( $orphan, 0, 4 ) ) ),
		);

		$empty = 0;
		foreach ( $sections as $b ) {
			$empty += self::empty_places( $b );
		}
		$out[] = array(
			'id'    => 'empty',
			'state' => $empty === 0 ? 'ok' : 'warn',
			/* translators: %d: empty places */
			'text'  => $empty === 0 ? __( 'no place is empty', 'dxai-ui' ) : sprintf( _n( '%d place is empty', '%d places are empty', $empty, 'dxai-ui' ), $empty ),
		);

		$long = 0;
		foreach ( (array) $read['sections'] as $s ) {
			$shape = (string) ( $s['shape_hash'] ?? '' );
			if ( isset( $h['shapes'][ $shape ] ) && $h['shapes'][ $shape ] >= 10 && (int) ( $s['words'] ?? 0 ) > 1.6 * $h['shapes'][ $shape ] ) {
				++$long;
			}
		}
		$out[] = array(
			'id'    => 'measure',
			'state' => $long === 0 ? 'ok' : 'warn',
			'text'  => $long === 0
				? __( 'its words fit the Home\'s places', 'dxai-ui' )
				/* translators: %d: sections */
				: sprintf( _n( '%d section runs past the Home\'s length for it', '%d sections run past the Home\'s length for them', $long, 'dxai-ui' ), $long ),
		);

		return $out;
	}

	/**
	 * What a page of a design is held to, read once from its Home: its builds, the words of each of its section shapes, the
	 * names it records as its own.
	 *
	 * @return array{builds: array<string, true>, shapes: array<string, int>, names: array<int, string>}
	 */
	private static function home_measure( int $home ): array {
		$content = (string) get_post_field( 'post_content', $home );
		$key     = $home . '@' . md5( $content );
		if ( ! isset( self::$homes[ $key ] ) ) {
			$builds = array();
			foreach ( self::top( $content ) as $b ) {
				$builds[ self::build( $b ) ] = true;
			}
			$shapes = array();
			foreach ( Page_Reader::read( do_blocks( $content ) )['sections'] as $s ) {
				$shape = (string) ( $s['shape_hash'] ?? '' );
				if ( $shape !== '' && ! isset( $shapes[ $shape ] ) ) {
					$shapes[ $shape ] = (int) ( $s['words'] ?? 0 );
				}
			}
			$names               = get_post_meta( $home, \DXAI_UI\Compiler\Style_Hoister::DESIGN_NAMES_META, true );
			self::$homes[ $key ] = array(
				'builds' => $builds,
				'shapes' => $shapes,
				'names'  => is_array( $names ) ? array_map( 'strval', $names ) : array(),
			);
		}

		return self::$homes[ $key ];
	}

	/**
	 * A page's sections: its top-level blocks, or the blocks of the one box that holds them all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function top( string $content ): array {
		$real = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
		if ( count( $real ) === 1 && count( array_filter( (array) $real[0]['innerBlocks'], static fn( $b ) => ! empty( $b['blockName'] ) ) ) >= 3 && empty( $real[0]['attrs']['metadata']['name'] ) ) {
			return array_values( array_filter( (array) $real[0]['innerBlocks'], static fn( $b ) => ! empty( $b['blockName'] ) ) );
		}

		return $real;
	}

	/**
	 * A section's build: its block, its tag and its classes — without its ground (the pages trade the Home's grounds) and the
	 * importer's per-block helpers.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function build( array $b ): string {
		$classes = preg_split( '/\s+/', trim( (string) ( $b['attrs']['className'] ?? '' ) ) ) ?: array();
		$classes = array_values( array_filter( $classes, static fn( string $c ): bool => $c !== '' && preg_match( '/^(?:dxai-sh-\d+|dxs-[a-z0-9]+|(?:(?:sm|md|lg|xl|2xl)[:-])*bg-[\w.-]+|has-[\w-]+-background-color|has-background)$/', $c ) !== 1 ) );
		sort( $classes );

		return (string) ( $b['blockName'] ?? '' ) . '|' . strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ) . '|' . implode( ' ', $classes );
	}

	/**
	 * The places of a block left empty: a heading, a line or a list item with no words (and no picture or drawing in it), a button
	 * or a link with no words, a picture with no file.
	 *
	 * @param array<string, mixed> $b
	 */
	private static function empty_places( array $b ): int {
		$name = (string) ( $b['blockName'] ?? '' );
		if ( ! empty( $b['innerBlocks'] ) ) {
			$n = 0;
			foreach ( (array) $b['innerBlocks'] as $inner ) {
				if ( is_array( $inner ) ) {
					$n += self::empty_places( $inner );
				}
			}
			return $n;
		}
		$html = (string) ( $b['innerHTML'] ?? '' );
		if ( in_array( $name, array( 'core/heading', 'core/paragraph', 'core/list-item', 'core/button' ), true ) ) {
			$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return $text === '' && preg_match( '/<(?:img|svg|iframe|video)\b/i', $html ) !== 1 && ! isset( $b['attrs']['placeholder'] ) ? 1 : 0;
		}
		if ( in_array( $name, array( 'core/image', 'dx/picture' ), true ) ) {
			// core/image keeps its file in `url` and `id`, the DX Picture in `imageUrl` and `imageId`.
			$src = (string) ( $b['attrs']['url'] ?? ( $b['attrs']['imageUrl'] ?? '' ) );
			if ( $src === '' && preg_match( '/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $m ) === 1 ) {
				$src = $m[1];
			}
			return $src === '' && (int) ( $b['attrs']['id'] ?? ( $b['attrs']['imageId'] ?? 0 ) ) < 1 ? 1 : 0;
		}

		return 0;
	}

	/**
	 * The words of a page's own sections (Page_Copies::sections() 'page'), the shared ones and the list of the others left out.
	 */
	private static function own_text( int $page_id ): string {
		$blocks = parse_blocks( (string) get_post_field( 'post_content', $page_id ) );
		$plan   = Page_Copies::sections( $page_id );
		$real   = array_values( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$list   = count( $real ) === 1 && count( array_filter( (array) $real[0]['innerBlocks'], static fn( $b ) => ! empty( $b['blockName'] ) ) ) >= 3 && empty( $real[0]['attrs']['metadata']['name'] )
			? array_values( array_filter( (array) $real[0]['innerBlocks'], static fn( $b ) => ! empty( $b['blockName'] ) ) )
			: $real;
		$out = '';
		foreach ( $list as $i => $b ) {
			if ( ( $plan[ $i ]['what'] ?? '' ) === 'page' ) {
				$out .= ' ' . wp_strip_all_tags( render_block( $b ) );
			}
		}

		return $out;
	}

	/**
	 * The pictures a page's markup shows, by their file.
	 *
	 * @return array<int, string>
	 */
	private static function pictures( string $content ): array {
		preg_match_all( '/<img\b[^>]*\bsrc="([^"]+)"/i', $content, $m );

		return array_values( array_unique( array_map( static fn( string $u ): string => (string) preg_replace( '/-\d+x\d+(?=\.[a-z]+$)/i', '', strtok( $u, '?' ) ), $m[1] ) ) );
	}
}

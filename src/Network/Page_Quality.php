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

		$bad  = count( array_filter( $checks, static fn( array $c ): bool => $c['state'] === 'bad' ) );
		$warn = count( array_filter( $checks, static fn( array $c ): bool => $c['state'] === 'warn' ) );

		return array(
			'grade'  => $bad === 0 && $warn <= 1 ? 'good' : 'check',
			'kind'   => $kind,
			'checks' => $checks,
		);
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

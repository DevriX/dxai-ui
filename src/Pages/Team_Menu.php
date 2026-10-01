<?php
/**
 * The menu of a design's pages: its header and footer links point at the pages made for it.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Blocks\Native\Native_Blocks;
use DXAI_UI\Structures\Page_Scope;

/**
 * A design keeps its menu in its own header and footer (links in blocks, not a WordPress menu), and every page made for
 * it (Team_Pages) carries a copy. The links of the design pointed at the old site (`cleanjoe.com/services/water-damage`)
 * or at a section of the Home (`#services`); once the pages exist the menu should open them. A link is pointed at a page
 * when its words are that page's — its title ("Mold Remediation"), the start of it ("Water Damage"), a place ("Revere,
 * MA"), or what a menu calls a general page ("About", "FAQ", "Reviews", "Free Estimate") — and it now leads away from the
 * design (another site, or a section of the Home). Nothing else is touched: phone numbers, social links, a link to a page
 * the person chose, links in the body of a page.
 *
 * Only the links change. A page's words and layout stay, and the records that tell a person's edit from the plugin's
 * (the Copy_Writer hash, the native conversion record, the Team_Pages hash) follow the new content when they matched the
 * old, so putting the earlier words back, or making the pages again, still works.
 */
final class Team_Menu {

	/** What a menu calls a general page, by the kind of page made. */
	private const LABELS = array(
		'about'        => array( 'about', 'about us', 'our story', 'who we are' ),
		'contact'      => array( 'contact', 'contact us', 'free estimate', 'get a free estimate', 'request a free estimate', 'free quote', 'get a quote' ),
		'faq'          => array( 'faq', 'faqs', "faq's", 'frequently asked questions', 'questions' ),
		'testimonials' => array( 'reviews', 'testimonials', 'customer reviews' ),
		'services'     => array( 'services', 'our services', 'all services', 'additional services' ),
		'areas'        => array( 'service areas', 'service area', 'areas', 'areas we serve', 'locations', 'all communities', 'all locations', 'communities' ),
	);

	/** Elements that make a part of the header or footer: a link inside one is a menu link. */
	private const CHROME_TAGS = array( 'header', 'footer', 'nav' );

	/**
	 * Point the design's header and footer links at its pages: on the Home and on each page made for it.
	 *
	 * @return array{pages:int, links:int}
	 */
	public static function link( int $home ): array {
		$targets = self::targets( $home );
		$sum     = array(
			'pages' => 0,
			'links' => 0,
		);
		if ( $targets === array() ) {
			return $sum;
		}
		$ctx = array(
			'targets' => $targets,
			'home'    => $home,
			'site'    => strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'paths'   => array( '/', '/' . trim( (string) get_page_uri( $home ), '/' ) . '/' ),
			'url'     => (string) get_permalink( $home ),
		);
		foreach ( array_merge( array( $home ), array_keys( $targets ) ) as $id ) {
			$id      = (int) $id;
			$content = (string) get_post_field( 'post_content', $id );
			if ( $content === '' || ! str_contains( $content, '<a ' ) ) {
				continue;
			}
			$blocks = parse_blocks( $content );
			// A page that does not come back byte for byte is not rewritten: a link is not worth a different page.
			if ( serialize_blocks( $blocks ) !== $content ) {
				continue;
			}
			$changed = 0;
			self::walk( $blocks, $ctx, false, $changed );
			if ( $changed === 0 ) {
				continue;
			}
			self::save( $id, $home, $content, serialize_blocks( $blocks ) );
			++$sum['pages'];
			$sum['links'] += $changed;
		}

		return $sum;
	}

	/**
	 * The pages made for the design.
	 *
	 * @return array<int, array{type:string, title:string, norm:string, url:string, place:string}> id => page
	 */
	private static function targets( int $home ): array {
		$out = array();
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => Team_Pages::META,
						'compare' => 'EXISTS',
					),
					array(
						'key'   => Page_Scope::META,
						'value' => (string) $home,
					),
				),
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$type  = explode( '|', (string) get_post_meta( (int) $id, Team_Pages::META, true ) )[0];
			$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( (int) $id ) ), ENT_QUOTES, 'UTF-8' ) );
			$norm  = self::norm( $title );
			$place = '';
			if ( $type === 'location' && preg_match( '/ in (.+)$/u', $norm, $m ) === 1 ) {
				$place = $m[1];
			}
			$out[ (int) $id ] = array(
				'type'  => $type,
				'title' => $title,
				'norm'  => $norm,
				'url'   => (string) get_permalink( (int) $id ),
				'place' => $place,
			);
		}

		return $out;
	}

	/** Words as a menu writes them, compared: lower case, one space, no arrow of a dropdown, no HTML. */
	private static function norm( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = str_replace( array( '▼', '▾', '▲', '▴', '›', '»' ), ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
	}

	/**
	 * Every block, with whether it sits in a header, a footer or a nav; a link in one of those is looked at.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<string, mixed>             $ctx
	 */
	private static function walk( array &$blocks, array $ctx, bool $in_chrome, int &$changed ): void {
		foreach ( $blocks as &$b ) {
			if ( empty( $b['blockName'] ) ) {
				continue;
			}
			$here = $in_chrome || in_array( strtolower( (string) ( $b['attrs']['tagName'] ?? '' ) ), self::CHROME_TAGS, true );
			if ( $here && self::retarget( $b, $ctx ) ) {
				++$changed;
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], $ctx, $here, $changed );
			}
		}
		unset( $b );
	}

	/**
	 * Point one link block at its page, when it is a link to one.
	 *
	 * @param array<string, mixed> $b
	 * @param array<string, mixed> $ctx
	 */
	private static function retarget( array &$b, array $ctx ): bool {
		$html = (string) ( $b['innerHTML'] ?? '' );
		// A block with one link of its own: the link block, the text or box written as an <a>, a list item with its link.
		if ( substr_count( $html, '<a ' ) !== 1 || preg_match( '/<a\b[^>]*\bhref="([^"]*)"/', $html, $m ) !== 1 ) {
			return false;
		}
		$raw = $m[1];
		$old = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! self::leads_away( $old, $ctx ) ) {
			return false;
		}
		$text = self::norm( $html );
		$new  = '';
		if ( $text === '' ) {
			// The logo: a link around a picture, to the Home's top.
			$new = self::has_image( $b ) ? $ctx['url'] : '';
		} else {
			$new = self::page_for( $text, $ctx['targets'] );
		}
		if ( $new === '' || $new === $old ) {
			return false;
		}
		$swap = static function ( string $s ) use ( $raw, $new ): string {
			$from = 'href="' . $raw . '"';
			$at   = strpos( $s, $from );

			return $at === false ? $s : substr_replace( $s, 'href="' . esc_url( $new ) . '"', $at, strlen( $from ) );
		};
		$b['innerHTML'] = $swap( $html );
		foreach ( (array) $b['innerContent'] as $k => $part ) {
			if ( is_string( $part ) && str_contains( $part, 'href="' . $raw . '"' ) ) {
				$b['innerContent'][ $k ] = $swap( $part );
				break;
			}
		}
		// A block writes its href from `url`: they have to say the same, or the editor opens it as invalid.
		if ( array_key_exists( 'url', (array) $b['attrs'] ) ) {
			$b['attrs']['url'] = esc_url( $new );
		}

		return true;
	}

	/** Whether a link leaves the design: another site, or a section of the Home. A link to another page of the site stays. */
	private static function leads_away( string $url, array $ctx ): bool {
		if ( $url === '' || preg_match( '#^(tel|mailto|sms|javascript):#i', $url ) === 1 ) {
			return false;
		}
		if ( $url[0] === '#' ) {
			return true;
		}
		$p    = wp_parse_url( $url );
		$host = strtolower( (string) ( $p['host'] ?? '' ) );
		if ( $host === '' ) {
			return false;
		}
		if ( $host !== $ctx['site'] ) {
			return true;
		}
		$path = '/' . trim( (string) ( $p['path'] ?? '/' ), '/' ) . '/';

		return in_array( str_replace( '//', '/', $path ), $ctx['paths'], true );
	}

	/**
	 * The page a menu link with these words leads to, or ''.
	 *
	 * @param array<int, array{type:string, title:string, norm:string, url:string, place:string}> $targets
	 */
	private static function page_for( string $text, array $targets ): string {
		// Its title, or what comes of it in a footer: the start of a service's ("Water Damage" for "Water Damage Restoration").
		foreach ( $targets as $t ) {
			if ( $t['norm'] === $text ) {
				return $t['url'];
			}
		}
		foreach ( $targets as $t ) {
			if ( $t['type'] === 'service' && substr_count( $text, ' ' ) >= 1 && str_starts_with( $t['norm'], $text . ' ' ) ) {
				return $t['url'];
			}
		}
		// A place ("Revere, MA", or "Revere, MA7 Franklin St…" with its address after it).
		foreach ( $targets as $t ) {
			if ( $t['place'] !== '' && str_starts_with( $text, $t['place'] ) ) {
				return $t['url'];
			}
		}
		foreach ( self::LABELS as $type => $labels ) {
			if ( ! in_array( $text, $labels, true ) ) {
				continue;
			}
			foreach ( $targets as $t ) {
				if ( $t['type'] === $type ) {
					return $t['url'];
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $b */
	private static function has_image( array $b ): bool {
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $c ) {
			if ( in_array( (string) ( $c['blockName'] ?? '' ), array( 'core/image', 'dxai-ui/image' ), true ) || self::has_image( (array) $c ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write the page's new content, and keep the records of what the plugin wrote in step with it.
	 */
	private static function save( int $id, int $home, string $before, string $after ): void {
		global $wpdb;
		$was = md5( $before );
		$now = md5( $after );
		// The design's own content is written as it is: wp_update_post() would run KSES for someone without unfiltered HTML.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $after ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_post_cache( $id );
		// Written straight to the row: the rules cached for the page are for the content it had.
		\DXAI_UI\Blocks\Style_Rules::forget( $id );
		foreach ( array( Team_Pages::HASH, Copy_Writer::HASH ) as $key ) {
			if ( (string) get_post_meta( $id, $key, true ) === $was ) {
				update_post_meta( $id, $key, $now );
			}
		}
		$record = get_post_meta( $home, Native_Blocks::META, true );
		if ( is_array( $record ) && isset( $record['posts'][ $id ] ) && (string) $record['posts'][ $id ] === $was ) {
			$record['posts'][ $id ] = $now;
			update_post_meta( $home, Native_Blocks::META, $record );
		}
	}
}

<?php
/**
 * The prompt that asks an AI for the words of a page, filled in from the page.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Color_Usage;

/**
 * The team writes the copy of a new page with one prompt: look at the staging page, know what the page is about, take
 * the production page as inspiration, rewrite every visible block, flag what does not fit. It had three blanks filled
 * by hand (the staging URL, the topic, the reference page). Here the blanks come from the page: its address, its
 * title, and the address of the page it was made after on the old site (the Home remembers the old site's origin;
 * a page built from an old page remembers its route).
 *
 * The words of the prompt are the team's, unchanged. Only the two lines about the reference page leave when there is
 * no reference page. What a person types into a field is kept on the page and wins over what is worked out.
 */
final class Copy_Prompt {

	/** On a page: the topic and the reference address a person set. */
	public const META = '_dxai_ui_copy_prompt';

	/**
	 * The prompt, with the three blanks as {STAGING}, {TOPIC} and {REFERENCE}.
	 */
	public static function template(): string {
		return <<<'PROMPT'
Please inspect this staging page and analyze every piece of visible text:
{STAGING}
This page should be about:
{TOPIC}
Use this production page as content inspiration where relevant:
{REFERENCE}
I need you to rewrite/populate all visible content blocks so the staging page fully matches the intended topic. Keep the current page structure and section order where possible, but replace any placeholder/template content that does not belong.
Pay special attention to:

* The hero section
* Trust/accreditation badges
* Service/benefit cards
* Process sections
* Review/testimonial sections
* Insurance/support sections
* Service area/location sections
* Related services
* FAQ section
* Final CTA

Important: make sure all accreditations, company details, phone numbers, office locations, service areas, review counts, emergency response claims, certifications, and coverage areas are updated based on the company/page at hand, not copied from placeholder content or another brand/location.
Flag any obvious mismatches you notice, such as wrong city/state references, incorrect phone numbers, unrelated services, copied FAQs, inconsistent ratings, incorrect service areas, or placeholder testimonials.
Then provide the full replacement copy block by block in a clean, paste-ready format.
PROMPT;
	}

	/**
	 * What a page is, from its title and address: a service, a location, or one of the pages every site has.
	 */
	public static function kind( string $title, string $slug ): string {
		$hay = strtolower( $title . ' ' . $slug );
		if ( preg_match( '/^(?:our\s+)?services?$/i', trim( $title ) ) === 1 ) {
			return 'services';
		}
		foreach ( array(
			'faq'          => '/\bfaqs?\b|frequently asked|questions/',
			'contact'      => '/\bcontact\b/',
			'about'        => '/\babout\b|our story|our team|who we are/',
			'testimonials' => '/testimonial|reviews?\b|customer stories/',
			'privacy'      => '/privacy|terms|cookie|disclaimer/',
			'sitemap'      => '/sitemap/',
			'blog'         => '/\bblog\b|\bnews\b|articles?/',
			'areas'        => '/service[- ]areas?|locations?\b|areas we serve/',
			'services'     => '/all services/',
		) as $kind => $pattern ) {
			if ( preg_match( $pattern, $hay ) === 1 ) {
				return $kind;
			}
		}
		// "Water Damage in Forest, VA" or "water-damage-forest-va": a place with a state after it.
		$states = 'al|ak|az|ar|ca|co|ct|de|fl|ga|hi|id|il|in|ia|ks|ky|la|me|md|ma|mi|mn|ms|mo|mt|ne|nv|nh|nj|nm|ny|nc|nd|oh|ok|or|pa|ri|sc|sd|tn|tx|ut|vt|va|wa|wv|wi|wy';
		if ( preg_match( '/,\s*[A-Z]{2}\b/', $title ) === 1 || preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)+-(?:' . $states . ')$/', $slug ) === 1 ) {
			return 'location';
		}
		return 'service';
	}

	/**
	 * Company facts the Home carries: its name, phone numbers, e-mail addresses and the old site's address.
	 *
	 * @return array{name:string, phones:array<int, string>, emails:array<int, string>, origin:string}
	 */
	public static function facts( int $home ): array {
		$text   = '';
		$phones = array();
		$emails = array();
		foreach ( Color_Usage::posts( $home ) as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			if ( preg_match_all( '/href="tel:([^"]+)"/i', $content, $m ) ) {
				foreach ( $m[1] as $tel ) {
					$tel = trim( rawurldecode( html_entity_decode( $tel ) ) );
					if ( $tel !== '' ) {
						$phones[ preg_replace( '/\D+/', '', $tel ) ?: $tel ] = $tel;
					}
				}
			}
			if ( preg_match_all( '/href="mailto:([^"?]+)/i', $content, $m ) ) {
				foreach ( $m[1] as $mail ) {
					$emails[ strtolower( $mail ) ] = $mail;
				}
			}
		}
		unset( $text );

		return array(
			'name'   => trim( html_entity_decode( wp_strip_all_tags( get_the_title( $home ) ), ENT_QUOTES, 'UTF-8' ) ),
			'phones' => array_values( array_slice( $phones, 0, 4 ) ),
			'emails' => array_values( array_slice( $emails, 0, 3 ) ),
			'origin' => (string) get_post_meta( $home, Site_Pages::ORIGIN_META, true ),
		);
	}

	/**
	 * What the prompt would say for a page, worked out from the page and then overridden by what was saved.
	 *
	 * @return array{id:int, title:string, slug:string, kind:string, staging:string, topic:string, reference:string, reference_guessed:bool, saved:bool}
	 */
	public static function fields( int $page_id ): array {
		$post  = get_post( $page_id );
		$title = $post instanceof \WP_Post ? trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) ) : '';
		$slug  = $post instanceof \WP_Post ? (string) $post->post_name : '';
		$home  = self::home_of( $page_id );
		$orig  = $home > 0 ? rtrim( (string) get_post_meta( $home, Site_Pages::ORIGIN_META, true ), '/' ) : '';

		$route     = trim( (string) get_post_meta( $page_id, '_dxai_ui_source_route', true ), '/' );
		$reference = '';
		$guessed   = false;
		if ( $orig !== '' ) {
			if ( $route !== '' ) {
				$reference = $orig . '/' . $route . '/';
			} elseif ( $home !== $page_id && $slug !== '' ) {
				$reference = $orig . '/' . $slug . '/';
				$guessed   = true;
			} elseif ( $home === $page_id ) {
				$reference = $orig . '/';
			}
		}
		$kind  = self::kind( $title, $slug );
		$saved = get_post_meta( $page_id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$topic = isset( $saved['topic'] ) && trim( (string) $saved['topic'] ) !== '' ? trim( (string) $saved['topic'] ) : self::topic_for( $title, $kind, $home );
		if ( isset( $saved['reference'] ) ) {
			$reference = trim( (string) $saved['reference'] );
			$guessed   = false;
		}

		return array(
			'id'                => $page_id,
			'title'             => $title,
			'slug'              => $slug,
			'kind'              => $kind,
			'staging'           => $post instanceof \WP_Post ? (string) get_permalink( $post ) : '',
			'topic'             => $topic,
			'reference'         => $reference,
			'reference_guessed' => $guessed,
			'saved'             => $saved !== array(),
		);
	}

	/**
	 * The prompt for a page.
	 *
	 * @param array{topic?:string, reference?:string}|null $override Fields as typed, not yet saved.
	 * @param bool                                         $facts    Add the company facts the Home carries.
	 */
	public static function prompt( int $page_id, ?array $override = null, bool $facts = false ): string {
		$f = self::fields( $page_id );
		if ( is_array( $override ) ) {
			if ( isset( $override['topic'] ) && trim( (string) $override['topic'] ) !== '' ) {
				$f['topic'] = trim( (string) $override['topic'] );
			}
			if ( isset( $override['reference'] ) ) {
				$f['reference'] = trim( (string) $override['reference'] );
			}
		}
		$text = self::template();
		if ( $f['reference'] === '' ) {
			// No page to take inspiration from: its two lines leave, the rest is the team's prompt.
			$text = (string) preg_replace( '/Use this production page as content inspiration where relevant:\R\{REFERENCE\}\R/', '', $text );
		}
		$text = strtr(
			$text,
			array(
				'{STAGING}'   => $f['staging'],
				'{TOPIC}'     => $f['topic'],
				'{REFERENCE}' => $f['reference'],
			)
		);
		if ( $facts ) {
			$home = self::home_of( $page_id );
			if ( $home > 0 ) {
				$text .= "\n\n" . self::facts_block( self::facts( $home ) );
			}
		}

		return $text;
	}

	/**
	 * Save what a person typed. An empty field goes back to what is worked out.
	 *
	 * @param array{topic?:string, reference?:string} $fields
	 */
	public static function save( int $page_id, array $fields ): void {
		$keep = array();
		$topic = isset( $fields['topic'] ) ? trim( sanitize_text_field( (string) $fields['topic'] ) ) : '';
		if ( $topic !== '' ) {
			$keep['topic'] = $topic;
		}
		if ( isset( $fields['reference'] ) ) {
			$ref = trim( (string) $fields['reference'] );
			if ( $ref === '' || preg_match( '#^https?://#i', $ref ) === 1 ) {
				$keep['reference'] = esc_url_raw( $ref );
			}
		}
		if ( $keep === array() ) {
			delete_post_meta( $page_id, self::META );

			return;
		}
		update_post_meta( $page_id, self::META, $keep );
	}

	/**
	 * The pages of a design (not its Home), with what their prompts would say.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function design( int $home ): array {
		$rows = array();
		foreach ( Color_Usage::posts( $home ) as $id ) {
			if ( get_post_type( $id ) !== 'page' ) {
				continue;
			}
			$rows[] = self::fields( (int) $id ) + array( 'home' => (int) $id === $home );
		}
		usort( $rows, static fn( $a, $b ) => ( $b['home'] <=> $a['home'] ) ?: strcasecmp( (string) $a['title'], (string) $b['title'] ) );

		return $rows;
	}

	/** The Home a page belongs to: its own id when it is one. */
	public static function home_of( int $page_id ): int {
		$scope = (int) get_post_meta( $page_id, \DXAI_UI\Structures\Page_Scope::META, true );

		return $scope > 0 ? $scope : $page_id;
	}

	/**
	 * @param array{name:string, phones:array<int, string>, emails:array<int, string>, origin:string} $facts
	 */
	private static function facts_block( array $facts ): string {
		$lines = array( 'What the Home page already says about the company (use it, do not invent other details):' );
		if ( $facts['name'] !== '' ) {
			$lines[] = '* Company / site: ' . $facts['name'];
		}
		if ( $facts['phones'] !== array() ) {
			$lines[] = '* Phone: ' . implode( ', ', $facts['phones'] );
		}
		if ( $facts['emails'] !== array() ) {
			$lines[] = '* E-mail: ' . implode( ', ', $facts['emails'] );
		}
		if ( $facts['origin'] !== '' ) {
			$lines[] = '* Current production site: ' . $facts['origin'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * The topic line for a page: its title, with what kind of page it is where the title does not say.
	 */
	private static function topic_for( string $title, string $kind, int $home ): string {
		$title = trim( (string) preg_replace( '/\s*[|–—-]\s*[^|–—-]*$/u', '', $title ) ) ?: $title;
		if ( in_array( $kind, array( 'service', 'location' ), true ) ) {
			return $title;
		}
		$label = array(
			'faq'          => 'the frequently asked questions',
			'contact'      => 'how to contact the company',
			'about'        => 'the company: who it is, its story and its team',
			'testimonials' => 'what customers say about the company',
			'privacy'      => 'the privacy policy and terms',
			'sitemap'      => 'a sitemap of the site',
			'blog'         => 'the blog',
			'areas'        => 'the areas the company serves',
			'services'     => 'all the services the company offers',
		)[ $kind ] ?? '';

		return $label !== '' ? $title . ' — ' . $label : $title;
	}
}

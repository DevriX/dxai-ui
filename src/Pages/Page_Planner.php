<?php
/**
 * An AI's choice of the sections of a page, checked before it is used.
 *
 * @package DXAI_UI\Pages
 */

declare(strict_types=1);

namespace DXAI_UI\Pages;

use DXAI_UI\Compiler\Json_Repair;
use DXAI_UI\Engines\LLM_Provider_Interface;

/**
 * The pages are put together without an AI (Team_Pages): the order of the sections is what the team's sites do, and each
 * section is one of the Home's. An AI may choose better — it can read what each of the Home's sections says and what the page
 * is about — and this lets it, with nothing left to trust:
 *
 *  - one request for a page, at most, and only when a person asked for it (Ai_Budget says what it will cost first);
 *  - it is shown the Home's sections and what can be made of the site's own pages, and answers with a list: for each place on
 *    the page, the role it plays and which of the Home's sections (or which made one) fills it;
 *  - the answer is a plan, never content: it can only name sections the Home has and sections the code makes, and every rule
 *    that holds for the usual plan holds for it (the hero first, a section once, never twice in a row, the list of pages
 *    before the questions, at most two made sections, a role only with a section that can play it);
 *  - an answer that breaks a rule, or is not an answer, is dropped with its reason, and the page is made the usual way.
 *
 * The words stay the Home's; they are written afterwards, by the copy writer, when a person asks.
 */
final class Page_Planner {

	/** The fewest and the most sections of a page. */
	public const MIN = 3;
	public const MAX = 10;

	/** The sections the code makes (Section_Blueprints), which a plan may ask for by name. */
	public const MADE = array( 'related', 'contact' );

	/** The most of the Home's sections listed to the AI. */
	private const LISTED = 24;

	/**
	 * What a plan is made and checked against.
	 *
	 * @param array<int, array<string, mixed>>   $library Section_Library::for_page() of the Home.
	 * @param array<string, array<int, int>>     $roles   Team_Pages::home_roles().
	 * @param array{type:string, title:string, slug:string} $item
	 * @param array<int, string>                 $usual   The roles of the usual plan of the page.
	 * @param array{related:bool, contact:bool}  $made    Which made sections can be made for this page.
	 * @return array<string, mixed>
	 */
	public static function context( array $library, array $roles, array $item, array $usual, array $made ): array {
		return array(
			'library' => array_values( $library ),
			'roles'   => $roles,
			'item'    => $item,
			'usual'   => array_values( $usual ),
			'made'    => $made,
		);
	}

	/**
	 * The request: what the page is, what the Home has, what can be made, the rules, and how to answer.
	 *
	 * @param array<string, mixed> $ctx context().
	 * @return array{system:string, user:string}
	 */
	public static function request( array $ctx ): array {
		$item = (array) $ctx['item'];
		$rows = array();
		foreach ( array_slice( (array) $ctx['library'], 0, self::LISTED ) as $i => $c ) {
			$head  = '';
			$words = '';
			$panel = $c['panels'][0] ?? null;
			if ( is_array( $panel ) ) {
				$head = Block_Tree::own_text( Block_Tree::at( $c['block'], (array) $panel['heading'] ) );
				if ( ! empty( $panel['texts'] ) ) {
					$words = Block_Tree::own_text( Block_Tree::at( $c['block'], (array) $panel['texts'][0] ) );
				}
			}
			$rows[] = sprintf(
				'%d. %s (%s)%s — "%s"%s',
				$i + 1,
				Section_Roles::of( $c['block'], $i === 0 ),
				(string) $c['kind'],
				$c['controls'] !== array() || $c['widgets'] !== array() ? ' [works by script: once only]' : '',
				mb_substr( $head, 0, 80 ),
				$words !== '' ? ' — "' . mb_substr( $words, 0, 100 ) . '"' : ''
			);
		}
		$made = array();
		if ( ! empty( $ctx['made']['related'] ) ) {
			$made[] = '- related: cards linking the other pages of this site (before the questions and the call to action)';
		}
		if ( ! empty( $ctx['made']['contact'] ) ) {
			$made[] = '- contact: the ways to reach the company (phone, e-mail, address) as cards';
		}
		$user = sprintf( "Plan the sections of one page of a local service company's website.\n\nPage: \"%s\", a %s page, about: %s.\n", $item['title'], $item['type'], $item['title'] )
			. 'The usual order of sections for this kind of page on the team\'s sites: ' . implode( ', ', (array) $ctx['usual'] ) . ".\n\n"
			. "The Home's sections (number, the role it plays, its kind, its heading, its first words):\n" . implode( "\n", $rows ) . "\n\n"
			. 'Sections the site can make besides the Home\'s' . ( $made === array() ? ": none for this page.\n" : ":\n" . implode( "\n", $made ) . "\n" )
			. "\nRules (the plan is checked against them; if it breaks one, the usual plan is used instead):\n"
			. '- ' . self::MIN . ' to ' . self::MAX . " sections; the first is the hero.\n"
			. "- Each of the Home's sections at most once, except a call to action, which may be twice, never twice in a row.\n"
			. "- Give a role only to a Home section that can play it (the roles are: " . implode( ', ', Section_Roles::ROLES ) . ").\n"
			. "- A made section is named by what it is: {\"role\":\"related\",\"made\":\"related\"}. At most two made sections, each once.\n"
			. "- Choose by what the page is about and what each section says; do not invent a section, and do not write any words.\n\n"
			. 'Answer with one JSON object and nothing else, in this shape (the numbers are the numbers of the list above, one entry for each place on the page, in order): {"sections":[{"role":"hero","home":<number>},{"role":"<role>","home":<number>},{"role":"related","made":"related"}],"why":"one short sentence"}';

		return array(
			'system' => 'You plan the sections of a web page from the sections a site already has. You never invent content. You answer with valid JSON only.',
			'user'   => $user,
		);
	}

	/**
	 * A plan, checked. The plan has to be one the page can be made from, by the same rules as the usual plan.
	 *
	 * @param array<string, mixed> $data The AI's answer, decoded.
	 * @param array<string, mixed> $ctx  context().
	 * @return array{roles:array<int, string>, picks:array<int, int|null>, why:string}|\WP_Error
	 */
	public static function validate( array $data, array $ctx ) {
		$sections = $data['sections'] ?? null;
		if ( ! is_array( $sections ) || $sections === array() ) {
			return self::refuse( 'no list of sections' );
		}
		$sections = array_values( $sections );
		if ( count( $sections ) < self::MIN || count( $sections ) > self::MAX ) {
			return self::refuse( sprintf( '%d sections (%d to %d are allowed)', count( $sections ), self::MIN, self::MAX ) );
		}
		$library = (array) $ctx['library'];
		$roles   = (array) $ctx['roles'];
		$item    = (array) $ctx['item'];
		$out     = array();
		$picks   = array();
		$used    = array();
		$made    = array();
		$prev    = -1;
		foreach ( $sections as $place => $s ) {
			if ( ! is_array( $s ) ) {
				return self::refuse( sprintf( 'section %d is not an object', $place + 1 ) );
			}
			$role = isset( $s['role'] ) && is_string( $s['role'] ) ? strtolower( trim( $s['role'] ) ) : '';
			if ( ! in_array( $role, array_merge( Section_Roles::ROLES, self::MADE ), true ) ) {
				return self::refuse( sprintf( 'section %d: "%s" is not a role', $place + 1, mb_substr( $role, 0, 30 ) ) );
			}
			$name = isset( $s['made'] ) && is_string( $s['made'] ) ? strtolower( trim( $s['made'] ) ) : '';
			if ( $name !== '' ) {
				// A section the code makes.
				if ( ! in_array( $name, self::MADE, true ) || $role !== $name ) {
					return self::refuse( sprintf( 'section %d: "%s" is not a section that can be made as "%s"', $place + 1, $name, $role ) );
				}
				if ( empty( $ctx['made'][ $name ] ) ) {
					return self::refuse( sprintf( 'section %d: "%s" cannot be made for this page', $place + 1, $name ) );
				}
				if ( isset( $made[ $name ] ) ) {
					return self::refuse( sprintf( 'section %d: "%s" twice', $place + 1, $name ) );
				}
				$made[ $name ] = true;
				$out[]         = $role;
				$picks[]       = null;
				$prev          = -1;
				continue;
			}
			if ( in_array( $role, self::MADE, true ) ) {
				return self::refuse( sprintf( 'section %d: "%s" is a made section and has to say so', $place + 1, $role ) );
			}
			$n = $s['home'] ?? null;
			if ( ! is_int( $n ) && ! ( is_string( $n ) && ctype_digit( $n ) ) ) {
				return self::refuse( sprintf( 'section %d has neither a Home section nor a made one', $place + 1 ) );
			}
			$index = (int) $n - 1;
			if ( ! isset( $library[ $index ] ) ) {
				return self::refuse( sprintf( 'section %d: the Home has no section %d', $place + 1, (int) $n ) );
			}
			if ( ! array_key_exists( $index, Team_Pages::pool( $library, $roles, $role ) ) ) {
				return self::refuse( sprintf( 'section %d: Home section %d cannot play "%s"', $place + 1, (int) $n, $role ) );
			}
			$times = ( $used[ $index ] ?? 0 ) + 1;
			if ( $times > ( $role === 'cta' ? 2 : 1 ) || ( $times > 1 && ( $library[ $index ]['controls'] !== array() || $library[ $index ]['widgets'] !== array() ) ) ) {
				return self::refuse( sprintf( 'section %d: Home section %d is used too often', $place + 1, (int) $n ) );
			}
			if ( $index === $prev ) {
				return self::refuse( sprintf( 'section %d: Home section %d twice in a row', $place + 1, (int) $n ) );
			}
			$used[ $index ] = $times;
			$prev           = $index;
			$out[]          = $role;
			$picks[]        = $index;
		}
		if ( $out[0] !== 'hero' ) {
			return self::refuse( 'the first section is not the hero' );
		}
		// The list of the site's pages comes before the questions and the call to action.
		$related = array_search( 'related', $out, true );
		foreach ( array( 'faq', 'cta' ) as $tail ) {
			$at = array_search( $tail, $out, true );
			if ( $related !== false && $at !== false && $at < $related ) {
				return self::refuse( 'the list of the site\'s pages is after the ' . $tail );
			}
		}
		// A page that is not what its name says without one section has it, when the Home has one.
		$need = array( 'faq' => 'faq', 'contact' => 'form' )[ $item['type'] ] ?? '';
		if ( $need !== '' && ! empty( $roles[ $need ] ) && ! in_array( $need, $out, true ) ) {
			return self::refuse( sprintf( 'a %s page without its %s section', $item['type'], $need ) );
		}
		if ( in_array( $item['type'], array( 'services', 'areas' ), true ) && ! empty( $ctx['made']['related'] ) && ! isset( $made['related'] ) ) {
			return self::refuse( 'the page that lists the pages has no list of them' );
		}

		return array(
			'roles' => $out,
			'picks' => $picks,
			'why'   => isset( $data['why'] ) && is_string( $data['why'] ) ? mb_substr( sanitize_text_field( $data['why'] ), 0, 200 ) : '',
		);
	}

	/**
	 * Ask the engine for a plan of one page and check it. One request, if the budget has room for it.
	 *
	 * @param array<string, mixed> $ctx context().
	 * @return array{plan:array{roles:array<int, string>, picks:array<int, int|null>, why:string}|null, reason:string, requested:bool}
	 */
	public static function run( LLM_Provider_Interface $engine, array $ctx, Ai_Budget $budget ): array {
		$req = self::request( $ctx );
		$in  = Ai_Budget::tokens( $req['system'] . $req['user'] );
		if ( ! $budget->allows( $in, Ai_Budget::PLAN_OUT ) ) {
			return array(
				'plan'      => null,
				'reason'    => 'the ceiling of this run would be passed',
				'requested' => false,
			);
		}
		$text = $engine->complete( $req['system'], $req['user'], array( 'max_tokens' => 1500 ) ); // phpcs:ignore -- engines have complete(); Copy_Writer::engine() checks.
		$budget->spend( $in, Ai_Budget::PLAN_OUT );
		if ( is_wp_error( $text ) ) {
			return array(
				'plan'      => null,
				'reason'    => 'the engine did not answer: ' . $text->get_error_message(),
				'requested' => true,
			);
		}
		$data = Json_Repair::decode( (string) $text );
		if ( ! is_array( $data ) ) {
			return array(
				'plan'      => null,
				'reason'    => 'the answer is not JSON',
				'requested' => true,
			);
		}
		$plan = self::validate( $data, $ctx );
		if ( is_wp_error( $plan ) ) {
			return array(
				'plan'      => null,
				'reason'    => $plan->get_error_message(),
				'requested' => true,
			);
		}

		return array(
			'plan'      => $plan,
			'reason'    => '',
			'requested' => true,
		);
	}

	private static function refuse( string $why ): \WP_Error {
		return new \WP_Error( 'dxai_ui_plan', $why );
	}
}

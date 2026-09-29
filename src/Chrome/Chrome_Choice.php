<?php
/**
 * Whether an import installs the design's header and footer as the site's.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Chrome;

/**
 * The choice an import makes about the site's header and footer, asked of
 * the person importing:
 *
 * - `install`: the design's header goes into Appearance > Menus (its menus
 *   take the theme locations, Header_Menus::install()) and its footer into
 *   Appearance > Widgets (Footer_Widgets::install()); the design's pages get
 *   the site header and footer blocks. They become the SITE's header and
 *   footer — on a classic theme, which draws its own header and footer from
 *   the very same locations and areas, on every page of the site.
 * - `keep`: the site's menus, widgets, locations and logo are not touched at
 *   all; the design's pages keep its header and footer as template parts.
 * - `` (automatic): keep when the theme draws its own header and footer
 *   (Theme_Compat::may_install_chrome() false), when the import is not a
 *   whole-site import (scope `page`), or when another design's header or
 *   footer is the site's now; install otherwise — no design's is the site's
 *   (none was installed, or the design it came from is gone), or this
 *   design's already is (a re-import updates it in place).
 *
 * Structure_Repository::save() resolves the choice before it writes
 * anything, so it answers for the site as it was before the import; the
 * import screen asks the same question beforehand (info()).
 */
final class Chrome_Choice {

	public const INSTALL = 'install';
	public const KEEP    = 'keep';
	public const AUTO    = '';

	/** Page statuses under which a design's page still shows its header and footer. */
	private const LIVE = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * A requested choice as one of the three; anything else is automatic.
	 *
	 * @param mixed $requested
	 */
	public static function normalize( $requested ): string {
		$value = is_string( $requested ) ? strtolower( trim( $requested ) ) : '';

		return in_array( $value, array( self::INSTALL, self::KEEP ), true ) ? $value : self::AUTO;
	}

	/**
	 * Whether the active theme draws its own header and footer from the menu
	 * locations and widget areas an install writes (a classic theme), so an
	 * install changes them on every page of the site.
	 */
	public static function theme_draws_chrome(): bool {
		return ! \DXAI_UI\Theme\Theme_Compat::may_install_chrome();
	}

	/**
	 * The designs whose header and footer are the site's now: the header
	 * installed last (its menus hold the theme locations) and the footer the
	 * widget areas hold. Each is [] when none was installed; otherwise title,
	 * archive, page_id, owner (the design's page key, archive#slug, '' for a
	 * footer installed before the key was recorded), live (its page still
	 * exists outside the trash) and the page's view and edit links.
	 *
	 * @return array{header:array<string, mixed>, footer:array<string, mixed>}
	 */
	public static function owners(): array {
		$site   = Header_Template::stored();
		$source = null !== $site && is_array( $site['source'] ?? null ) ? $site['source'] : array();
		$footer = Footer_Template::get();

		return array(
			'header' => $source === array() ? array() : self::describe(
				(string) ( $source['title'] ?? '' ),
				(string) ( $source['archive'] ?? '' ),
				(int) ( $source['page_id'] ?? 0 ),
				(int) ( $source['scope_id'] ?? 0 ),
				(string) ( $source['owner'] ?? '' )
			),
			'footer' => $footer === array() ? array() : self::describe(
				(string) ( $footer['title'] ?? '' ),
				(string) ( $footer['source'] ?? '' ),
				(int) ( $footer['page_id'] ?? 0 ),
				(int) ( $footer['scope'] ?? 0 ),
				(string) ( $footer['owner'] ?? '' )
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function describe( string $title, string $archive, int $page_id, int $scope, string $owner ): array {
		$status = $page_id > 0 ? get_post_status( $page_id ) : false;
		$live   = is_string( $status ) && in_array( $status, self::LIVE, true );

		return array(
			'title'   => $title !== '' ? $title : (string) preg_replace( '/\.zip$/i', '', $archive ),
			'archive' => $archive,
			'page_id' => $page_id,
			'scope'   => $scope > 0 ? $scope : $page_id,
			'owner'   => $owner,
			'live'    => $live,
			'view'    => $live ? (string) get_permalink( $page_id ) : '',
			'edit'    => $live ? (string) get_edit_post_link( $page_id, 'raw' ) : '',
		);
	}

	/**
	 * Whether an owners() entry is the design being imported.
	 *
	 * @param array<string, mixed> $who    An owners() entry.
	 * @param array<string, mixed> $design owner, archive, page_id (see resolve()).
	 */
	public static function is_design( array $who, array $design ): bool {
		if ( $who === array() ) {
			return false;
		}
		$page = (int) ( $design['page_id'] ?? 0 );
		if ( $page > 0 && ( (int) ( $who['page_id'] ?? 0 ) === $page || (int) ( $who['scope'] ?? 0 ) === $page ) ) {
			return true;
		}
		$owner = (string) ( $design['owner'] ?? '' );
		$held  = (string) ( $who['owner'] ?? '' );
		if ( $owner !== '' && $held !== '' ) {
			return $owner === $held;
		}
		// No page key on one side (a footer installed before it was recorded,
		// or the import screen, which knows only the archive): the archive.
		$archive = (string) ( $design['archive'] ?? '' );
		if ( $archive === '' ) {
			return false;
		}
		$theirs = (string) ( $who['archive'] ?? '' );
		if ( $theirs === '' && $held !== '' ) {
			$theirs = (string) strtok( $held, '#' );
		}

		return $theirs === $archive;
	}

	/**
	 * The areas whose site header or footer another design holds now — one
	 * whose page is still there, so its pages still show it.
	 *
	 * @param array<string, mixed>                                $design See resolve().
	 * @param array{header:array<string, mixed>, footer:array<string, mixed>}|null $owners owners(); null reads them.
	 * @return array<string, array<string, mixed>> area => owners() entry.
	 */
	public static function held_by_others( array $design, ?array $owners = null ): array {
		$owners = $owners ?? self::owners();
		$out    = array();
		foreach ( array( 'header', 'footer' ) as $area ) {
			$who = (array) ( $owners[ $area ] ?? array() );
			if ( $who !== array() && ! empty( $who['live'] ) && ! self::is_design( $who, $design ) ) {
				$out[ $area ] = $who;
			}
		}

		return $out;
	}

	/**
	 * Resolve a requested choice for one import.
	 *
	 * @param string               $requested install, keep, or '' (automatic).
	 * @param array<string, mixed> $design    scope ('site' or 'page'), owner (the design's page
	 *                                        key, archive#slug), archive (the ZIP's name), page_id
	 *                                        (its page when it exists already, else 0).
	 * @return array{mode:string, requested:string, reason:string, message:string, theme_draws:bool, owners:array<string, mixed>, others:array<string, mixed>}
	 *         mode: install or keep; reason: asked (the person chose), theme, one_page,
	 *         owned (automatic keep), free, own (automatic install); others: the areas
	 *         another design holds now (held_by_others()).
	 */
	public static function resolve( string $requested, array $design ): array {
		$requested = self::normalize( $requested );
		$owners    = self::owners();
		$others    = self::held_by_others( $design, $owners );
		$theme     = self::theme_draws_chrome();
		$scope     = (string) ( $design['scope'] ?? 'site' );

		if ( $requested !== self::AUTO ) {
			$mode   = $requested;
			$reason = 'asked';
		} elseif ( $theme ) {
			$mode   = self::KEEP;
			$reason = 'theme';
		} elseif ( 'site' !== $scope ) {
			$mode   = self::KEEP;
			$reason = 'one_page';
		} elseif ( $others !== array() ) {
			$mode   = self::KEEP;
			$reason = 'owned';
		} else {
			$mode   = self::INSTALL;
			$own    = false;
			foreach ( $owners as $who ) {
				$own = $own || ( $who !== array() && ! empty( $who['live'] ) && self::is_design( (array) $who, $design ) );
			}
			$reason = $own ? 'own' : 'free';
		}

		/**
		 * The header and footer choice an import makes, after it is resolved.
		 * Return install or keep; anything else keeps the resolved mode.
		 *
		 * @param string               $mode      install or keep.
		 * @param string               $requested install, keep or '' (automatic).
		 * @param array<string, mixed> $design    The import (see resolve()).
		 * @param string               $reason    Why.
		 */
		$filtered = (string) apply_filters( 'dxai_ui_chrome_mode', $mode, $requested, $design, $reason );
		if ( $filtered !== $mode && in_array( $filtered, array( self::INSTALL, self::KEEP ), true ) ) {
			$mode   = $filtered;
			$reason = 'filter';
		}

		return array(
			'mode'        => $mode,
			'requested'   => $requested,
			'reason'      => $reason,
			'message'     => self::message( $mode, $reason, $others, $theme ),
			'theme_draws' => $theme,
			'owners'      => $owners,
			'others'      => $others,
		);
	}

	/**
	 * What the import screen needs before an import: the automatic choice
	 * for a whole-site import of this archive (or of a design that is not
	 * the site's header and footer, when no archive is named), why, whether
	 * the theme draws its own header and footer, and whose they are now.
	 *
	 * @return array{chrome_default:string, chrome_default_reason:string, chrome_default_message:string, theme_chrome:bool, theme_name:string, chrome_owner:array<string, mixed>}
	 */
	public static function info( string $archive = '', string $scope = 'site' ): array {
		$choice = self::resolve(
			self::AUTO,
			array(
				'scope'   => in_array( $scope, array( 'site', 'page' ), true ) ? $scope : 'site',
				'archive' => $archive,
			)
		);

		return array(
			'chrome_default'         => $choice['mode'],
			'chrome_default_reason'  => $choice['reason'],
			'chrome_default_message' => $choice['message'],
			'theme_chrome'           => $choice['theme_draws'],
			'theme_name'             => (string) wp_get_theme()->get( 'Name' ),
			'chrome_owner'           => $choice['owners'],
		);
	}

	/**
	 * One or two sentences saying what the choice does and why.
	 *
	 * @param array<string, array<string, mixed>> $others held_by_others().
	 */
	private static function message( string $mode, string $reason, array $others, bool $theme ): string {
		$names = array();
		foreach ( $others as $who ) {
			$names[] = (string) ( $who['title'] ?? '' );
		}
		$names = array_values( array_unique( array_filter( $names ) ) );
		$other = implode( ', ', $names );

		if ( self::KEEP === $mode ) {
			$kept = __( 'The site\'s header and footer stay as they are: Appearance › Menus and Widgets are not changed, and this design\'s header and footer are template parts on its pages.', 'dxai-ui' );
			if ( 'theme' === $reason ) {
				return __( 'This theme draws the site\'s header and footer itself, from the menus and widget areas an install would write, so installing the design\'s would change every page of the site.', 'dxai-ui' ) . ' ' . $kept;
			}
			if ( 'one_page' === $reason ) {
				return __( 'A one-page import does not change the site\'s header and footer.', 'dxai-ui' ) . ' ' . $kept;
			}
			if ( 'owned' === $reason ) {
				return sprintf(
					/* translators: %s: title(s) of the design(s) whose header or footer the site shows. */
					__( 'The site\'s header and footer are those of “%s”, imported earlier.', 'dxai-ui' ),
					$other
				) . ' ' . $kept . ' ' . __( 'Choose to install to make this design\'s the site\'s.', 'dxai-ui' );
			}

			return $kept;
		}

		$done = __( 'The design\'s header is built in Appearance › Menus and its footer in Appearance › Widgets, where you edit them.', 'dxai-ui' );
		if ( 'own' === $reason ) {
			$done = __( 'The site\'s header and footer are already this design\'s; Appearance › Menus and Widgets are updated in place.', 'dxai-ui' );
		}
		$more = array();
		if ( $other !== '' ) {
			$more[] = sprintf(
				/* translators: %s: title(s) of the design(s) whose header or footer the site showed. */
				__( 'They replace those of “%s”, whose pages keep their own header, and their footer as a template part.', 'dxai-ui' ),
				$other
			);
		}
		if ( $theme ) {
			$more[] = __( 'This theme draws the site\'s header and footer itself from these menus and widget areas, so every page of the site shows them.', 'dxai-ui' );
		}

		return trim( $done . ' ' . implode( ' ', $more ) );
	}
}

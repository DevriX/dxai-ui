<?php
/**
 * The site-from-menu crawl as a job that advances a little per request.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

/**
 * Crawling a design's menu used to run inside the import request itself:
 * for every page linked from the menu, a fetch (up to 45 s), its images
 * (up to 90 s each), an AI restyle (up to 120 s, plus a retry), then the
 * writes — 13 minutes for H2O's 15 pages, in one PHP process. A real host
 * does not let a request live that long: nginx's fastcgi_read_timeout,
 * Cloudflare's 100 s, PHP-FPM's request_terminate_timeout or LiteSpeed's
 * limit end it part-way, leaving pages half-made, the design's tokens
 * undefined (the tokenising pass runs last) and no conversion record.
 *
 * So the import request now only PLANS the crawl and stores the plan here.
 * The admin wizard then calls the crawl-step route in a loop; each call
 * advances the job by as many small steps as fit in its time budget — at
 * least one: fetch one page, sideload some of its images, restyle it, save
 * it — and the last call runs the finishing pass (menus, link rewrite,
 * tokens, audits). If the tab is closed, a WP-Cron event carries on as the
 * user who started the import, so a site with a real cron still finishes;
 * with DISABLE_WP_CRON the wizard is the driver.
 *
 * One step can still outlive a proxy's timeout — an AI call alone can take
 * two minutes. The step keeps running server-side (ignore_user_abort) and
 * stores its result; the wizard's next call finds the job locked, waits,
 * and continues when the lock is released. A lock older than LOCK_TTL
 * belongs to a request that died, and is taken over.
 *
 * A host can also end the request itself (PHP-FPM's request_terminate_timeout,
 * WP Engine's 60-second process limit, an out-of-memory kill): nothing after
 * the kill runs, so the step stores nothing and the next request would run
 * the very same step again — the same AI call, paid for again — for as long
 * as the wizard kept asking. So each step marks its attempt in the job before
 * it starts (run_step()): a step whose mark is still there when the job is
 * next advanced never came back. Its retry is lighter (no AI, no more image
 * downloads), and a step that has died twice, or thrown twice, is given up:
 * the page is reported as not built and the crawl moves on.
 *
 * Storage is one non-autoloaded option per job: it works on every host,
 * needs no table, and a job is small next to the design itself (the plan,
 * the page being worked on, and what the finishing pass needs).
 */
final class Crawl_Job {

	private const PREFIX = 'dxai_ui_crawl_job_';

	private const LOCK_PREFIX = 'dxai_ui_crawl_lock_';

	public const CRON_HOOK = 'dxai_ui_crawl_tick';

	/**
	 * Longer than any single step can take: the longest is an AI restyle, two
	 * calls of Remote_Client::DEFAULT_TIMEOUT (120 s), and a request starts one
	 * only as its first step (advance()). A shorter wait matters because a
	 * lock whose request was killed holds the job this long.
	 */
	private const LOCK_TTL = 600;

	/** Attempts one step gets before its page is given up (run_step()). */
	private const MAX_ATTEMPTS = 2;

	/** A job nobody advanced for this long is abandoned and removed. */
	private const MAX_AGE = 2 * DAY_IN_SECONDS;

	/** Seconds of work one wizard request may spend (it always does one step). */
	public const REQUEST_BUDGET = 20.0;

	public function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'tick' ), 10, 1 );
	}

	/**
	 * Store a new job and schedule the cron fallback — or, when this page
	 * already has a crawl that has not finished, answer that one.
	 *
	 * A save whose answer the browser never got (a proxy timed the request
	 * out while WordPress kept working) had already planned its crawl; saving
	 * again planned a second one for the same page, and WP-Cron then ran the
	 * orphaned first job beside the one the wizard drives: every menu page
	 * fetched, rebuilt — with AI, at the site's expense — and saved twice, in
	 * parallel. A second tab, a CLI run or a double submit does the same. So
	 * one page has one unfinished crawl:
	 *  - the same plan (same pages, same chrome, same AI switch, same design:
	 *    palette and restyle guide): the existing job is returned, with
	 *    whatever progress it has made, and with this save's tail — the
	 *    conversion, parts and patterns the finishing pass works on are this
	 *    save's;
	 *  - a different plan (the design changed): the old job is cancelled — its
	 *    option, lock and cron event removed; a step it is running now stops
	 *    at its next store (advance()) — and the new one takes its place.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function create( array $state ): string {
		self::cleanup();
		$page = (int) ( $state['tail']['page_id'] ?? 0 );
		if ( $page > 0 ) {
			$plan = self::plan_sig( $state );
			$same = '';
			foreach ( self::unfinished_for( $page ) as $old_id => $old ) {
				if ( $same === '' && self::plan_sig( $old ) === $plan ) {
					$same = $old_id;
					continue;
				}
				self::delete( $old_id );
			}
			if ( $same !== '' ) {
				$old = self::fresh( $same );
				if ( is_array( $old ) && is_array( $state['tail'] ?? null ) ) {
					// A step running now keeps this tail too (advance()).
					$old['tail'] = $state['tail'];
					self::store( $same, $old );
				}
				self::schedule( $same, 120 );

				return $same;
			}
		}
		$id                 = str_replace( '-', '', wp_generate_uuid4() );
		$state['id']        = $id;
		$state['owner']     = get_current_user_id();
		$state['created']   = time();
		$state['touched']   = time();
		$state['done']      = false;
		add_option( self::PREFIX . $id, $state, '', false );
		self::schedule( $id, 120 );

		return $id;
	}

	/**
	 * The unfinished jobs crawling for one page, oldest first.
	 *
	 * @return array<string, array<string, mixed>> Job id => state.
	 */
	public static function unfinished_for( int $page_id ): array {
		global $wpdb;

		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);
		$out = array();
		foreach ( $names as $name ) {
			$id    = substr( (string) $name, strlen( self::PREFIX ) );
			$state = self::load( $id );
			if ( is_array( $state ) && empty( $state['done'] ) && (int) ( $state['tail']['page_id'] ?? 0 ) === $page_id ) {
				$out[ self::clean_id( $id ) ] = $state;
			}
		}

		return $out;
	}

	/**
	 * What makes two crawls the same crawl: the pages it will visit, the
	 * header and footer it puts on them, whether it may use AI, the archive
	 * it came from — and the design it restyles them in: the palette and
	 * restyle guide every page is written with, the Home's wrapper and
	 * whether it is a static design. A re-import of a changed design (new
	 * colours, same menu) is a new crawl; reusing the old one would restyle
	 * the remaining pages with the old palette, whose tokens the new one may
	 * not define.
	 *
	 * @param array<string, mixed> $state
	 */
	private static function plan_sig( array $state ): string {
		$crawl = is_array( $state['crawl'] ?? null ) ? $state['crawl'] : array();
		$setup = is_array( $crawl['setup'] ?? null ) ? $crawl['setup'] : array();
		$pages = array();
		foreach ( (array) ( $crawl['candidates'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$pages[] = array( (string) ( $row['path'] ?? '' ), (string) ( $row['url'] ?? '' ) );
			}
		}

		return md5(
			(string) wp_json_encode(
				array(
					$pages,
					(string) ( $setup['header'] ?? '' ),
					(string) ( $setup['footer'] ?? '' ),
					! empty( $setup['ai_ok'] ),
					(string) ( $setup['archive'] ?? '' ),
					(string) ( $crawl['summary']['origin'] ?? '' ),
					md5( (string) wp_json_encode( $setup['palette'] ?? array() ) ),
					md5( (string) wp_json_encode( $setup['home_guide'] ?? array() ) ),
					(string) ( $setup['home_wrap'] ?? '' ),
					! empty( $setup['static'] ),
				)
			)
		);
	}

	/**
	 * A job's state as stored now, read past every cache: another request
	 * (create(), for a save that reused this job) may have written it since
	 * this one loaded it.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function fresh( string $id ): ?array {
		global $wpdb;

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . self::clean_id( $id ) ) );
		if ( null === $raw ) {
			return null;
		}
		$state = maybe_unserialize( $raw );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Merge values into a job's tail, as stored now — its progress, which a
	 * step in another request may have just stored, is left as it is.
	 *
	 * @param array<string, mixed> $patch Tail keys => values.
	 */
	public static function update_tail( string $id, array $patch ): void {
		$state = self::fresh( $id );
		if ( ! is_array( $state ) ) {
			return;
		}
		$state['tail'] = array_merge( is_array( $state['tail'] ?? null ) ? $state['tail'] : array(), $patch );
		self::store( $id, $state );
	}

	/**
	 * $state with the tail as stored now. The tail is the save's (create(),
	 * Structure_Repository::save()) and a step never changes it, so a step
	 * that stores its progress must not put back the tail it loaded: a save
	 * that reused this job while the step ran has written a newer one.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function with_stored_tail( string $id, array $state ): array {
		$stored = self::fresh( $id );
		if ( is_array( $stored ) && is_array( $stored['tail'] ?? null ) ) {
			$state['tail'] = $stored['tail'];
		}

		return $state;
	}

	/**
	 * Whether the job's option is still in the database — read past every
	 * cache, because the request that cancels it (create()) is another one.
	 */
	private static function exists( string $id ): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . self::clean_id( $id ) ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function load( string $id ): ?array {
		$id = self::clean_id( $id );
		if ( $id === '' ) {
			return null;
		}
		$state = get_option( self::PREFIX . $id, null );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	public static function store( string $id, array $state ): void {
		$state['touched'] = time();
		update_option( self::PREFIX . self::clean_id( $id ), $state, false );
	}

	public static function delete( string $id ): void {
		$id = self::clean_id( $id );
		delete_option( self::PREFIX . $id );
		delete_option( self::LOCK_PREFIX . $id );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
	}

	/**
	 * Take the job's lock. add_option() is an INSERT on a unique key, so
	 * two requests cannot both get it; a lock older than LOCK_TTL is a dead
	 * request's and is replaced.
	 */
	public static function lock( string $id ): bool {
		$key = self::LOCK_PREFIX . self::clean_id( $id );
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$held = (int) get_option( $key, 0 );
		if ( $held > 0 && time() - $held < self::LOCK_TTL ) {
			return false;
		}
		delete_option( $key );

		return add_option( $key, time(), '', false );
	}

	public static function unlock( string $id ): void {
		delete_option( self::LOCK_PREFIX . self::clean_id( $id ) );
	}

	/**
	 * Advance a job: lock it, run steps until the budget is spent (at least
	 * one), run the finishing pass once nothing is left, store, unlock.
	 *
	 * @return array<string, mixed> Progress for the caller.
	 */
	public static function advance( string $id, float $budget ): array {
		$state = self::load( $id );
		if ( $state === null ) {
			return array(
				'job'   => $id,
				'error' => 'missing',
			);
		}
		if ( ! empty( $state['done'] ) ) {
			return self::progress( $state );
		}
		if ( ! self::lock( $id ) ) {
			return self::progress( $state ) + array( 'busy' => true );
		}
		// A proxy may give up on this request; the work must still finish
		// and be stored, or the next call would repeat it.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			// Read again now the lock is ours: the request that held it until
			// just now may have stored its step, and cleared its attempt mark,
			// since load() above.
			$state = self::fresh( $id );
			if ( $state === null ) {
				return array(
					'job'   => $id,
					'error' => 'missing',
				);
			}
			if ( ! empty( $state['done'] ) ) {
				return self::progress( $state );
			}
			$start = microtime( true );
			$menu  = new Site_From_Menu();
			do {
				$more = self::run_step( $id, $state, $menu );
				// Cancelled meanwhile (a new plan for the same page, create()):
				// storing now would bring the job back.
				if ( ! self::exists( $id ) ) {
					return array(
						'job'   => $id,
						'error' => 'missing',
					);
				}
				$state = self::with_stored_tail( $id, $state );
				self::store( $id, $state );
				// An AI restyle can take minutes, so it only ever starts a
				// request: after other steps it would start with part of the
				// host's time limit already spent.
			} while ( $more && microtime( true ) - $start < $budget && ! self::calls_ai( $state ) );

			// The finishing pass is the longest step of all; with the budget
			// spent it waits for the next request, which starts with it.
			if ( ! $more && microtime( true ) - $start < $budget ) {
				self::finish_job( $id, $state, $menu );
			} else {
				self::schedule( $id, 120 );
			}
		} finally {
			self::unlock( $id );
		}

		return self::progress( $state );
	}

	/**
	 * One step of the crawl, with its attempt marked in the stored job first.
	 *
	 * The mark (index and phase) is cleared when the step returns. Found
	 * still there, it belongs to a request the host ended mid-step, or to a
	 * step that threw (its message is kept with it):
	 *  - the retry is lighter: a restyle runs without AI, and an image phase
	 *    stops downloading and goes on with the pictures it has;
	 *  - an AI restyle that died is taken as this host's limit — the rest of
	 *    the job restyles without AI too, rather than wait out a dead lock
	 *    and pay for a call that cannot finish, page after page;
	 *  - after MAX_ATTEMPTS the page is reported as not built and the crawl
	 *    moves on to the next.
	 *
	 * @param array<string, mixed> $state The job, advanced in place.
	 * @return bool Whether there is more to do.
	 */
	private static function run_step( string $id, array &$state, Site_From_Menu $menu ): bool {
		$crawl = is_array( $state['crawl'] ?? null ) ? $state['crawl'] : array();
		if ( (int) ( $crawl['index'] ?? 0 ) >= count( (array) ( $crawl['candidates'] ?? array() ) ) ) {
			// Every page is through: no step to mark, and the finishing pass
			// that comes next keeps its own mark (finish_job()).
			return $menu->step( $state['crawl'] );
		}
		$phase = (string) ( $crawl['phase'] ?? 'fetch' );
		$key   = (int) ( $crawl['index'] ?? 0 ) . ':' . $phase;
		$mark  = is_array( $state['attempt'] ?? null ) && ( $state['attempt']['at'] ?? '' ) === $key ? $state['attempt'] : array();
		$tries = (int) ( $mark['n'] ?? 0 );
		$why   = (string) ( $mark['why'] ?? '' );
		unset( $state['attempt'] );
		if ( $tries >= self::MAX_ATTEMPTS ) {
			return self::give_up( $state['crawl'], $why );
		}
		if ( $tries > 0 && $why === '' && self::calls_ai( $state ) ) {
			$state['ai_off'] = true;
		}
		// Never store into a job create() has cancelled meanwhile.
		if ( ! self::exists( $id ) ) {
			return false;
		}
		$state            = self::with_stored_tail( $id, $state );
		$state['attempt'] = array(
			'at' => $key,
			'n'  => $tries + 1,
		);
		self::store( $id, $state );

		$before = $state['crawl'];
		$no_ai  = ! empty( $state['crawl']['setup']['ai_ok'] ) && ( ! empty( $state['ai_off'] ) || ( $tries > 0 && $phase === 'restyle' ) );
		if ( $no_ai ) {
			$state['crawl']['setup']['ai_ok'] = false;
		}
		if ( $tries > 0 && $phase === 'images' ) {
			$state['crawl']['current']['pending'] = array();
		}
		try {
			$more = $menu->step( $state['crawl'] );
		} catch ( \Throwable $e ) {
			// Nothing the step half did is kept; the mark stays, with the reason.
			$state['crawl']          = $before;
			$state['attempt']['why'] = self::reason( $e );

			return true;
		}
		if ( $no_ai ) {
			// Only this attempt went without: the plan (plan_sig()) still says AI.
			$state['crawl']['setup']['ai_ok'] = true;
		}
		unset( $state['attempt'] );

		return $more;
	}

	/** What a step that threw reports: its message, as plain text and short. */
	private static function reason( \Throwable $e ): string {
		$why = sanitize_text_field( $e->getMessage() );

		return $why !== '' ? mb_substr( $why, 0, 300 ) : get_class( $e );
	}

	/**
	 * Whether the next step is a restyle that will call the AI.
	 *
	 * @param array<string, mixed> $state
	 */
	private static function calls_ai( array $state ): bool {
		$crawl = is_array( $state['crawl'] ?? null ) ? $state['crawl'] : array();

		return ( $crawl['phase'] ?? '' ) === 'restyle'
			&& empty( $crawl['current']['model'] )
			&& ! empty( $crawl['setup']['ai_ok'] )
			&& empty( $state['ai_off'] );
	}

	/**
	 * Report the current page as not built and move to the next — what
	 * Site_From_Menu::next_page() does after a page fails.
	 *
	 * @param array<string, mixed> $crawl The run, advanced in place.
	 * @return bool Whether there is another page.
	 */
	private static function give_up( array &$crawl, string $why ): bool {
		$queue     = is_array( $crawl['candidates'] ?? null ) ? array_values( $crawl['candidates'] ) : array();
		$index     = (int) ( $crawl['index'] ?? 0 );
		$candidate = is_array( $queue[ $index ] ?? null ) ? $queue[ $index ] : array();

		$crawl['summary']['crawl_errors'][] = array(
			'label'   => sanitize_text_field( (string) ( $candidate['label'] ?? '' ) ),
			'url'     => (string) ( $candidate['url'] ?? '' ),
			'message' => sprintf(
				/* translators: %s: why the step stopped */
				__( 'Sorry — this page was not built: building it stopped twice before it could finish (%s). Build it again from the Library.', 'dxai-ui' ),
				$why !== '' ? $why : __( 'the server ended the request — most likely the host’s time limit', 'dxai-ui' )
			),
		);
		$crawl['index']   = $index + 1;
		$crawl['phase']   = 'fetch';
		$crawl['current'] = array();

		return $crawl['index'] < count( $queue );
	}

	/**
	 * The finishing pass, marked like a step (run_step()): a pass that dies
	 * or throws is run once more, and after MAX_ATTEMPTS the job ends with
	 * the pages it built and an error that says the pass did not run, rather
	 * than being asked to run it for as long as the wizard is open.
	 *
	 * @param array<string, mixed> $state The job, advanced in place.
	 */
	private static function finish_job( string $id, array &$state, Site_From_Menu $menu ): void {
		$mark  = is_array( $state['attempt'] ?? null ) && ( $state['attempt']['at'] ?? '' ) === 'finish' ? $state['attempt'] : array();
		$tries = (int) ( $mark['n'] ?? 0 );
		unset( $state['attempt'] );
		if ( $tries >= self::MAX_ATTEMPTS ) {
			$state['result'] = self::unfinished_result( $state, (string) ( $mark['why'] ?? '' ) );
		} else {
			if ( ! self::exists( $id ) ) {
				return;
			}
			$state            = self::with_stored_tail( $id, $state );
			$state['attempt'] = array(
				'at' => 'finish',
				'n'  => $tries + 1,
			);
			self::store( $id, $state );
			try {
				$state['crawl']  = $menu->finish( $state['crawl'] );
				// A job the Library started for chosen pages ends with their links only (Pages\Site_Pages).
				$state['result'] = ( $state['tail']['mode'] ?? '' ) === \DXAI_UI\Pages\Site_Pages::MODE
					? \DXAI_UI\Pages\Site_Pages::finish( $state )
					: ( new Structure_Repository() )->finish_crawl( $state );
			} catch ( \Throwable $e ) {
				$state['attempt']['why'] = self::reason( $e );
				if ( self::exists( $id ) ) {
					self::store( $id, $state );
					self::schedule( $id, 120 );
				}

				return;
			}
			unset( $state['attempt'] );
		}
		$state['done'] = true;
		self::store( $id, $state );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
	}

	/**
	 * A job's outcome when its finishing pass never completed: the pages, as
	 * the crawl built them, and an error that names what did not happen.
	 *
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private static function unfinished_result( array $state, string $why ): array {
		$summary  = is_array( $state['crawl']['summary'] ?? null ) ? $state['crawl']['summary'] : array();
		$errors   = (array) ( $summary['crawl_errors'] ?? array() );
		$errors[] = array(
			'label'   => '',
			'url'     => (string) ( $summary['origin'] ?? '' ),
			'message' => sprintf(
				/* translators: %s: why the finishing pass stopped */
				__( 'Sorry — the pages were built, but the finishing pass (menus, links, colour tokens) stopped twice before it could finish (%s). Import the design again to run it.', 'dxai-ui' ),
				$why !== '' ? $why : __( 'the server ended the request — most likely the host’s time limit', 'dxai-ui' )
			),
		);

		return array(
			'pages_created'  => (array) ( $summary['pages_created'] ?? array() ),
			'pages_skipped'  => (array) ( $summary['pages_skipped'] ?? array() ),
			'crawl_errors'   => $errors,
			'pages_restyled' => (int) ( $summary['pages_restyled'] ?? 0 ),
			'restyle_engine' => (string) ( $summary['restyle_engine'] ?? '' ),
			'finish_error'   => $why !== '' ? $why : 'ended',
		);
	}

	/**
	 * The cron fallback: carry on as the user who started the import, so
	 * the pages are written with that user's capabilities (a cron request
	 * has no user, and KSES would filter every page it wrote). Skipped while
	 * the wizard is driving — it touched the job in the last two minutes.
	 */
	public static function tick( string $id ): void {
		$state = self::load( $id );
		if ( $state === null || ! empty( $state['done'] ) ) {
			return;
		}
		if ( time() - (int) ( $state['touched'] ?? 0 ) < 90 ) {
			self::schedule( $id, 120 );

			return;
		}
		$owner = (int) ( $state['owner'] ?? 0 );
		if ( $owner < 1 || ! user_can( $owner, 'unfiltered_html' ) ) {
			return;
		}
		wp_set_current_user( $owner );
		self::advance( $id, 45.0 );
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	public static function progress( array $state ): array {
		$crawl   = is_array( $state['crawl'] ?? null ) ? $state['crawl'] : array();
		$queue   = is_array( $crawl['candidates'] ?? null ) ? array_values( $crawl['candidates'] ) : array();
		$index   = (int) ( $crawl['index'] ?? 0 );
		$now     = $queue[ $index ] ?? null;
		$summary = is_array( $crawl['summary'] ?? null ) ? $crawl['summary'] : array();
		$current = is_array( $crawl['current'] ?? null ) ? $crawl['current'] : array();
		$done    = ! empty( $state['done'] );
		$out     = array(
			'job'       => (string) ( $state['id'] ?? '' ),
			'total'     => count( $queue ),
			'index'     => min( $index, count( $queue ) ),
			'label'     => is_array( $now ) ? (string) ( $now['label'] ?? $now['path'] ?? '' ) : '',
			'phase'     => (string) ( $crawl['phase'] ?? '' ),
			'created'   => count( (array) ( $summary['pages_created'] ?? array() ) ),
			'errors'    => count( (array) ( $summary['crawl_errors'] ?? array() ) ),
			'done'      => $done,
			/*
			 * Detail for the wizard's progress panel, all read from what the
			 * job already stores: the page being worked on, where it is in its
			 * four phases, and what every page in the queue came to so far.
			 */
			'path'      => is_array( $now ) ? (string) ( $now['path'] ?? '' ) : '',
			// The live page's own <title>, once it has been fetched.
			'title'     => (string) ( $current['title'] ?? '' ),
			'images'    => array(
				'done' => count( (array) ( $current['images'] ?? array() ) ),
				'left' => count( (array) ( $current['pending'] ?? array() ) ),
			),
			'restyled'  => (int) ( $summary['pages_restyled'] ?? 0 ),
			'skipped'   => count( (array) ( $summary['pages_skipped'] ?? array() ) ),
			'ai'        => ! empty( $crawl['setup']['ai_ok'] ) && 'ai' === ( $crawl['setup']['engine'] ?? '' ),
			'engine'    => (string) ( $summary['restyle_engine'] ?? '' ),
			// Every page is through; the next step runs the finishing pass.
			'finishing' => ! $done && $index >= count( $queue ),
			'queue'     => self::queue_detail( $queue, $index, $summary, $done ),
			'started'   => (int) ( $state['created'] ?? 0 ),
			'updated'   => (int) ( $state['touched'] ?? 0 ),
			'now'       => time(),
		);
		if ( $done ) {
			$out['result'] = $state['result'] ?? array();
		}

		return $out;
	}

	/**
	 * What each queued page has come to: created (with its id), skipped
	 * (with the reason), failed (with the message), being worked on, or
	 * waiting. Labels, paths and short strings only — a queue is at most
	 * Site_Origin::MAX_PAGES long.
	 *
	 * @param array<int, mixed>    $queue
	 * @param array<string, mixed> $summary
	 * @return array<int, array<string, mixed>>
	 */
	private static function queue_detail( array $queue, int $index, array $summary, bool $done ): array {
		$created = array();
		foreach ( (array) ( $summary['pages_created'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$created[ (string) ( $row['path'] ?? '' ) ] = (int) ( $row['id'] ?? 0 );
			}
		}
		$skipped = array();
		foreach ( (array) ( $summary['pages_skipped'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$skipped[ (string) ( $row['path'] ?? '' ) ] = (string) ( $row['reason'] ?? '' );
			}
		}
		$failed = array();
		foreach ( (array) ( $summary['crawl_errors'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (string) ( $row['url'] ?? '' ) !== '' ) {
				$failed[ (string) $row['url'] ] = (string) ( $row['message'] ?? '' );
			}
		}

		$out = array();
		foreach ( $queue as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$path = (string) ( $item['path'] ?? '' );
			$url  = (string) ( $item['url'] ?? '' );
			$row  = array(
				'label' => (string) ( $item['label'] ?? '' ),
				'path'  => $path,
				'state' => 'pending',
			);
			if ( $i === $index && ! $done ) {
				$row['state'] = 'current';
			} elseif ( $i < $index || $done ) {
				if ( isset( $created[ $path ] ) ) {
					$row['state'] = 'created';
					$row['id']    = $created[ $path ];
				} elseif ( isset( $skipped[ $path ] ) ) {
					$row['state']  = 'skipped';
					$row['reason'] = $skipped[ $path ];
				} elseif ( isset( $failed[ $url ] ) ) {
					$row['state']   = 'error';
					$row['message'] = $failed[ $url ];
				} else {
					$row['state'] = 'done';
				}
			}
			$out[] = $row;
		}

		return $out;
	}

	private static function schedule( string $id, int $delay ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $id ) );
		}
	}

	/**
	 * Remove jobs nobody has advanced for MAX_AGE (a closed tab on a site
	 * without cron), with their locks and schedules.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%'
			)
		);
		foreach ( $names as $name ) {
			$state = get_option( (string) $name, null );
			if ( ! is_array( $state ) || time() - (int) ( $state['touched'] ?? 0 ) > self::MAX_AGE ) {
				self::delete( substr( (string) $name, strlen( self::PREFIX ) ) );
			}
		}
	}

	private static function clean_id( string $id ): string {
		return (string) preg_replace( '/[^a-f0-9]/', '', strtolower( $id ) );
	}
}

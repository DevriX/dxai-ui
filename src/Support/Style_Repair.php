<?php
/**
 * Puts back what KSES removed from converted pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Support;

use DXAI_UI\Compiler\Block_Normalizer;
use DXAI_UI\Structures\Site_From_Menu;

/**
 * Before Kses_Styles existed, a converted page saved by anything without
 * `unfiltered_html` — a WP-CLI or cron run with no user, a site admin on
 * multisite, an Editor on a site with DISALLOW_UNFILTERED_HTML — came back
 * with part of its design removed from the HTML: rgba() colours, text-wrap,
 * inset, box-sizing, iframes, form controls. Every block whose CSS is also
 * kept in its `dxaiStyle` attribute then opened as invalid in the editor,
 * because the attribute still held what the HTML had lost. On the install
 * this was measured on: 231 blocks over 16 pages, and one page that had lost
 * its map iframe and 19 form controls.
 *
 * Two repairs, each only where the evidence says the change was KSES and
 * nothing else:
 *
 *  1. From a revision. When the page's current content was written by a
 *     user without unfiltered_html, and an older revision was written by one
 *     who had it, each block is compared with the same block in that
 *     revision. A block whose current version is the revision's version with
 *     things REMOVED — same text, same tags in the same order minus some —
 *     is put back as the revision had it. Nothing is restored when the two
 *     documents do not have the same blocks in the same order, so an edit
 *     made since (a block added, moved or deleted) is never undone.
 *
 *  2. From the block's own attribute. Where the style="" in the HTML holds a
 *     subset of the declarations the block's `dxaiStyle` (or dxai-ui/link
 *     `style`) attribute holds, the HTML is given the attribute's list —
 *     exactly what the editor's save() writes. A block whose HTML has a
 *     declaration the attribute lacks is left alone: that difference is not
 *     KSES's.
 *
 * And one that is not about KSES at all, for crawled pages only (posts with
 * `_dxai_ui_from_live_menu`):
 *
 *  3. Block_Normalizer. Those pages are model-written — Live_Page_Restyler
 *     stores the markup a language model returned — and the model writes
 *     block shapes whose save() does not reproduce the stored HTML (a
 *     comment inside a group, `dxaiStyle` on a core/image, a dynamic form
 *     stored with markup): 15 invalid blocks on 6 of the 15 crawled pages of
 *     the development site. The restyler now normalises what it returns;
 *     this puts the pages it stored before that through the same pass. It
 *     runs after the first two, on what they produced, and has its own rule
 *     of evidence, set out on Block_Normalizer: the stored HTML is what
 *     visitors get, so the attributes are moved to match it, and a block
 *     whose page would look different is left as it was. Design pages and
 *     template parts are never given to it — the compiler wrote those, and
 *     on every one of the 41 measured the pass changed nothing.
 *
 * Writes go through wp_update_post(), so every repair is a revision and can
 * be undone from the post's history. KSES is suspended for that one write:
 * the repair only puts back markup a trusted author wrote, or the block's
 * own attribute, or (the third) moves what is already in the page from one
 * place to another, and re-filtering the page would remove it again.
 *
 * Runs once by itself (a batch per admin page load, until every converted
 * post has been looked at), and on demand with `wp dxai-ui repair-styles`.
 */
final class Style_Repair {

	/** Progress of the one-time pass: version, last post id looked at, done. */
	public const OPTION = 'dxai_ui_style_repair';

	/**
	 * Bump to run the first two repairs again over every converted post.
	 *
	 * Deliberately not bumped for the third. Re-running the first two would
	 * face every edit made since version 1 finished with the revision rule —
	 * and once Kses_Styles exists, "the same block with things removed, by
	 * someone without unfiltered_html" mostly describes a deliberate edit (a
	 * site admin on multisite unlinking a word on a page a super admin
	 * imported), which step 1 would quietly put back.
	 */
	public const VERSION = 1;

	/**
	 * Progress of the third repair's own one-time pass, over crawled pages
	 * only: a crawled page stored before the restyler normalised its output
	 * would otherwise stay invalid until someone ran the CLI command or paid
	 * for a re-crawl.
	 */
	public const NORMALIZE_OPTION = 'dxai_ui_style_normalize';

	public const NORMALIZE_VERSION = 1;

	/** Posts per admin page load. */
	private const BATCH = 10;

	private const SKIP_BLOCKS = array( 'dxai-ui/svg' );

	private const STYLE_ATTRS = array( 'dxaiStyle', 'style' );

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_run_batch' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'dxai-ui repair-styles', array( self::class, 'cli' ) );
		}
	}

	/**
	 * One batch of the one-time pass, on an ordinary admin page load by
	 * someone who can run imports. No cron, so it also runs on hosts where
	 * wp-cron is disabled, and a batch is small enough for any time limit.
	 */
	public function maybe_run_batch(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// One pass per page load: the normalising pass starts once the first
		// has finished.
		if ( self::run_pass( self::OPTION, self::VERSION, 'kses' ) ) {
			self::run_pass( self::NORMALIZE_OPTION, self::NORMALIZE_VERSION, 'normalize' );
		}
	}

	/**
	 * One batch of a one-time pass; true when that pass has finished.
	 *
	 * @param string $steps 'kses' (the first two repairs, every converted
	 *                      post) or 'normalize' (the third, crawled pages).
	 */
	private static function run_pass( string $option, int $version, string $steps ): bool {
		$state = get_option( $option, array() );
		$state = is_array( $state ) ? $state : array();
		if ( (int) ( $state['version'] ?? 0 ) >= $version && ! empty( $state['done'] ) ) {
			return true;
		}
		if ( (int) ( $state['version'] ?? 0 ) < $version ) {
			$state = array(
				'version'  => $version,
				'last_id'  => 0,
				'done'     => false,
				'repaired' => 0,
			);
		}

		$ids = 'normalize' === $steps
			? self::crawled( (int) $state['last_id'], self::BATCH )
			: self::candidates( (int) $state['last_id'], self::BATCH );
		foreach ( $ids as $id ) {
			$result = self::repair_post( $id, false, $steps );
			if ( $result['written'] ) {
				++$state['repaired'];
			}
			$state['last_id'] = $id;
		}
		if ( count( $ids ) < self::BATCH ) {
			$state['done'] = true;
		}
		update_option( $option, $state, false );

		return ! empty( $state['done'] );
	}

	/**
	 * `wp dxai-ui repair-styles [--dry-run] [--ids=<id,id>]`
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function cli( array $args, array $assoc ): void {
		$dry = isset( $assoc['dry-run'] );
		$ids = isset( $assoc['ids'] )
			? array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) )
			: self::candidates( 0, 0 );
		$total = array(
			'posts'      => 0,
			'written'    => 0,
			'revision'   => 0,
			'attribute'  => 0,
			'normalized' => 0,
		);
		foreach ( $ids as $id ) {
			$r = self::repair_post( (int) $id, $dry );
			++$total['posts'];
			$total['revision']   += $r['from_revision'];
			$total['attribute']  += $r['from_attribute'];
			$total['normalized'] += $r['normalized'];
			if ( $r['written'] || ( $dry && $r['changed'] ) ) {
				++$total['written'];
				\WP_CLI::log( sprintf( '%d  %s: %d block(s) from revision %d, %d from their attributes, %d normalized', $id, $dry ? 'would repair' : 'repaired', $r['from_revision'], $r['revision_id'], $r['from_attribute'], $r['normalized'] ) );
			}
		}
		\WP_CLI::success( sprintf( '%d post(s) checked, %d %s; %d block(s) from revisions, %d from attributes, %d normalized.', $total['posts'], $total['written'], $dry ? 'would change' : 'changed', $total['revision'], $total['attribute'], $total['normalized'] ) );
	}

	/**
	 * Converted posts after $after_id: pages (design and crawled), and the
	 * template parts and patterns this plugin generated.
	 *
	 * @return array<int, int>
	 */
	public static function candidates( int $after_id, int $limit ): array {
		global $wpdb;

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE m.meta_key IN ('_dxai_ui_generated_page','_dxai_ui_generated','_dxai_ui_from_live_menu')
			AND p.post_type IN ('page','wp_template_part','wp_block') AND p.post_status NOT IN ('trash','auto-draft','inherit')
			AND p.ID > %d ORDER BY p.ID ASC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $after_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Crawled pages after $after_id, for the normalising pass.
	 *
	 * @return array<int, int>
	 */
	private static function crawled( int $after_id, int $limit ): array {
		global $wpdb;

		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					WHERE m.meta_key = '_dxai_ui_from_live_menu' AND p.post_type = 'page'
					AND p.post_status NOT IN ('trash','auto-draft','inherit')
					AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
					$after_id,
					max( 1, $limit )
				)
			)
		);
	}

	/**
	 * @param string $steps 'all' (the CLI), 'kses' (the first two repairs
	 *                      only) or 'normalize' (the third only).
	 * @return array{changed:bool, written:bool, from_revision:int, from_attribute:int, normalized:int, revision_id:int}
	 */
	public static function repair_post( int $id, bool $dry = false, string $steps = 'all' ): array {
		$out  = array(
			'changed'        => false,
			'written'        => false,
			'from_revision'  => 0,
			'from_attribute' => 0,
			'normalized'     => 0,
			'revision_id'    => 0,
		);
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! str_contains( (string) $post->post_content, '<!-- wp:' ) ) {
			return $out;
		}
		$content = (string) $post->post_content;
		$blocks  = parse_blocks( $content );
		$crawled = (bool) get_post_meta( $id, '_dxai_ui_from_live_menu', true );

		$source = 'normalize' === $steps ? null : self::trusted_revision( $post );
		if ( $source instanceof \WP_Post ) {
			$restored = 0;
			$blocks   = self::restore_from_revision( $blocks, parse_blocks( (string) $source->post_content ), $restored );
			if ( $restored > 0 ) {
				$out['from_revision'] = $restored;
				$out['revision_id']   = (int) $source->ID;
			}
		}

		if ( 'normalize' !== $steps ) {
			$synced                = 0;
			$blocks                = self::restore_from_attributes( $blocks, $synced );
			$out['from_attribute'] = $synced;
		}

		// Third, crawled pages only: model-written block shapes made to open
		// as valid blocks — see the class comment and Block_Normalizer.
		if ( 'kses' !== $steps && $crawled && class_exists( Block_Normalizer::class ) ) {
			$normalized        = 0;
			$blocks            = Block_Normalizer::normalize_blocks( $blocks, $normalized );
			$out['normalized'] = $normalized;
		}

		if ( $out['from_revision'] + $out['from_attribute'] + $out['normalized'] === 0 ) {
			return $out;
		}
		$repaired = serialize_blocks( $blocks );
		if ( $repaired === $content ) {
			return $out;
		}
		$out['changed'] = true;
		if ( $dry ) {
			return $out;
		}

		$clean  = class_exists( Site_From_Menu::class ) && $crawled && ! Site_From_Menu::edited_since_import( $post );
		$kses   = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		$saved = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( $repaired ),
			),
			true
		);
		if ( $kses ) {
			kses_init_filters();
		}
		$out['written'] = ! is_wp_error( $saved ) && (int) $saved > 0;
		// A crawled page nobody had edited still reads as unedited, so a
		// later re-crawl keeps updating it.
		if ( $out['written'] && $clean ) {
			Site_From_Menu::mark_imported( $id );
		}

		return $out;
	}

	/**
	 * The newest revision written by someone with unfiltered_html, when the
	 * current content was written by someone without it — the signature of
	 * a KSES-filtered save. Null when the current content is trusted or no
	 * such revision exists.
	 */
	private static function trusted_revision( \WP_Post $post ): ?\WP_Post {
		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'order'   => 'DESC',
				'orderby' => 'date ID',
			)
		);
		$current   = self::canonical( (string) $post->post_content );
		$head_seen = false;
		foreach ( $revisions as $revision ) {
			if ( wp_is_post_autosave( $revision ) ) {
				continue;
			}
			if ( ! $head_seen ) {
				if ( self::canonical( (string) $revision->post_content ) !== $current ) {
					continue;
				}
				$head_seen = true;
				if ( self::trusted( (int) $revision->post_author ) ) {
					return null;
				}
				continue;
			}
			if ( self::trusted( (int) $revision->post_author ) && self::canonical( (string) $revision->post_content ) !== $current ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * A block document in one spelling, so the revision that recorded the
	 * current content is recognised although something re-serialised it
	 * since without making a revision — an empty block written as a pair of
	 * comments by one serialiser and as a void comment by another.
	 */
	private static function canonical( string $content ): string {
		$content = (string) preg_replace( '#<!-- wp:([a-z0-9/_-]+)((?: \{.*?\})?) --><!-- /wp:\1 -->#s', '<!-- wp:$1$2 /-->', $content );

		return serialize_blocks( parse_blocks( $content ) );
	}

	private static function trusted( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'unfiltered_html' );
	}

	/**
	 * Restore removal-only block differences from the revision's blocks,
	 * when both documents have the same blocks in the same order.
	 *
	 * @param array<int, array<string, mixed>> $current
	 * @param array<int, array<string, mixed>> $source
	 * @return array<int, array<string, mixed>>
	 */
	private static function restore_from_revision( array $current, array $source, int &$restored ): array {
		$current_named = array_values( array_filter( $current, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$source_named  = array_values( array_filter( $source, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		if ( count( $current_named ) !== count( $source_named ) ) {
			return $current;
		}
		foreach ( $current_named as $i => $block ) {
			if ( $block['blockName'] !== $source_named[ $i ]['blockName'] ) {
				return $current;
			}
		}

		$j = 0;
		foreach ( $current as $k => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$from = $source_named[ $j++ ];
			if ( ! empty( $block['innerBlocks'] ) || ! empty( $from['innerBlocks'] ) ) {
				if ( count( (array) $block['innerBlocks'] ) !== count( (array) $from['innerBlocks'] ) ) {
					continue;
				}
				$current[ $k ]['innerBlocks'] = self::restore_from_revision( (array) $block['innerBlocks'], (array) $from['innerBlocks'], $restored );
				// The block's own markup around its children.
				$chunks = self::string_chunks( $block );
				$theirs = self::string_chunks( $from );
				if ( count( $chunks ) === count( $theirs ) && $chunks !== $theirs && self::only_removed( implode( '', $chunks ), implode( '', $theirs ) ) ) {
					$current[ $k ]['innerContent'] = self::replace_chunks( (array) $block['innerContent'], $theirs );
					$current[ $k ]['attrs']        = (array) $from['attrs'];
					++$restored;
				}
				continue;
			}
			$mine   = (string) ( $block['innerHTML'] ?? '' );
			$theirs = (string) ( $from['innerHTML'] ?? '' );
			if ( $mine === $theirs && (array) $block['attrs'] == (array) $from['attrs'] ) {
				continue;
			}
			if ( self::only_removed( $mine, $theirs ) ) {
				$current[ $k ] = $from;
				++$restored;
			}
		}

		return $current;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function string_chunks( array $block ): array {
		return array_values( array_filter( (array) ( $block['innerContent'] ?? array() ), 'is_string' ) );
	}

	/**
	 * @param array<int, string|null> $inner
	 * @param array<int, string>      $chunks
	 * @return array<int, string|null>
	 */
	private static function replace_chunks( array $inner, array $chunks ): array {
		$n = 0;
		foreach ( $inner as $i => $chunk ) {
			if ( is_string( $chunk ) ) {
				$inner[ $i ] = $chunks[ $n++ ] ?? $chunk;
			}
		}

		return $inner;
	}

	/**
	 * Whether $mine is $theirs with elements, attributes or declarations
	 * removed and nothing added or changed: the same text, and its tags a
	 * subsequence of theirs (by tag name, in order).
	 */
	private static function only_removed( string $mine, string $theirs ): bool {
		if ( $mine === $theirs ) {
			return false;
		}
		$text = static fn( string $h ): string => (string) preg_replace( '/\s+/', ' ', trim( html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		if ( $text( $mine ) !== $text( $theirs ) ) {
			return false;
		}
		preg_match_all( '/<([a-z][a-z0-9-]*)\b/i', $mine, $a );
		preg_match_all( '/<([a-z][a-z0-9-]*)\b/i', $theirs, $b );
		$ours   = array_map( 'strtolower', $a[1] );
		$source = array_map( 'strtolower', $b[1] );
		$at     = 0;
		foreach ( $ours as $tag ) {
			while ( $at < count( $source ) && $source[ $at ] !== $tag ) {
				++$at;
			}
			if ( $at >= count( $source ) ) {
				return false;
			}
			++$at;
		}

		return strlen( $mine ) < strlen( $theirs );
	}

	/**
	 * Give each block's root style="" the declarations its CSS attribute
	 * holds, where the HTML holds a subset of them.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function restore_from_attributes( array $blocks, int &$synced ): array {
		foreach ( $blocks as $i => $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( $name === '' || in_array( $name, self::SKIP_BLOCKS, true ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $i ]['innerBlocks'] = self::restore_from_attributes( $block['innerBlocks'], $synced );
			}
			$css = null;
			foreach ( self::STYLE_ATTRS as $key ) {
				if ( isset( $block['attrs'][ $key ] ) && is_string( $block['attrs'][ $key ] ) ) {
					$css = $block['attrs'][ $key ];
					break;
				}
			}
			if ( $css === null ) {
				continue;
			}
			$first = null;
			foreach ( (array) $block['innerContent'] as $n => $chunk ) {
				if ( is_string( $chunk ) && trim( $chunk ) !== '' ) {
					$first = $n;
					break;
				}
			}
			if ( $first === null ) {
				continue;
			}
			$chunk = (string) $block['innerContent'][ $first ];
			if ( preg_match( '/^(\s*<[a-z][a-z0-9-]*)(\b[^>]*)(>)/i', $chunk, $tag ) !== 1 ) {
				continue;
			}
			$attrs = $tag[2];
			$html  = preg_match( '/\sstyle="([^"]*)"/i', $attrs, $s ) === 1 ? html_entity_decode( $s[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			$want  = self::declarations( $css );
			$have  = self::declarations( $html );
			if ( $want == $have || array_diff_assoc( $have, $want ) !== array() ) {
				continue;
			}
			$style = ' style="' . esc_attr( $css ) . '"';
			$attrs = preg_match( '/\sstyle="[^"]*"/i', $attrs ) === 1
				? (string) preg_replace( '/\sstyle="[^"]*"/i', $style, $attrs, 1 )
				: $style . $attrs;
			$fixed = $tag[1] . $attrs . $tag[3] . substr( $chunk, strlen( $tag[0] ) );
			$blocks[ $i ]['innerContent'][ $first ] = $fixed;
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) && str_starts_with( $block['innerHTML'], $chunk ) ) {
				$blocks[ $i ]['innerHTML'] = $fixed . substr( $block['innerHTML'], strlen( $chunk ) );
			}
			++$synced;
		}

		return $blocks;
	}

	/**
	 * A declaration list as property => value, in the way the editor compares
	 * them: split on ";" and the first ":", trimmed, order ignored.
	 *
	 * @return array<string, string>
	 */
	private static function declarations( string $css ): array {
		$out = array();
		foreach ( explode( ';', str_replace( array( "\n", "\r", "\t" ), '', $css ) ) as $part ) {
			$part = trim( $part );
			if ( $part === '' || ! str_contains( $part, ':' ) ) {
				continue;
			}
			list( $property, $value ) = explode( ':', $part, 2 );
			$out[ strtolower( trim( $property ) ) ] = trim( $value );
		}
		ksort( $out );

		return $out;
	}
}

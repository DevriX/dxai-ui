<?php
/**
 * Purge every DXAI-UI generated page, navigation, template part, pattern and
 * conversion record, and the classic menus DXAI wrote next to them.
 *
 * Usage: php bin/purge-dxai-generated.php <wp-root> [--dry-run]
 *
 *   --dry-run  Print every post, classic menu, menu item and menu location the
 *              purge would remove, then stop. Nothing is deleted or written.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$cli_args = array_slice( $argv, 1 );
$dry_run  = in_array( '--dry-run', $cli_args, true );
$wp_root  = (string) ( array_values( array_diff( $cli_args, array( '--dry-run' ) ) )[0] ?? '' );
if ( $wp_root === '' ) {
	fwrite( STDERR, "usage: php bin/purge-dxai-generated.php <wp-root> [--dry-run]\n" );
	exit( 1 );
}

require rtrim( $wp_root, "/\\" ) . '/wp-load.php';

$user = get_user_by( 'login', 'admin' );
if ( $user instanceof WP_User ) {
	wp_set_current_user( $user->ID );
}

/**
 * IDs of every post matching $args, in any status. Deletes nothing.
 *
 * @param array<string, mixed> $args
 * @return array<int, int>
 */
function dxai_purge_ids( array $args ): array {
	$args = array_merge(
		array(
			'posts_per_page'         => -1,
			'post_status'            => array_keys( get_post_stati() ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		),
		$args
	);

	return array_map( 'intval', ( new WP_Query( $args ) )->posts );
}

/**
 * JSON-quoted, so a title with spaces, quotes or a newline stays one field.
 */
function dxai_purge_quote( string $text ): string {
	return (string) wp_json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/*
 * 1) Decide everything first, delete second. The classic menus below are
 * identified THROUGH the navigation posts, so the plan has to be complete
 * before the first navigation post goes — the old script deleted the
 * navigations and then had nothing left to tell a DXAI menu from anyone
 * else's, which is why it deleted them all.
 */
$plan = array(
	'pages'          => dxai_purge_ids(
		array(
			'post_type'  => 'page',
			'meta_key'   => '_dxai_ui_generated_page',
			'meta_value' => '1',
		)
	),
	'template_parts' => dxai_purge_ids(
		array(
			'post_type'  => 'wp_template_part',
			'meta_key'   => '_dxai_ui_generated',
			'meta_value' => '1',
		)
	),
	'navigations'    => dxai_purge_ids(
		array(
			'post_type'  => 'wp_navigation',
			'meta_key'   => '_dxai_ui_generated',
			'meta_value' => '1',
		)
	),
	'patterns'       => dxai_purge_ids(
		array(
			'post_type'  => 'wp_block',
			'meta_key'   => '_dxai_ui_generated',
			'meta_value' => '1',
		)
	),
	// The plugin's own post type: every row in it is DXAI's.
	'conversions'    => dxai_purge_ids(
		array(
			'post_type' => 'dxai_ui_conversion',
		)
	),
);

/*
 * 2) Classic menus DXAI created — and only those.
 *
 * This loop used to call wp_delete_nav_menu() on EVERY classic menu on the
 * site ("user asked for a clean slate"), so a person's own menus went with
 * the generated ones, unrecoverably: wp_delete_nav_menu() has no trash, and
 * wp_delete_post( $id, true ) skips it for the items too.
 *
 * The menu term itself carries no DXAI marker: Navigation_Factory never
 * writes term meta, and the name has no prefix (measured 2026-09-24: 16
 * classic menus, every one with termmeta=[], named "<design> Primary" /
 * "<design> Footer"). What does mark it is where it comes from.
 * Navigation_Factory::save() inserts a wp_navigation post carrying
 * `_dxai_ui_generated = 1`, and only once that insert succeeds does
 * classic_menu() create a classic menu under the SAME title — and on the
 * next import it finds that menu again by name (get_term_by( 'name', $title ) —
 * not wp_get_nav_menu_object(), which casts a digit-led title to a term ID)
 * and rewrites it. So a classic menu is DXAI's exactly when that lookup,
 * given the title of a DXAI navigation post, returns it. Using the factory's
 * own lookup also absorbs the term-name escaping: the navigation titled
 * "Angel Reyes & Associates Primary" is stored as the term name
 * "Angel Reyes &amp; Associates Primary", which a plain string compare would
 * miss. The raw post_title is read, not get_the_title(), whose texturize
 * filters would turn that `&` into `&#038;`.
 *
 * Menu LOCATION is deliberately not evidence. "DXAI Primary" / "DXAI Footer"
 * are ordinary locations in Appearance > Menus, and a person can assign a
 * menu of their own to one. A DXAI menu whose navigation post is already
 * gone therefore survives this purge; it is listed as `kept` so it can be
 * removed by hand. Leaving one generated menu behind is recoverable;
 * deleting someone's menu is not.
 */
$dxai_menus = array(); // term_id => array{name:string, items:array<int, int>, navigation:int}
foreach ( $plan['navigations'] as $nav_id ) {
	$title = (string) get_post_field( 'post_title', $nav_id, 'raw' );
	if ( $title === '' ) {
		continue;
	}
	/*
	 * By NAME only. wp_get_nav_menu_object() tries get_term( $title ) first,
	 * and get_term() casts its argument to an integer — so a DXAI navigation
	 * titled "208 Plumbing Primary" or "24/7 Plumbing Primary" resolved to the
	 * nav_menu term whose ID is 208 or 24, which can be a menu a person made,
	 * and this purge would have deleted it with no trash to recover it from.
	 * get_term_by( 'name' ) runs the same sanitize_term_field( 'db' ) the term
	 * was stored with, so escaped names ("… &amp; Associates Primary") still
	 * match.
	 */
	$menu = get_term_by( 'name', $title, 'nav_menu' );
	if ( ! $menu instanceof WP_Term || isset( $dxai_menus[ (int) $menu->term_id ] ) ) {
		continue;
	}
	$items = get_objects_in_term( (int) $menu->term_id, 'nav_menu' );

	$dxai_menus[ (int) $menu->term_id ] = array(
		'name'       => (string) $menu->name,
		// The same list wp_delete_nav_menu() walks and deletes.
		'items'      => is_array( $items ) ? array_map( 'intval', $items ) : array(),
		'navigation' => $nav_id,
	);
}
$kept_menus = array();
foreach ( wp_get_nav_menus() as $menu ) {
	if ( ! isset( $dxai_menus[ (int) $menu->term_id ] ) ) {
		$kept_menus[ (int) $menu->term_id ] = (string) $menu->name;
	}
}

/*
 * 3) Menu items core removes on its own. wp_delete_post() fires
 * `delete_post`, and _wp_delete_post_menu_item() (wp-includes/nav-menu.php,
 * hooked in default-filters.php) deletes every menu item that points at the
 * deleted post — in ANY menu, including a kept one. Nothing here can stop
 * that without leaving dangling links behind, but a dry run has to name it:
 * a person's own menu loses its item for a DXAI page when the page goes.
 * Items inside a DXAI menu are not listed again; they go with the menu.
 */
$collateral = array(); // item_id => array{menu:int, target:int}
foreach ( $plan as $ids ) {
	foreach ( $ids as $post_id ) {
		foreach ( (array) wp_get_associated_nav_menu_items( $post_id, 'post_type' ) as $item_id ) {
			$terms   = wp_get_object_terms( (int) $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
			$menu_id = is_array( $terms ) && $terms !== array() ? (int) $terms[0] : 0;
			if ( isset( $dxai_menus[ $menu_id ] ) ) {
				continue;
			}
			$collateral[ (int) $item_id ] = array(
				'menu'   => $menu_id,
				'target' => $post_id,
			);
		}
	}
}

/*
 * 4) Menu locations. The old script ended with
 * set_theme_mod( 'nav_menu_locations', array() ), which also unassigned any
 * location pointing at a menu it had no business touching. Only locations
 * that point at a DXAI menu are cleared now; wp_delete_nav_menu() zeroes
 * those itself, and they are then unset so no stale 0 is left behind.
 */
$locations       = (array) get_theme_mod( 'nav_menu_locations', array() );
$clear_locations = array();
foreach ( $locations as $location => $menu_id ) {
	if ( isset( $dxai_menus[ (int) $menu_id ] ) ) {
		$clear_locations[ (string) $location ] = (int) $menu_id;
	}
}

/*
 * 5) The listing. It is the same list the deletion below walks, so a dry run
 * prints exactly what a real run removes. `revisions` is counted with the
 * query wp_delete_post() itself runs to delete them.
 */
global $wpdb;
$verb = $dry_run ? 'would_delete' : 'deleting';
foreach ( $plan as $group => $ids ) {
	foreach ( $ids as $id ) {
		$post      = get_post( $id );
		$revisions = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision'", $id )
		);
		printf(
			"%s %s id=%d type=%s status=%s revisions=%d title=%s\n",
			$verb,
			$group,
			$id,
			$post instanceof WP_Post ? $post->post_type : '?',
			$post instanceof WP_Post ? $post->post_status : '?',
			$revisions,
			dxai_purge_quote( $post instanceof WP_Post ? $post->post_title : '' )
		);
	}
}
foreach ( $dxai_menus as $term_id => $menu ) {
	printf(
		"%s classic_menu term=%d items=%d navigation=%d name=%s\n",
		$verb,
		$term_id,
		count( $menu['items'] ),
		$menu['navigation'],
		dxai_purge_quote( $menu['name'] )
	);
}
foreach ( $collateral as $item_id => $row ) {
	printf(
		"%s menu_item id=%d in_kept_menu=%d name=%s links_to=%d title=%s\n",
		$verb,
		$item_id,
		$row['menu'],
		dxai_purge_quote( $kept_menus[ $row['menu'] ] ?? '' ),
		$row['target'],
		dxai_purge_quote( (string) get_post_field( 'post_title', $item_id, 'raw' ) )
	);
}
foreach ( $clear_locations as $location => $menu_id ) {
	printf( "%s nav_menu_location %s=%d\n", $dry_run ? 'would_clear' : 'clearing', $location, $menu_id );
}
foreach ( $kept_menus as $term_id => $name ) {
	printf( "kept classic_menu term=%d name=%s reason=no_dxai_navigation_of_that_name\n", $term_id, dxai_purge_quote( $name ) );
}

if ( $dry_run ) {
	foreach ( $plan as $group => $ids ) {
		echo "{$group}=" . count( $ids ) . "\n";
	}
	echo 'classic_menus=' . count( $dxai_menus ) . "\n";
	echo 'classic_menu_items=' . array_sum( array_map( static fn( array $m ): int => count( $m['items'] ), $dxai_menus ) ) . "\n";
	echo 'collateral_menu_items=' . count( $collateral ) . "\n";
	echo 'locations_cleared=' . count( $clear_locations ) . "\n";
	echo 'classic_menus_kept=' . count( $kept_menus ) . "\n";
	echo "purge=dry-run (nothing deleted)\n";
	exit( 0 );
}

// 6) Delete, in the order the plan was listed.
$deleted = array();
foreach ( $plan as $group => $ids ) {
	$deleted[ $group ] = 0;
	foreach ( $ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			++$deleted[ $group ];
		}
	}
}

$menus_deleted = 0;
foreach ( array_keys( $dxai_menus ) as $term_id ) {
	// A WP_Error is truthy, so a failed delete used to be counted as done.
	if ( true === wp_delete_nav_menu( $term_id ) ) {
		++$menus_deleted;
	}
}

if ( $clear_locations !== array() ) {
	$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
	foreach ( array_keys( $clear_locations ) as $location ) {
		unset( $locations[ $location ] );
	}
	set_theme_mod( 'nav_menu_locations', $locations );
}

foreach ( $deleted as $group => $count ) {
	echo "{$group}={$count}\n";
}
echo "classic_menus={$menus_deleted}\n";
echo 'classic_menus_kept=' . count( $kept_menus ) . "\n";
echo "purge=ok\n";

<?php
/**
 * A design's tokens as a theme.json style variation, and the theme capability contract.
 *
 *   bash bin/wp-php.sh bin/verify-style-variation.php        (or: wp eval-file bin/verify-style-variation.php --user=1)
 *
 * Capabilities answers what the active theme offers a design without its name. Style_Variation makes a design's variation from its
 * document (presets under the brand slugs, its fonts with their faces when hosted, its content width, the styles that make it the
 * site's), applies it as the theme layer of theme.json — presets everywhere, styles everywhere but on the design's own pages, which
 * stay drawn by their own rules —, takes it off, and writes it into the theme's styles folder only when the theme is the plugin's own.
 *
 * Against the small Claude Design fixture (.verify/dc-minimal.zip), imported under a name of its own and removed when the suite ends,
 * however it ends; the site's variation and brand design are put back as they were. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Compiler\Source_Compiler;
use DXAI_UI\Compiler\Token_Styles;
use DXAI_UI\Connectors\Dc_Connector;
use DXAI_UI\Content\Content_Types;
use DXAI_UI\Design\Document_Store;
use DXAI_UI\Design\Style_Variation;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Structures\Structure_Repository;
use DXAI_UI\Support\Upload_Paths;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Design_Theme_Json;
use DXAI_UI\Theme\Theme_Palette;

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

$run       = substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
$made      = array();
$saved     = array();
$max_att   = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$variation = get_option( Style_Variation::OPTION );
$brand     = get_option( Design_Theme_Json::OPTION );
$tmp_dir   = '';
$fin       = static function () use ( &$made, &$saved, $max_att, $variation, $brand, &$tmp_dir ): void {
	global $wpdb;
	// The site's look and brand as they were.
	if ( is_array( $variation ) ) {
		update_option( Style_Variation::OPTION, $variation, false );
	} else {
		delete_option( Style_Variation::OPTION );
	}
	if ( is_array( $brand ) ) {
		update_option( Design_Theme_Json::OPTION, $brand, false );
	} else {
		delete_option( Design_Theme_Json::OPTION );
	}
	Design_Theme_Json::flush();
	Style_Variation::assume_page( null );
	if ( $tmp_dir !== '' && is_dir( $tmp_dir ) ) {
		foreach ( (array) glob( $tmp_dir . '/*.json' ) as $f ) {
			wp_delete_file( (string) $f );
		}
		@rmdir( $tmp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$home = (int) ( $saved['page_id'] ?? 0 );
	if ( $home > 0 ) {
		$file = Upload_Paths::for_meta( $home, '_dxai_ui_css_url' )['path'] ?? '';
		if ( is_string( $file ) && $file !== '' && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => Page_Scope::META, 'meta_value' => $home ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_post( (int) $id, true );
		}
		foreach ( get_posts( array( 'post_type' => Content_Types::CONVERSION, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => '_dxai_ui_page_id', 'meta_value' => $home ) ) as $id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			wp_delete_post( (int) $id, true );
		}
		$ids = array_merge(
			array( $home, (int) ( $saved['header_id'] ?? 0 ), (int) ( $saved['footer_id'] ?? 0 ) ),
			array_map( 'intval', (array) ( $saved['extra_page_ids'] ?? array() ) ),
			array_map( 'intval', (array) ( $saved['navigation_ids'] ?? array() ) ),
			array_map( static fn( $p ) => (int) ( is_array( $p ) ? ( $p['id'] ?? 0 ) : $p ), (array) ( $saved['patterns'] ?? array() ) )
		);
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$new = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dxai_ui_asset_kind' WHERE p.post_type = 'attachment' AND p.ID > %d", $max_att ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $new as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		$saved = array();
	}
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $fin );

echo "What the theme offers a design (Capabilities)\n";
$cap = Capabilities::report();
$expect( 'the report says what a design needs to know of the theme: whether it is a DX theme, has a theme.json, draws the chrome, its picture block, its button styles, its palette, its sizes, its locations and areas', isset( $cap['theme']['slug'], $cap['theme']['dx'], $cap['theme']['theme_json'], $cap['draws_chrome'], $cap['picture_block'], $cap['button_styles'], $cap['palette'], $cap['layout']['content'], $cap['menu_locations'], $cap['widget_areas'], $cap['styles_writable'] ), wp_json_encode( array_keys( $cap ) ) );
$expect( 'the picture block is the theme\'s own when it registers one, else core\'s', in_array( Capabilities::picture_block(), array( 'dx/picture', 'core/image' ), true ) && ( \WP_Block_Type_Registry::get_instance()->is_registered( 'dx/picture' ) ? 'dx/picture' : 'core/image' ) === Capabilities::picture_block() );
$expect( 'the theme\'s palette is slug => hex', array() === array_filter( Capabilities::palette(), static fn( string $hex ): bool => $hex !== '' && preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) !== 1 ) );
$expect( 'a team\'s theme is never written into: its styles folder is not writable to the plugin unless the theme is the plugin\'s own', Capabilities::styles_writable() === ( \DXAI_UI\Theme\Base_Theme::is_ours( wp_normalize_path( get_stylesheet_directory() ) ) && wp_is_file_mod_allowed( 'x' ) && Capabilities::has_theme_json() ), get_stylesheet() );
$expect( 'the variations a theme lists are its styles/*.json, by file', is_array( Capabilities::variations() ) );

echo "\nThe fixture, imported under a name of its own\n";
$zip    = dirname( __DIR__ ) . '/.verify/dc-minimal.zip';
$source = ( new Dc_Connector() )->import_zip( array( 'tmp_name' => $zip, 'name' => 'dc-minimal.zip' ) );
if ( is_wp_error( $source ) ) {
	echo '  FAIL  the connector: ' . $source->get_error_message() . "\n";
	exit( 1 );
}
$native                 = ( new Source_Compiler() )->compile( $source );
$native['block_title']  = 'Style variation fixture ' . $run;
$native['slug']         = '/svar-fixture-' . $run . '/';
$native['source_name']  = 'svar-fixture-' . $run . '.zip';
$native['chrome']       = \DXAI_UI\Chrome\Chrome_Choice::KEEP;
$brand_before           = get_option( Design_Theme_Json::OPTION );
$created                = ( new Structure_Repository() )->save( $native );
if ( is_wp_error( $created ) ) {
	echo '  FAIL  the fixture saves: ' . $created->get_error_message() . "\n";
	exit( 1 );
}
$saved = $created;
$home  = (int) $created['page_id'];
$doc   = Document_Store::load( $home );
if ( $doc === null ) {
	echo "  FAIL  the fixture has no document\n";
	exit( 1 );
}
$plain = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Style variation plain page ' . $run, 'post_content' => '<!-- wp:paragraph --><p>A plain page of the site.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->' ) );
$made[] = $plain;

echo "\nThe variation\n";
$v = Style_Variation::build( $home );
$expect( 'it is a theme.json of version 3, titled after the design', is_array( $v ) && 3 === $v['version'] && (string) get_the_title( $home ) === $v['title'] && isset( $v['settings'], $v['styles'] ), is_wp_error( $v ) ? $v->get_error_message() : '' );
$roles   = $doc->get( 'tokens' )['roles'];
$palette = is_array( $v ) ? $v['settings']['color']['palette'] : array();
$by_slug = array_column( $palette, 'color', 'slug' );
$expect( 'its palette has the design\'s brand and accent under the brand design\'s slugs, in the document\'s colours', strtoupper( (string) $roles['brand'] ) === ( $by_slug['brand'] ?? '' ) && strtoupper( (string) $roles['accent'] ) === ( $by_slug['accent'] ?? '' ), wp_json_encode( $by_slug ) );
$expect( 'the slugs are the ones Design_Theme_Json registers for the brand design (the two never drift apart)', ( static function () use ( $roles ): bool {
	$fragment = Design_Theme_Json::fragment( array_intersect_key( $roles, Style_Variation::ROLE_SLUGS ), array() );
	$theirs   = array_column( $fragment['settings']['color']['palette'] ?? array(), 'slug' );
	$ours     = array_values( array_intersect_key( Style_Variation::ROLE_SLUGS, array_filter( $roles, 'strlen' ) ) );

	return $theirs !== array() && array() === array_diff( $ours, $theirs ) && array() === array_diff( $theirs, $ours );
} )() );
$expect( 'each slug once', count( $palette ) === count( array_unique( array_column( $palette, 'slug' ) ) ) );
$fonts = is_array( $v ) ? $v['settings']['typography']['fontFamilies'] : array();
$expect( 'its fonts are the design\'s, the heading\'s and the body\'s first, by family', count( $fonts ) >= 1 && 'dxai-heading' === ( $fonts[0]['slug'] ?? '' ) && '' !== (string) ( $fonts[0]['name'] ?? '' ), wp_json_encode( $fonts ) );
$expect( 'the styles make the design the site\'s: text in its body font, headings in its heading font, buttons in its brand, links in its accent', ( static function () use ( $v ): bool {
	$s = is_array( $v ) ? $v['styles'] : array();

	return 'var(--wp--preset--font-family--dxai-body)' === ( $s['typography']['fontFamily'] ?? '' )
		&& 'var(--wp--preset--font-family--dxai-heading)' === ( $s['elements']['heading']['typography']['fontFamily'] ?? '' )
		&& 'var(--wp--preset--color--brand)' === ( $s['elements']['button']['color']['background'] ?? '' )
		&& 'var(--wp--preset--color--accent)' === ( $s['elements']['link']['color']['text'] ?? '' );
} )(), wp_json_encode( is_array( $v ) ? $v['styles'] : null ) );
$json = Style_Variation::json( $home );
$expect( 'as JSON it decodes to the same, and the file is named after the design', is_string( $json ) && json_decode( $json, true ) === $v && str_starts_with( Style_Variation::file_name( $home ), 'dxai-style-variation-fixture' ) && str_ends_with( Style_Variation::file_name( $home ), '.json' ), Style_Variation::file_name( $home ) );
$expect( 'a page that is no design\'s has none', is_wp_error( Style_Variation::build( $plain ) ) && 'dxai_ui_no_document' === Style_Variation::build( $plain )->get_error_code() );
$expect( 'the layout reader knows Tailwind\'s widths', ( static function (): bool {
	$r = new ReflectionMethod( Style_Variation::class, 'px' );
	$r->setAccessible( true );

	return 1280 === $r->invoke( null, '80rem' ) && 1240 === $r->invoke( null, '1240px' ) && 0 === $r->invoke( null, 'auto' );
} )() );

echo "\nApplied: the site's look is the design's, its own pages stay theirs\n";
$fetch = static function ( int $id ): string {
	$res = wp_remote_get( (string) get_permalink( $id ), array( 'timeout' => 60, 'sslverify' => false, 'redirection' => 0 ) );

	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
};
// The two blocks a brand change rewrites by design: the theme's global styles (presets and styles) and the plugin's own tokens block,
// which for the brand design refers to the presets instead of writing the hex (Token_Styles::brand_css()) — the same colours either way.
$without_global = static fn( string $html ): string => (string) preg_replace( '#<style id=[\'"](?:global-styles|dxai-ui-tokens)-inline-css[\'"][^>]*>.*?</style>#s', '', $html );
$global_of      = static fn( string $html ): string => preg_match( '#<style id=[\'"]global-styles-inline-css[\'"][^>]*>(.*?)</style>#s', $html, $m ) === 1 ? $m[1] : '';
$tokens_of      = static fn( string $html ): string => preg_match( '#<style id=[\'"]dxai-ui-tokens-inline-css[\'"][^>]*>(.*?)</style>#s', $html, $m ) === 1 ? $m[1] : '';
$home_before    = $fetch( $home );
$plain_before   = $fetch( $plain );
$expect( 'before: nothing of the design in the theme\'s global styles on a plain page', '' !== $plain_before && ! str_contains( $global_of( $plain_before ), '--wp--preset--font-family--dxai-heading' ) );
$applied = Style_Variation::apply( $home );
$expect( 'apply() keeps the choice and makes the design the brand design', ! is_wp_error( $applied ) && Style_Variation::applied() === $home && (int) ( get_option( Design_Theme_Json::OPTION )['page_id'] ?? 0 ) === $home && true === $applied['applied'], is_wp_error( $applied ) ? $applied->get_error_message() : wp_json_encode( $applied ) );
Design_Theme_Json::flush();
$settings = wp_get_global_settings( array( 'color', 'palette' ) );
$theme_slugs = array_column( is_array( $settings['theme'] ?? null ) ? $settings['theme'] : array(), 'slug' );
$expect( 'the theme layer of theme.json now has the design\'s presets beside the theme\'s own', in_array( 'brand', $theme_slugs, true ) && in_array( 'accent', $theme_slugs, true ) && count( $theme_slugs ) > 2, wp_json_encode( $theme_slugs ) );
// The presets the plugin registered (the design's slug with the design's value) are not the theme's colours: the palette a design is
// fitted to (Theme_Palette, Theme_Binding) leaves them out, on a classic theme — where they reach theme.json since 2б — as on a block one.
Theme_Palette::reset();
$fitted     = array_keys( Theme_Palette::current()['entries'] );
$theme_rows = array_change_key_case( array_map( 'strtolower', array_column( is_array( $settings['theme'] ?? null ) ? $settings['theme'] : array(), 'color', 'slug' ) ) );
$registered = array();
foreach ( (array) ( get_post_meta( $home, Token_Styles::META, true )['colors'] ?? array() ) as $row ) {
	if ( is_array( $row ) && ! empty( $row['preset'] ) && isset( $row['slug'], $row['value'] ) && ( $theme_rows[ (string) $row['slug'] ] ?? '' ) === strtolower( (string) $row['value'] ) ) {
		$registered[] = (string) $row['slug'];
	}
}
$expect( 'the palette the plugin fits designs to leaves the brand design\'s own presets out, on this theme too (a design is never fitted to itself)', $registered !== array() && array() === array_intersect( $registered, $fitted ) && ( $fitted !== array() || count( $theme_slugs ) === count( $registered ) ), wp_json_encode( array( 'registered' => $registered, 'fitted' => $fitted ) ) );
$families = array_column( (array) ( wp_get_global_settings( array( 'typography', 'fontFamilies' ) )['theme'] ?? array() ), 'slug' );
$expect( '…and its fonts', in_array( 'dxai-heading', $families, true ) && in_array( 'dxai-body', $families, true ), wp_json_encode( $families ) );
$sheet = wp_get_global_stylesheet();
$expect( 'the global stylesheet defines them', str_contains( $sheet, '--wp--preset--color--brand:' ) && str_contains( $sheet, '--wp--preset--font-family--dxai-heading:' ) );
$home_after  = $fetch( $home );
$plain_after = $fetch( $plain );
$expect( 'a plain page of the site is drawn in the design\'s look: the global styles carry the button in the brand and the heading font', str_contains( $global_of( $plain_after ), '--wp--preset--color--brand' ) && str_contains( $global_of( $plain_after ), 'var(--wp--preset--font-family--dxai-heading)' ), substr( $global_of( $plain_after ), 0, 200 ) );
$expect( 'the design\'s own page is as it was, byte for byte but for the two blocks a brand change rewrites by design', $home_before !== '' && $without_global( $home_before ) === $without_global( $home_after ), (string) strlen( $without_global( $home_before ) ) . ' vs ' . strlen( $without_global( $home_after ) ) );
// The slug exactly: the site's brand before the suite may have a `brand-green` of its own (a prefix match once failed this check for that).
$expect( '…its tokens block now defines the design\'s presets and their classes (the same colours, changeable in one place)', str_contains( $tokens_of( $home_after ), '--wp--preset--color--brand:' ) && str_contains( $tokens_of( $home_after ), 'var(--wp--preset--color--brand)' ) && ! str_contains( $tokens_of( $home_before ), '--wp--preset--color--brand:' ), substr( $tokens_of( $home_after ), 0, 200 ) );
// (The tokens block may point the design's own font token at the font preset — the same font; that is the brand mechanism, not a style.)
$rest_of_home = $without_global( $home_after );
$expect( '…and none of the variation\'s styles is on it: its own rules draw it', ! str_contains( $rest_of_home, 'var(--wp--preset--font-family--dxai-heading)' ) && ! preg_match( '#wp-element-button[^}]*var\(--wp--preset--color--brand\)#', $rest_of_home ) );
Style_Variation::assume_page( $home );
$probe = new WP_Theme_JSON_Data( array( 'version' => 3, 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'theme-own', 'name' => 'Own', 'color' => '#123456' ) ) ) ) ), 'theme' );
$on_design = ( new Style_Variation() )->filter( $probe )->get_data();
Style_Variation::assume_page( 0 );
$elsewhere = ( new Style_Variation() )->filter( $probe )->get_data();
Style_Variation::assume_page( null );
$slugs_of = static function ( array $data ): array {
	$pal = $data['settings']['color']['palette'] ?? array();
	$pal = is_array( $pal ) && isset( $pal['theme'] ) && is_array( $pal['theme'] ) ? $pal['theme'] : $pal; // read back, presets are keyed by origin

	return array_map( 'strval', array_column( is_array( $pal ) ? $pal : array(), 'slug' ) );
};
$expect( 'the filter carries the theme\'s own palette forward and adds the design\'s (a merge replaces a palette whole)', in_array( 'theme-own', $slugs_of( $on_design ), true ) && in_array( 'brand', $slugs_of( $on_design ), true ) && 1 === count( array_keys( $slugs_of( $on_design ), 'brand', true ) ), wp_json_encode( $slugs_of( $on_design ) ) );
$expect( 'on a design\'s page the styles are left out, elsewhere they are in', ! isset( $on_design['styles']['elements']['button'] ) && isset( $elsewhere['styles']['elements']['button'] ), wp_json_encode( array( array_keys( $on_design['styles'] ?? array() ), array_keys( $elsewhere['styles'] ?? array() ) ) ) );
$status = Style_Variation::status( $home );
$expect( 'the status says it is applied and whether the theme takes a file', true === $status['applied'] && is_bool( $status['writable'] ) && false === $status['written'] );

echo "\nWritten, read, taken off\n";
$written = Style_Variation::write( $home );
if ( Capabilities::styles_writable() ) {
	$expect( 'the theme is the plugin\'s own: the file is written into its styles folder', ! is_wp_error( $written ) && is_file( $written['path'] ) );
	if ( ! is_wp_error( $written ) ) {
		wp_delete_file( $written['path'] );
	}
} else {
	$expect( 'the theme is a team\'s: nothing is written into it, and the answer says so', is_wp_error( $written ) && 'dxai_ui_variation_not_ours' === $written->get_error_code(), is_wp_error( $written ) ? '' : wp_json_encode( $written ) );
}
$tmp_dir = wp_normalize_path( get_temp_dir() ) . 'dxai-svar-' . $run;
$to      = Style_Variation::write_to( $home, $tmp_dir );
$expect( 'the writer writes the JSON as the file, where it is told to', ! is_wp_error( $to ) && is_file( $to['path'] ) && file_get_contents( $to['path'] ) === $json && basename( $to['path'] ) === Style_Variation::file_name( $home ), is_wp_error( $to ) ? $to->get_error_message() : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$ns   = DXAI_UI_REST_NAMESPACE;
$rest = static function ( string $method, int $id, array $params = array() ) use ( $ns ): array {
	$request = new WP_REST_Request( $method, '/' . $ns . '/design/' . $id . '/variation' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	$d        = $response->get_data();

	return array( 'status' => (int) $response->get_status(), 'data' => is_array( $d ) ? $d : array() );
};
$admin = (int) get_current_user_id();
$r     = $rest( 'GET', $home );
$expect( 'GET /design/{id}/variation gives the JSON, the status and what the theme offers', 200 === $r['status'] && ( $r['data']['json'] ?? '' ) === $json && true === ( $r['data']['applied'] ?? false ) && isset( $r['data']['capabilities']['theme'] ), wp_json_encode( array( $r['status'], array_keys( $r['data'] ) ) ) );
$r = $rest( 'POST', $home, array( 'action' => 'clear' ) );
$expect( 'POST clear takes it off', 200 === $r['status'] && false === ( $r['data']['applied'] ?? true ) && 0 === Style_Variation::applied() );
$r = $rest( 'POST', $home, array( 'action' => 'apply' ) );
$expect( 'POST apply puts it on again', 200 === $r['status'] && true === ( $r['data']['applied'] ?? false ) && Style_Variation::applied() === $home );
$r = $rest( 'POST', $home, array( 'action' => 'write' ) );
$expect( 'POST write answers as write() does for this theme', Capabilities::styles_writable() ? 200 === $r['status'] : 409 === $r['status'], (string) $r['status'] );
if ( Capabilities::styles_writable() && 200 === $r['status'] ) {
	wp_delete_file( Capabilities::styles_dir() . '/' . Style_Variation::file_name( $home ) );
}
// The writes publish a design's look site-wide, so they take what an import takes (unfiltered_html on top of manage_options); the reads take
// manage_options. Denied the way a multisite site admin or DISALLOW_UNFILTERED_HTML denies it (a super admin passes every cap but do_not_allow).
$no_html = static fn( array $caps, string $cap ): array => $cap === 'unfiltered_html' ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $no_html, 10, 2 );
$r_write = $rest( 'POST', $home, array( 'action' => 'clear' ) );
$r_read  = $rest( 'GET', $home );
remove_filter( 'map_meta_cap', $no_html, 10 );
$expect( 'an administrator without unfiltered_html may read the variation but not apply, clear or write it', in_array( $r_write['status'], array( 401, 403 ), true ) && Style_Variation::applied() === $home && 200 === $r_read['status'], wp_json_encode( array( $r_write['status'], $r_read['status'] ) ) );
$r = $rest( 'GET', $plain );
$expect( 'a page that is no design\'s is 404', 404 === $r['status'] );
wp_set_current_user( 0 );
$r = $rest( 'GET', $home );
wp_set_current_user( $admin );
$expect( 'a visitor is refused', in_array( $r['status'], array( 401, 403 ), true ) );
Style_Variation::clear();
Design_Theme_Json::flush();
$expect( 'clear(): the choice is gone; the brand design stays what it was made', 0 === Style_Variation::applied() && (int) ( get_option( Design_Theme_Json::OPTION )['page_id'] ?? 0 ) === $home );
$families = array_column( (array) ( wp_get_global_settings( array( 'typography', 'fontFamilies' ) )['theme'] ?? array() ), 'slug' );
$expect( '…and the design\'s fonts are out of theme.json again', ! in_array( 'dxai-heading', $families, true ) );
wp_delete_post( $home, true );
$expect( 'a design that is gone takes its variation with it', ( static function () use ( $home ): bool {
	update_option( Style_Variation::OPTION, array( 'design' => $home, 'at' => 'x' ), false );
	Style_Variation::released( $home );

	return 0 === Style_Variation::applied();
} )() );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

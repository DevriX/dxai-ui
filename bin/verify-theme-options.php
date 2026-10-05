<?php
/**
 * What the site's owner chose in the theme's Theme Global Settings (ACF options page) is used where a design can use it, and when it is
 * not there — no ACF, another theme, an empty field — everything works as it always did.
 *
 *   bash bin/wp-php.sh bin/verify-theme-options.php        (or: wp eval-file bin/verify-theme-options.php --user=1)
 *
 * Makes two attachments and removes them; changes the Site Logo and one ACF option for a moment and puts both back. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Header_Menus;
use DXAI_UI\Theme\Theme_Buttons;
use DXAI_UI\Theme\Theme_Options;

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

// Two pictures to point at.
$dir = wp_upload_dir();
wp_mkdir_p( $dir['basedir'] . '/dxai-options-test' );
$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
$made = array();
$mk   = static function ( string $name, string $mime ) use ( $dir, $png, &$made ): int {
	$path = $dir['basedir'] . '/dxai-options-test/' . $name;
	file_put_contents( $path, $mime === 'image/png' ? $png : 'text' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
	$id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( (int) $id, array( 'file' => 'dxai-options-test/' . $name, 'width' => 1, 'height' => 1 ) );
	$made[] = (int) $id;

	return (int) $id;
};
$a    = $mk( 'a.png', 'image/png' );
$b    = $mk( 'b.png', 'image/png' );
$txt  = $mk( 'c.txt', 'text/plain' );
$was  = array( 'mod' => get_theme_mod( 'custom_logo' ), 'opt' => get_option( 'site_logo' ) );
$fin  = static function () use ( &$made, $dir, $was ): void {
	if ( $was['mod'] ) {
		set_theme_mod( 'custom_logo', $was['mod'] );
	} else {
		remove_theme_mod( 'custom_logo' );
	}
	if ( $was['opt'] ) {
		update_option( 'site_logo', $was['opt'] );
	} else {
		delete_option( 'site_logo' );
	}
	foreach ( $made as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	@rmdir( $dir['basedir'] . '/dxai-options-test' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
};
$give = static function ( array $values ): void {
	remove_all_filters( 'dxai_ui_theme_option' );
	add_filter(
		'dxai_ui_theme_option',
		static function ( $value, $path ) use ( $values ) {
			$key = implode( '.', (array) $path );

			return array_key_exists( $key, $values ) ? $values[ $key ] : $value;
		},
		10,
		2
	);
};
$clear = static fn() => remove_all_filters( 'dxai_ui_theme_option' );

echo "Without the options\n";
$clear();
$expect( 'a field nothing set is null, an image field 0, and no radius is written', Theme_Options::value( 'no_such_field' ) === null && Theme_Options::value( 'logos', 'primary_logo' ) === null && Theme_Options::image_id( 'logos', 'primary_logo' ) === 0 && Theme_Options::radius_css() === '' );
$expect( 'the options are read through ACF: without it nothing is read', Theme_Options::available() === function_exists( 'get_field' ) );
remove_theme_mod( 'custom_logo' );
$expect( 'the header\'s logo is the design\'s: no Site Logo, none to draw', Header_Menus::logo() === array() );
set_theme_mod( 'custom_logo', $b );
$expect( 'with a Site Logo it is the Site Logo, as it always was', ( Header_Menus::logo()['id'] ?? 0 ) === $b );

echo "\nThe fields\n";
$give( array( 'logos.primary_logo' => array( 'ID' => $a, 'url' => 'x' ) ) );
$expect( 'an image field that returns the array, the id, or the address is the same image', Theme_Options::image_id( 'logos', 'primary_logo' ) === $a );
$give( array( 'logos.primary_logo' => (string) $a ) );
$expect( '…an id as a string', Theme_Options::image_id( 'logos', 'primary_logo' ) === $a );
$give( array( 'logos.primary_logo' => (string) wp_get_attachment_url( $a ) ) );
$expect( '…an address', Theme_Options::image_id( 'logos', 'primary_logo' ) === $a );
$give( array( 'logos.primary_logo' => array( 'url' => (string) wp_get_attachment_url( $a ) ) ) );
$expect( '…an array that has only the address', Theme_Options::image_id( 'logos', 'primary_logo' ) === $a );
$give( array( 'logos.primary_logo' => $txt ) );
$expect( 'a file that is not an image is not a logo', Theme_Options::image_id( 'logos', 'primary_logo' ) === 0 );
$give( array( 'logos.primary_logo' => 999999999 ) );
$expect( 'and an id that is nothing is not either', Theme_Options::image_id( 'logos', 'primary_logo' ) === 0 );
$give( array( 'logos.primary_logo' => '', 'border_radius_style' => false ) );
$expect( 'an empty field is null', Theme_Options::value( 'logos', 'primary_logo' ) === null && Theme_Options::value( 'border_radius_style' ) === null );

echo "\nThe logo of the header\n";
set_theme_mod( 'custom_logo', $b );
$give( array( 'logos.primary_logo' => array( 'ID' => $a ) ) );
$expect( 'is the primary logo of Theme Global Settings when the site has one, before the Site Logo (the theme draws it in that order)', ( Header_Menus::logo()['id'] ?? 0 ) === $a );
$clear();
$expect( 'and the Site Logo again when it is taken away', ( Header_Menus::logo()['id'] ?? 0 ) === $b );
remove_theme_mod( 'custom_logo' );
delete_option( 'site_logo' );
$spec = array( 'logos' => array( array( 'src' => (string) wp_get_attachment_url( $b ) ) ) );
$inst = new ReflectionMethod( Header_Menus::class, 'install_logo' );
$inst->setAccessible( true );
$give( array( 'logos.primary_logo' => array( 'ID' => $a ) ) );
$r = $inst->invoke( null, $spec, array( 'set_site_logo' => true ) );
$expect( 'an import does not give the site the design\'s logo when it has the primary logo of Theme Global Settings', $r['set'] === false && ! get_theme_mod( 'custom_logo' ) );
$clear();
$r = $inst->invoke( null, $spec, array( 'set_site_logo' => true ) );
$expect( 'and does when it has no logo at all, as it always did', $r['set'] === true && (int) get_theme_mod( 'custom_logo' ) === $b );

echo "\nThe radius of buttons and cards\n";
if ( ! function_exists( 'amr_build_button_theme_variables_css' ) ) {
	echo "  (the active theme has no such function: its radius is not written, which is the default)\n";
	$give( array( 'border_radius_style' => 'extra' ) );
	$expect( 'a theme without them gets nothing, whatever the options say', Theme_Options::radius_css() === '' );
	$clear();
} else {
	$expect( 'with no setting nothing is written: the theme\'s rules carry their own default', Theme_Options::radius_css() === '' );
	// The theme's own function reads the ACF option itself, so the lengths are checked with the real option (put back at the end).
	$acf_radius = function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) && acf_get_field( 'border_radius_style' );
	$was_radius = $acf_radius ? get_field( 'border_radius_style', 'option' ) : null;
	$restore    = static function () use ( $acf_radius, $was_radius ): void {
		if ( ! $acf_radius ) {
			return;
		}
		if ( $was_radius === null || $was_radius === '' || $was_radius === false ) {
			delete_field( 'border_radius_style', 'option' );
		} else {
			update_field( 'border_radius_style', $was_radius, 'option' );
		}
	};
	if ( $acf_radius ) {
		foreach ( array( 'none' => '0px', 'extra' => '35px', 'rounded' => '0.5rem' ) as $style => $length ) {
			update_field( 'border_radius_style', $style, 'option' );
			$css = Theme_Options::radius_css();
			$expect( 'the setting "' . $style . '" is the theme\'s own length (' . $length . ') for buttons', str_contains( $css, '--amr-button-border-radius:' . $length ) && str_starts_with( $css, ':root{' ), $css );
		}
		$restore();
	} else {
		echo "  (ACF has no such field here: the lengths are not exercised)\n";
	}
	$reset = static function (): void {
		wp_dequeue_style( 'dxai-ui-theme-buttons' );
		wp_deregister_style( 'dxai-ui-theme-buttons' );
	};
	if ( Theme_Buttons::url() === '' ) {
		echo "  (the theme has no button styles to print: the radius goes with them)\n";
	} else {
		$clear();
		$reset();
		Theme_Buttons::enqueue();
		$after = implode( '', (array) wp_styles()->get_data( 'dxai-ui-theme-buttons', 'after' ) );
		$expect( 'the sheet of the theme\'s buttons has no radius of its own to print when the options say nothing', wp_style_is( 'dxai-ui-theme-buttons', 'enqueued' ) && ! str_contains( $after, '--amr-button-border-radius' ) );
		if ( $acf_radius ) {
			update_field( 'border_radius_style', 'none', 'option' );
			$reset();
			Theme_Buttons::enqueue();
			$after = implode( '', (array) wp_styles()->get_data( 'dxai-ui-theme-buttons', 'after' ) );
			$expect( 'and with the setting the page prints it after the sheet (the theme\'s stylesheet, where it prints it, is not on a converted page)', str_contains( $after, '--amr-button-border-radius:0px' ), $after );
			$restore();
		}
		$clear();
		$reset();
	}
}

echo "\nThe options of ACF itself\n";
if ( function_exists( 'update_field' ) && function_exists( 'acf_get_field' ) && acf_get_field( 'border_radius_style' ) ) {
	$before = get_field( 'border_radius_style', 'option' );
	update_field( 'border_radius_style', 'extra', 'option' );
	$expect( 'a value saved on the options page is read', Theme_Options::value( 'border_radius_style' ) === 'extra' );
	if ( function_exists( 'amr_build_button_theme_variables_css' ) ) {
		$expect( 'and written as the theme writes it', str_contains( Theme_Options::radius_css(), '35px' ) );
	}
	if ( $before === null || $before === '' || $before === false ) {
		delete_field( 'border_radius_style', 'option' );
	} else {
		update_field( 'border_radius_style', $before, 'option' );
	}
	$expect( 'and the site is as it was', Theme_Options::value( 'border_radius_style' ) === ( $before === '' || $before === false ? null : $before ) );
} else {
	echo "  (ACF has no such field here: the real options are not exercised)\n";
}

$clear();
$fin();

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

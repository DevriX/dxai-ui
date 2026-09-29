<?php
/**
 * Print a logged-in auth cookie for the editor round-trip harness.
 *
 * The harness drives the real block editor, which needs an authenticated
 * session. The cookie is minted server-side from the user id so no password is
 * ever typed into a form.
 *
 * Run: wp eval-file bin/editor-roundtrip-cookie.php [user_login]
 *
 * @package DXAI_UI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$login = isset( $args[0] ) ? (string) $args[0] : 'admin';
$user  = get_user_by( 'login', $login );
if ( ! $user instanceof WP_User ) {
	echo "error=no_such_user\n";
	return;
}

$expiration = time() + ( 2 * HOUR_IN_SECONDS );

// The cookie carries a session token that wp_validate_auth_cookie() looks up,
// so the session has to exist before the cookie is signed — without it every
// request bounces to wp-login.php with reauth=1.
$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );

// wp-admin validates the auth cookie for the current scheme, while the rest of
// the request needs logged_in, so both travel — signed against the same token.
$auth_scheme = is_ssl() ? 'secure_auth' : 'auth';
$auth_name   = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

echo 'auth_cookie_name=' . $auth_name . PHP_EOL;
echo 'auth_cookie_value=' . wp_generate_auth_cookie( $user->ID, $expiration, $auth_scheme, $token ) . PHP_EOL;
echo 'cookie_name=' . LOGGED_IN_COOKIE . PHP_EOL;
echo 'cookie_value=' . wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $token ) . PHP_EOL;
echo 'cookie_path=' . ( defined( 'COOKIEPATH' ) ? COOKIEPATH : '/' ) . PHP_EOL;
echo 'cookie_domain=' . ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : wp_parse_url( home_url(), PHP_URL_HOST ) ) . PHP_EOL;
echo 'admin_url=' . admin_url( 'post.php' ) . PHP_EOL;

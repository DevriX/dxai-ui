<?php
/**
 * Smoke: authored classes are not missing utilities; space-x compiles;
 * JSX toggles, Lucide icons, and next/image survive compilation.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

define( 'DXAI_UI_DIR', dirname( __DIR__ ) . '/' );
require DXAI_UI_DIR . 'src/Autoloader.php';
DXAI_UI\Autoloader::register();

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return (string) $text;
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ) ?? '';
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

$fail = static function ( string $msg ): never {
	fwrite( STDERR, $msg . "\n" );
	exit( 1 );
};

if ( DXAI_UI\Compiler\Tailwind\Engine::looks_like_utility( 'mobile-section-nav' ) ) {
	$fail( 'mobile-section-nav must not look like a Tailwind utility' );
}
if ( ! DXAI_UI\Compiler\Tailwind\Engine::looks_like_utility( 'space-x-4' ) ) {
	$fail( 'space-x-4 must look like a utility' );
}
if ( ! DXAI_UI\Compiler\Tailwind\Engine::looks_like_utility( 'md:hidden' ) ) {
	$fail( 'md:hidden must look like a utility' );
}

$css = <<<'CSS'
.mobile-section-nav { display: flex; }
@theme { --color-background: #fff; }
CSS;
$markup = '<nav class="mobile-section-nav space-x-4 hidden md:flex divide-x divide-gray-200">';
$unresolved = ( new DXAI_UI\Compiler\Tailwind_Purger() )->unresolved( $markup, $css );
if ( in_array( 'mobile-section-nav', $unresolved, true ) ) {
	$fail( 'authored class leaked into unresolved utilities: ' . implode( ',', $unresolved ) );
}

$engine = new DXAI_UI\Compiler\Tailwind\Engine( DXAI_UI\Compiler\Tailwind\Theme::from_css( $css ) );
$sheet  = $engine->compile( '<div class="space-x-4 divide-x">', '.dxai-ui' );
if ( ! str_contains( $sheet, 'margin-inline' ) && ! str_contains( $sheet, '--tw-space-x-reverse' ) ) {
	$fail( "space-x-4 did not compile:\n" . $sheet );
}
// `:not(:last-child)`, matching Tailwind 4.2. The older
// `> :not([hidden]) ~ :not([hidden])` put the margin after the last child
// instead of between the children — see Engine::child_combinator().
if ( ! str_contains( $sheet, '> :not(:last-child)' ) ) {
	$fail( "space-x child combinator missing:\n" . $sheet );
}
$a11y = $engine->compile( '<span class="sr-only md:not-sr-only">Hidden label</span>', '.dxai-ui' );
// `clip-path: inset(50%)`, not the deprecated `clip: rect(...)` this once
// expected — upstream Tailwind changed it, and the parity harness caught that
// we had not.
if ( ! str_contains( $a11y, 'clip-path:inset(50%)' ) || ! str_contains( $a11y, 'white-space:nowrap' ) ) {
	$fail( "sr-only did not compile:\n" . $a11y );
}
if ( ! str_contains( $a11y, 'width:auto' ) || ! str_contains( $a11y, 'overflow:visible' ) ) {
	$fail( "not-sr-only did not compile:\n" . $a11y );
}

$source = <<<'TSX'
import { Menu, X } from "lucide-react";
import Image from "next/image";
import hero from "@/assets/hero.jpg";

export default function Page() {
  const [open, setOpen] = useState(false);
  return (
    <div className="min-h-screen bg-background">
      <button className="md:hidden" onClick={() => setOpen(!open)} aria-label="Menu">
        {open ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
      </button>
      {open && (
        <nav className="mobile-section-nav">
          <a href="/about">About</a>
        </nav>
      )}
      <Image src={hero} alt="Hero" className="w-full" />
    </div>
  );
}
TSX;

$sections = ( new DXAI_UI\Compiler\Jsx_Compiler() )->compile_file(
	$source,
	array(
		'images' => array(
			array(
				'url'      => 'https://example.com/hero.jpg',
				'filename' => 'hero.jpg',
				'original' => '@/assets/hero.jpg',
			),
		),
		'rewrites' => array(
			'@/assets/hero.jpg' => 'https://example.com/hero.jpg',
		),
	)
);
$html = implode( "\n", array_column( $sections, 'html' ) );
if ( ! str_contains( $html, 'data-lucide="menu"' ) ) {
	$fail( "lucide Menu missing:\n" . $html );
}
if ( ! str_contains( $html, 'dxai-toggle-open' ) ) {
	$fail( "toggle class missing:\n" . $html );
}
if ( ! str_contains( $html, 'dxai-on-open' ) ) {
	$fail( "gated nav missing:\n" . $html );
}
if ( ! str_contains( $html, 'mobile-section-nav' ) ) {
	$fail( "mobile-section-nav dropped:\n" . $html );
}
if ( ! str_contains( $html, 'https://example.com/hero.jpg' ) ) {
	$fail( "image src missing:\n" . $html );
}
if ( DXAI_UI\Compiler\Tsx_Section_Splitter::icon_id( 'Bars3Icon', 'heroicons' ) !== 'menu' ) {
	$fail( 'Bars3Icon should map to lucide menu' );
}

echo "OK\n";

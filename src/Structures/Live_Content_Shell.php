<?php
/**
 * Wrap crawled live HTML in a Home-styled content shell + prose CSS.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

use DXAI_UI\Compiler\Design_Tokens;

final class Live_Content_Shell {

	/**
	 * Clean noisy live markup (plugin forms, lazy attrs) before block convert.
	 */
	public static function prepare_html( string $html ): string {
		$html = trim( $html );
		if ( $html === '' ) {
			return '';
		}

		// Drop Gravity Forms / contact-form markup — keep a short CTA instead.
		if ( preg_match( '/gform_wrapper|wpcf7-form|wpforms-form/i', $html ) ) {
			$phone = '';
			if ( preg_match( '/(?:\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}|\+?\d[\d\s().-]{8,}\d)/', wp_strip_all_tags( $html ), $m ) ) {
				$phone = trim( $m[0] );
			}
			$intro = '';
			if ( preg_match( '/<(?:h1|h2)[^>]*>(.*?)<\/(?:h1|h2)>/is', $html, $hm ) ) {
				$intro = '<h1>' . wp_strip_all_tags( $hm[1] ) . '</h1>';
			}
			$paras = '';
			if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $pm ) ) {
				$count = 0;
				foreach ( $pm[1] as $p ) {
					$text = trim( wp_strip_all_tags( $p ) );
					if ( $text === '' || strlen( $text ) < 20 ) {
						continue;
					}
					$paras .= '<p>' . esc_html( $text ) . '</p>';
					if ( ++$count >= 3 ) {
						break;
					}
				}
			}
			$cta = '<div class="dxai-live-cta">';
			if ( $phone !== '' ) {
				$tel = preg_replace( '/[^\d+]/', '', $phone ) ?? '';
				$cta .= '<p class="dxai-live-cta__phone"><a href="tel:' . esc_attr( $tel ) . '">' . esc_html( $phone ) . '</a></p>';
			}
			$cta .= '<p class="dxai-live-cta__note">' . esc_html__( 'Use the contact options above, or call us — we respond within 24 hours.', 'dxai-ui' ) . '</p>';
			$cta .= '</div>';
			$html = $intro . $paras . $cta;
		}

		$html = preg_replace( '/\s(?:nitro-lazy-src|data-src|data-lazy-src|data-original)=["\']([^"\']+)["\']/i', ' src="$1"', $html ) ?? $html;
		$html = preg_replace( '/\s(?:nitro-lazy-srcset|data-srcset|data-lazy-srcset)=["\']([^"\']+)["\']/i', ' srcset="$1"', $html ) ?? $html;
		$html = preg_replace( '/\s(?:data-bg|data-background|data-bg-image|nitro-lazy-bg)=["\']([^"\']+)["\']/i', ' style="background-image:url($1)"', $html ) ?? $html;
		$html = preg_replace( '/\sclass="[^"]*nitro-lazy[^"]*"/i', '', $html ) ?? $html;

		return trim( $html );
	}

	/**
	 * Derive palette from the imported template via Design_Tokens.
	 *
	 * @return array{brand:string, accent:string, ink:string, muted:string, surface:string, border:string, radius:string, button_fg:string, danger:string}
	 */
	public static function palette_from_css( string $css ): array {
		$tokens = Design_Tokens::resolve( $css );

		return array(
			'brand'     => $tokens['brand'],
			'accent'    => $tokens['accent'],
			'ink'       => $tokens['ink'],
			'muted'     => $tokens['muted'],
			'surface'   => $tokens['surface'],
			'border'    => $tokens['border'],
			'radius'    => $tokens['radius'],
			'button_fg' => $tokens['button_fg'],
			'danger'    => $tokens['danger'],
		);
	}

	/**
	 * Prose + utility bridge. Colors always come from Design_Tokens of the imported template.
	 */
	public static function prose_css( int $home_id, string $home_css = '', array $tokens = array() ): string {
		$scope = '.dxai-ui.dxai-ui--' . $home_id . ' .dxai-live-content';
		/*
		 * The Home's tokens as persist_site_css() already resolved them WITH the
		 * home page's content. Re-resolving from the stylesheet alone — which is
		 * what palette_from_css() does — gets H2O's accent wrong under the
		 * current resolver: it picks brand and accent by what the markup
		 * actually paints, and a CSS-only call has no markup. That wrong accent
		 * was being written into site-{home}.css for every crawled page's prose.
		 * The CSS-only path stays as the fallback for a caller with no tokens.
		 */
		$p = isset( $tokens['accent'], $tokens['brand'] ) ? $tokens : self::palette_from_css( $home_css );
		$prefix = Design_Tokens::to_css_vars(
			array(
				'brand'     => $p['brand'],
				'accent'    => $p['accent'],
				'ink'       => $p['ink'],
				'muted'     => $p['muted'],
				'surface'   => $p['surface'],
				'border'    => $p['border'],
				'radius'    => $p['radius'],
				'button_fg' => $p['button_fg'],
				'danger'    => $p['danger'],
				'font'      => 'inherit',
			),
			$home_id
		);

		return $prefix . <<<CSS
{$scope} {
  box-sizing: border-box;
  width: 100%;
  color: var(--dxai-ink);
  font-size: 1.0625rem;
  line-height: 1.65;
  background: #fff;
}
{$scope} *,
{$scope} *::before,
{$scope} *::after { box-sizing: border-box; }
{$scope} h1, {$scope} h2, {$scope} h3, {$scope} h4,
{$scope} .wp-block-heading {
  margin: 0 0 0.6em;
  line-height: 1.15;
  font-weight: 800;
  letter-spacing: -0.03em;
  color: var(--dxai-ink);
}
{$scope} h1, {$scope} .text-3xl, {$scope} .text-4xl, {$scope} .text-5xl {
  font-size: clamp(2.1rem, 4.5vw, 3.25rem);
}
{$scope} h2, {$scope} .text-2xl { font-size: clamp(1.55rem, 3vw, 2.25rem); }
{$scope} h3, {$scope} .text-xl { font-size: clamp(1.2rem, 2.4vw, 1.5rem); }
{$scope} p, {$scope} li { margin: 0 0 1em; color: var(--dxai-muted); }
{$scope} a { color: var(--dxai-brand); text-underline-offset: 0.15em; }
{$scope} img, {$scope} .wp-block-image img {
  display: block;
  max-width: 100%;
  height: auto;
  margin: 1.25rem 0;
  border-radius: var(--dxai-radius);
}
{$scope} .wp-block-group { margin: 0; }
{$scope} .mx-auto { margin-left: auto; margin-right: auto; }
{$scope} .max-w-6xl, {$scope} .max-w-5xl, {$scope} .max-w-4xl { max-width: 72rem; width: 100%; }
{$scope} .max-w-3xl { max-width: 48rem; }
{$scope} .px-4 { padding-left: 1rem; padding-right: 1rem; }
{$scope} .py-16 { padding-top: 4rem; padding-bottom: 4rem; }
{$scope} .md\\:py-24 { padding-top: 4rem; padding-bottom: 4rem; }
@media (min-width: 768px) {
  {$scope} .md\\:py-24 { padding-top: 6rem; padding-bottom: 6rem; }
}
{$scope} .py-12 { padding-top: 3rem; padding-bottom: 3rem; }
{$scope} .pt-8 { padding-top: 2rem; }
{$scope} .pb-8 { padding-bottom: 2rem; }
{$scope} .gap-4 { gap: 1rem; }
{$scope} .gap-6 { gap: 1.5rem; }
{$scope} .gap-8 { gap: 2rem; }
{$scope} .grid { display: grid; }
{$scope} .flex { display: flex; }
{$scope} .flex-col { flex-direction: column; }
{$scope} .items-center { align-items: center; }
{$scope} .justify-between { justify-content: space-between; }
{$scope} .text-center { text-align: center; }
{$scope} .font-bold, {$scope} .font-extrabold { font-weight: 800; }
{$scope} .tracking-tight { letter-spacing: -0.03em; }
{$scope} .text-slate-900, {$scope} .text-gray-900 { color: var(--dxai-ink); }
{$scope} .text-slate-600, {$scope} .text-slate-700, {$scope} .text-gray-600 { color: var(--dxai-muted); }
{$scope} .text-white { color: #fff; }
{$scope} .bg-white { background: #fff; }
{$scope} .bg-slate-50, {$scope} .bg-gray-50 { background: var(--dxai-surface); }
{$scope} .bg-sky-600, {$scope} .bg-blue-600, {$scope} .bg-indigo-600, {$scope} .bg-purple-600 { background: var(--dxai-brand); }
{$scope} .text-sky-600, {$scope} .text-blue-600, {$scope} .text-indigo-600, {$scope} .text-purple-600 { color: var(--dxai-brand); }
{$scope} .bg-orange-500, {$scope} .bg-amber-500, {$scope} .bg-yellow-500 { background: var(--dxai-accent); }
{$scope} .rounded-full { border-radius: 9999px; }
{$scope} .rounded-2xl, {$scope} .rounded-xl, {$scope} .rounded-lg { border-radius: var(--dxai-radius); }
{$scope} .shadow-sm { box-shadow: 0 1px 2px rgb(0 0 0 / 0.06); }
{$scope} .shadow-lg { box-shadow: 0 18px 40px rgb(15 23 42 / 0.12); }
{$scope} .inline-flex { display: inline-flex; align-items: center; justify-content: center; }
{$scope} a.inline-flex,
{$scope} .dxai-live-btn,
{$scope} .wp-block-button__link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.85rem 1.35rem;
  border-radius: var(--dxai-radius);
  background: var(--dxai-accent);
  color: var(--dxai-button-fg) !important;
  font-weight: 700;
  text-decoration: none !important;
  border: 0;
  box-shadow: 0 10px 24px color-mix(in srgb, var(--dxai-accent) 35%, transparent);
}
{$scope} .dxai-live-btn--brand {
  background: var(--dxai-brand);
  box-shadow: 0 10px 24px color-mix(in srgb, var(--dxai-brand) 35%, transparent);
}
{$scope} .dxai-live-card {
  padding: 1.5rem;
  border-radius: var(--dxai-radius);
  background: var(--dxai-surface);
  border: 1px solid var(--dxai-border);
}
{$scope} .dxai-live-hero {
  padding: clamp(3rem, 7vw, 5.5rem) 1.25rem;
  background:
    radial-gradient(1200px 400px at 10% -10%, color-mix(in srgb, var(--dxai-brand) 18%, transparent), transparent 60%),
    radial-gradient(900px 360px at 90% 0%, color-mix(in srgb, var(--dxai-accent) 16%, transparent), transparent 55%),
    #fff;
  border-bottom: 1px solid var(--dxai-border);
}
{$scope} .dxai-live-hero__inner {
  max-width: 72rem;
  margin: 0 auto;
}
{$scope} .dxai-live-cta {
  margin-top: 2rem;
  padding: 1.75rem 1.5rem;
  border-radius: var(--dxai-radius);
  background: linear-gradient(135deg, color-mix(in srgb, var(--dxai-brand) 8%, #fff), #fff);
  border: 1px solid color-mix(in srgb, var(--dxai-brand) 22%, transparent);
}
{$scope} .dxai-live-cta__phone {
  font-size: clamp(1.5rem, 3vw, 2rem);
  font-weight: 800;
  margin-bottom: 0.35rem;
}
{$scope} .dxai-live-cta__phone a { color: var(--dxai-brand); text-decoration: none; }
{$scope} .dxai-live-cta__note { margin: 0; }
{$scope} .sm\\:grid-cols-2, {$scope} .md\\:grid-cols-2 { grid-template-columns: 1fr; }
{$scope} .md\\:grid-cols-3 { grid-template-columns: 1fr; }
@media (min-width: 768px) {
  {$scope} .sm\\:grid-cols-2, {$scope} .md\\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  {$scope} .md\\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
CSS;
	}

	/**
	 * Build a shared site stylesheet for crawled pages: Home pattern sheet + live prose.
	 * Always prefers uploads/dxai-ui/pattern-{homeId}.css so Home's own meta stays untouched.
	 */
	public static function persist_site_css( int $home_id, string $home_css_url = '' ): string|\WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new \WP_Error( 'dxai_ui_css', (string) $uploads['error'] );
		}

		$basedir = trailingslashit( (string) $uploads['basedir'] ) . 'dxai-ui';
		$pattern = $basedir . '/pattern-' . $home_id . '.css';

		$home_css = '';
		if ( is_readable( $pattern ) ) {
			$home_css = (string) file_get_contents( $pattern );
		} elseif ( $home_css_url !== '' && ! str_contains( $home_css_url, '/site-' . $home_id . '.css' ) ) {
			// Either stored form — a relative location or an absolute URL from
			// the site's previous address. See Upload_Paths.
			$path = \DXAI_UI\Support\Upload_Paths::path( $home_css_url );
			if ( $path !== '' && is_readable( $path ) ) {
				$home_css = (string) file_get_contents( $path );
			}
		}

		if ( $home_css === '' ) {
			return new \WP_Error( 'dxai_ui_css', __( 'Home stylesheet not found for live pages.', 'dxai-ui' ) );
		}

		$tokens = Design_Tokens::resolve( $home_css, (string) get_post_field( 'post_content', $home_id ) );
		Design_Tokens::persist( $home_id, $tokens );

		$sheet = trim( $home_css ) . "\n\n/* DXAI live page content */\n" . self::prose_css( $home_id, $home_css, $tokens );
		if ( ! wp_mkdir_p( $basedir ) ) {
			return new \WP_Error( 'dxai_ui_css', __( 'Unable to create CSS directory.', 'dxai-ui' ) );
		}

		$file = $basedir . '/site-' . $home_id . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file, $sheet ) ) {
			return new \WP_Error( 'dxai_ui_css', __( 'Unable to write site CSS.', 'dxai-ui' ) );
		}

		// The current absolute URL, for the summary and bin/ callers; the page
		// meta it ends up in is written through Upload_Paths::for_storage().
		return \DXAI_UI\Support\Upload_Paths::url( 'dxai-ui/site-' . $home_id . '.css' );
	}
}

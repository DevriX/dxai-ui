<?php
/**
 * Distinct asset imports must resolve to distinct URLs.
 *
 * Jsx_Compiler carries a deliberate fallback for a design that names a logo it
 * never imported: pick_logo_url() takes the first harvested image whose name
 * contains `logo`, and resolve_src() substitutes it. The fallback is right; its
 * trigger was not. It fired on any `src` merely CONTAINING `logo`, so a logo
 * that had resolved correctly from a real import was overwritten with whichever
 * logo-ish asset the harvest happened to list first.
 *
 * ARA is the case that found it. AraLogo.tsx is
 *
 *     const src = color === "white" ? logoWhite : logoNavy;
 *
 * with DesktopNav taking the navy default and SiteFooter passing
 * color="white". Both identifiers bind correctly and the ternary picks
 * correctly; the override then discarded the result and rendered navy in the
 * footer. Because the two files have different aspect ratios the nav logo laid
 * out 74px against the design's 118px, and the flex row redistributed around
 * it — a structure swap plus 32 box differences per width, reported nowhere
 * near the real cause.
 *
 * Nothing else can see this:
 *   - the geometry check compares the page against an oracle built by this
 *     same compiler, so a compile-time loss is present on BOTH sides and the
 *     diff stays quiet — unless the two paths happen to disagree, which is
 *     exactly what made it visible, by luck;
 *   - unevaluated() reports nothing, because the expression evaluated fine and
 *     was overwritten afterwards;
 *   - and it is not even stable. The override only runs when resolve_src()'s
 *     two lookups miss, and "first image containing logo" moves as the harvest
 *     changes, so the same ZIP resolved correctly on one import and wrongly on
 *     the next.
 *
 * The matrix below is the bisect that isolated it, kept as a test. The naming
 * cases matter as much as the shapes: a pair named navy.png / white.png always
 * passed, and every failing pair shared the substring `logo`.
 *
 * usage: bin/wp-php.sh bin/asset-resolution.php
 * exit:  0 every case resolves, 1 an asset resolved to the wrong file
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$page = <<<'TSX'
import { Logo } from "@/components/Logo";

export default function Page() {
  return (
    <div>
      <Logo />
      <Logo color="white" height={36} />
    </div>
  );
}
TSX;

/* The component shapes. Each reads `color` and must pick the matching asset. */
$shapes = array(
	'const + ternary'        => <<<'TSX'
export function Logo({ color = "navy", height = 32 }) {
  const src = color === "white" ? assetB : assetA;
  return (
    <img src={src} data-color={color} style={{ height }} />
  );
}
TSX,
	'inline ternary'         => <<<'TSX'
export function Logo({ color = "navy", height = 32 }) {
  return (
    <img src={color === "white" ? assetB : assetA} data-color={color} style={{ height }} />
  );
}
TSX,
	'const + early return'   => <<<'TSX'
export function Logo({ variant = "full", color = "navy", height = 32 }) {
  const src = color === "white" ? assetB : assetA;
  const aspect = 6.4;

  if (variant === "mark") {
    return (
      <div role="img" style={{ height, backgroundImage: `url(${src})` }} />
    );
  }

  return (
    <img src={src} data-color={color} style={{ height, width: height * aspect }} />
  );
}
TSX,
);

/* [ label, A basename, B basename ]. */
$namings = array(
	array( 'no shared token', 'navy.png', 'white.png' ),
	array( 'shared `logo` token', 'ara-logo-navy.png', 'ara-logo-white.png' ),
	array( 'shared token, WP -8 suffixes', 'ara-logo-navy-8.png', 'ara-logo-white-8.png' ),
	array( 'shared token, no colour words', 'ara-logo-one.png', 'ara-logo-two.png' ),
	array( 'only B carries the token', 'plain-a.png', 'ara-logo-white.png' ),
	array( 'uppercase', 'ARA-LOGO-NAVY.png', 'ARA-LOGO-WHITE.png' ),
);

/* The two specifier forms a design can arrive in. */
$forms = array(
	'alias'    => '@/assets/%s',
	'absolute' => 'http://x.test/uploads/%s',
);

$fail  = 0;
$total = 0;

foreach ( $shapes as $shape_label => $body ) {
	foreach ( $forms as $form_label => $form ) {
		foreach ( $namings as [ $naming, $name_a, $name_b ] ) {
			$key_a = sprintf( $form, $name_a );
			$key_b = sprintf( $form, $name_b );
			$url_a = 'http://x.test/uploads/' . $name_a;
			$url_b = 'http://x.test/uploads/' . $name_b;

			$files = array(
				'src/components/Logo.tsx' =>
					"import assetA from \"{$key_a}\";\nimport assetB from \"{$key_b}\";\n\n" . $body,
			);
			$harvest = array( 'rewrites' => array( $key_a => $url_a, $key_b => $url_b ) );

			$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
			$sections = $compiler->compile_file( $page, $harvest, $files );
			$html     = implode( "\n", array_column( $sections, 'html' ) );

			preg_match_all( '/<img[^>]*>/i', $html, $m );
			foreach ( array( $url_a, $url_b ) as $i => $expect ) {
				++$total;
				$tag = $m[0][ $i ] ?? '';
				preg_match( '/src="([^"]*)"/i', $tag, $s );
				$got = $s[1] ?? '(no src)';
				if ( $got === $expect ) {
					continue;
				}
				++$fail;
				printf(
					"FAIL  %-18s %-9s %-30s instance %d: wanted %s, got %s\n",
					$shape_label,
					$form_label,
					$naming,
					$i,
					basename( $expect ),
					$got === '(no src)' ? $got : basename( $got )
				);
			}
		}
	}
}

/*
 * And the fallback itself still has to work, or this check would pass on a
 * compiler that simply deleted it. A design that names a logo it never
 * imported gets the harvested one.
 */
$fallback_page = <<<'TSX'
export default function Page() {
  return (
    <div>
      <img src="/logo.png" alt="brand" />
    </div>
  );
}
TSX;

$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
$sections = $compiler->compile_file(
	$fallback_page,
	array( 'rewrites' => array( '@/assets/site-logo.png' => 'http://x.test/uploads/site-logo.png' ) ),
	array()
);
$html = implode( "\n", array_column( $sections, 'html' ) );
++$total;
if ( strpos( $html, 'http://x.test/uploads/site-logo.png' ) === false ) {
	++$fail;
	preg_match( '/<img[^>]*>/i', $html, $mm );
	printf( "FAIL  unimported logo fallback no longer substitutes: %s\n", $mm[0] ?? '(no img)' );
}

printf( "\n%d of %d asset resolutions correct\n", $total - $fail, $total );
exit( $fail === 0 ? 0 : 1 );

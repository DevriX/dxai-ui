<?php
/**
 * The component surface: declaration forms, the shadcn/ui kit, and overlays.
 *
 * A SURFACE check, not a corpus one. All seven design ZIPs are hand-rolled
 * lowercase markup with inline useState, and none of them uses the shadcn/ui +
 * Radix kit — yet every Lovable export ships that kit, 44-46 files of it. So
 * nothing in the corpus exercised any of this, and every geometry run was
 * clean while a page built from Card/Button/Input came out as `<span>` soup
 * with no styling, no `<button>`, no `<input>` and no text.
 *
 * Three groups:
 *
 *   1. LADDER — the declaration and expression forms a component can use, each
 *      isolated so a failure names one capability instead of "the kit is
 *      broken". This is how seven separate defects were found in dependency
 *      order: the splitter could not see `forwardRef`/`memo`; extract_from()
 *      demanded a `{` body; a concise arrow body rendered NOTHING (which hid
 *      the next four behind it); cva() was unimplemented; extract_assignment()
 *      cut a multi-line call at the first newline; rest elements were never
 *      bound; and `children` arriving through a spread went nowhere.
 *
 *   2. KIT — the real components/ui files, asserted on what must survive.
 *
 *   3. OVERLAYS — a Radix portal's body must not paint. In the real app it is
 *      appended to the body and shown only while open; rendered inline it is
 *      simply visible, and these are `fixed z-50` centred panels, so a Dialog
 *      body covered the page. Measured before the fix: 3 of 4 overlays
 *      published their body visibly.
 *
 *      The guard test walks up from the secret text and requires the guard on
 *      one of its OWN ancestors. An earlier version searched the whole
 *      document for `hidden|display:none|aria-hidden` and reported every
 *      overlay safe — it was matching `aria-hidden="true"` on the lucide close
 *      icon inside the dialog. A loose instrument is worse than none.
 *
 * usage: bin/wp-php.sh bin/component-kit.php [project-src-dir]
 *        defaults to the newest .verify/react-oracle/<slug>/src
 * exit:  0 all groups pass, 1 a capability regressed, 2 bad usage
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

$repo = dirname( __DIR__ );
$root = (string) ( $argv[1] ?? '' );
/*
 * The BIGGEST kit among the extracted projects, not the last one glob
 * returns.
 *
 * The old rule was alphabetical by accident. Adding the legacy fixture —
 * whose components/ui is four files written for that fixture — made it the
 * last match, and groups 2 and 3 would have been asserted against a kit with
 * no input.tsx, no dialog and no popover: group 2 failing on classes that
 * fixture never had, and group 3 PASSING every overlay on the grounds that
 * nothing rendered. A real export ships 44-46 of these files and a stand-in
 * ships a handful, so size tells them apart.
 */
if ( $root === '' ) {
	$widest = 0;
	foreach ( (array) glob( $repo . '/.verify/react-oracle/*/src' ) as $candidate ) {
		$count = count( (array) glob( $candidate . '/components/ui/*.tsx' ) );
		if ( $count > $widest ) {
			$widest = $count;
			$root   = (string) $candidate;
		}
	}
	if ( $widest > 0 && $widest < 20 ) {
		// Not a real kit. Say so rather than assert against a stand-in.
		printf( "\nonly %d components/ui file(s) available — that is not a shadcn kit\n", $widest );
		$root = '';
	}
}

$fail = 0;

/* ------------------------------------------------------------- 1. ladder --- */

$cn = "export function cn(...inputs) {\n  return inputs.filter(Boolean).join(\" \");\n}\n";

$rungs = array(
	array( 'block-bodied arrow', "export const Box = ({ className }) => {\n  return (\n    <div className={className} data-r=\"1\" />\n  );\n};\n", 'data-r="1"' ),
	array( 'concise arrow body', "export const Box = ({ className }) => (\n  <div className={className} data-r=\"2\" />\n);\n", 'data-r="2"' ),
	array( 'concise arrow + rest', "export const Box = ({ className, ...props }) => (\n  <div className={className} data-r=\"3\" {...props} />\n);\n", 'data-r="3"' ),
	array( 'React.forwardRef', "import * as React from \"react\";\nexport const Box = React.forwardRef(({ className, ...props }, ref) => (\n  <div ref={ref} className={className} data-r=\"4\" {...props} />\n));\n", 'data-r="4"' ),
	array( 'forwardRef + generics', "import * as React from \"react\";\nexport const Box = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(\n  ({ className, ...props }, ref) => (\n    <div ref={ref} className={className} data-r=\"5\" {...props} />\n  ),\n);\n", 'data-r="5"' ),
	array( 'cn() base merge', "import { cn } from \"@/lib/utils\";\nexport const Box = ({ className }) => (\n  <div className={cn(\"rounded-xl border\", className)} data-r=\"6\" />\n);\n", 'rounded-xl' ),
	array( 'cva() variants', "import { cva } from \"class-variance-authority\";\nconst v = cva(\"base-cls\", {\n  variants: { tone: { solid: \"bg-solid\", ghost: \"bg-ghost\" } },\n  defaultVariants: { tone: \"solid\" },\n});\nexport const Box = ({ tone, className }) => (\n  <div className={v({ tone, className })} data-r=\"7\" />\n);\n", 'bg-solid' ),
	array( 'native tag kept', "export const Box = ({ children, ...props }) => (\n  <button type=\"button\" data-r=\"8\" {...props}>{children}</button>\n);\n", '<button' ),
	array( 'children via spread', "export const Box = ({ ...props }) => (\n  <div data-r=\"9\" {...props} />\n);\n", 'INNERTEXT' ),
	array( 'props at route level', "export const Box = ({ tone = \"D\" }) => (\n  <div data-r=\"10\" data-t={tone} />\n);\n", 'data-t="solid"' ),
);

print "--- 1. declaration and expression forms ---\n";
foreach ( $rungs as [ $label, $code, $needle ] ) {
	$page = "import { Box } from \"@/components/C\";\n\n"
		. "export default function Page() {\n  return (\n    <section>\n      <Box className=\"extra\" tone=\"solid\">INNERTEXT</Box>\n    </section>\n  );\n}\n";

	$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
	try {
		$html = implode( '', array_column(
			$compiler->compile_file( $page, array(), array( 'src/components/C.tsx' => $code, 'src/lib/utils.ts' => $cn ) ),
			'html'
		) );
	} catch ( Throwable $e ) {
		$html = 'threw: ' . $e->getMessage();
	}

	$ok = str_contains( $html, $needle );
	if ( ! $ok ) {
		++$fail;
	}
	printf( "  %-24s %s\n", $label, $ok ? 'ok' : 'FAIL  wanted ' . $needle . ' in ' . substr( trim( (string) preg_replace( '/\s+/', ' ', $html ) ), 0, 80 ) );
}

if ( $root === '' || ! is_dir( $root . '/components/ui' ) ) {
	print "\nno components/ui found — run bin/react-oracle.sh first to extract a project\n";
	print "groups 2 and 3 NOT RUN\n";
	exit( $fail === 0 ? 0 : 1 );
}

$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $f ) {
	if ( ! $f instanceof SplFileInfo || ! $f->isFile() || preg_match( '/\.(tsx|ts)$/', $f->getFilename() ) !== 1 ) {
		continue;
	}
	$files[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( dirname( $root ) ) + 1 ) ) ] = (string) file_get_contents( $f->getPathname() );
}

/* ---------------------------------------------------------------- 2. kit --- */

$files['src/components/Pricing.tsx'] = <<<'TSX'
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function Pricing() {
  return (
    <section className="p-8">
      <Card className="max-w-md">
        <CardHeader><CardTitle>PricingHeading</CardTitle></CardHeader>
        <CardContent>
          <Input placeholder="you@example.com" />
          <Button variant="default" size="lg">GetStarted</Button>
          <Button variant="outline">LearnMore</Button>
        </CardContent>
      </Card>
    </section>
  );
}
TSX;

$page = "import { Pricing } from \"@/components/Pricing\";\n\nexport default function Page() {\n  return (\n    <main>\n      <Pricing />\n    </main>\n  );\n}\n";

$compiler = new DXAI_UI\Compiler\Jsx_Compiler();
$kit      = implode( "\n", array_column( $compiler->compile_file( $page, array(), $files ), 'html' ) );

/* [ label, pattern, expected count ] — a count, because "at least one" hid a
 * missing second Button once. */
$kit_checks = array(
	array( 'Button renders <button>', '/<button\b/i', 2 ),
	array( 'Input renders <input>', '/<input\b/i', 1 ),
	array( 'Card keeps rounded-xl', '/rounded-xl/', 1 ),
	array( 'Card keeps bg-card', '/bg-card/', 1 ),
	array( 'cva default variant', '/bg-primary/', 1 ),
	array( 'cva size="lg"', '/\bh-10\b/', 1 ),
	array( 'cva variant="outline"', '/border-input/', 2 ),
	array( 'heading text kept', '/PricingHeading/', 1 ),
	array( 'button label kept', '/GetStarted/', 1 ),
	array( 'second label kept', '/LearnMore/', 1 ),
	array( 'no dotted tag emitted', '/<[a-zA-Z]+\.[a-zA-Z]/', 0 ),
	array( 'no marker leaked', '/__dxai_/', 0 ),
);

printf( "\n--- 2. the real shadcn kit (%s) ---\n", basename( dirname( $root ) ) );
foreach ( $kit_checks as [ $label, $pattern, $want ] ) {
	$got = preg_match_all( $pattern, $kit );
	$ok  = $want === 0 ? $got === 0 : $got >= $want;
	if ( ! $ok ) {
		++$fail;
	}
	printf( "  %-26s %s\n", $label, $ok ? 'ok' : sprintf( 'FAIL  wanted %s%d, got %d', $want === 0 ? '' : '>=', $want, $got ) );
}

/* ----------------------------------------------------------- 3. overlays --- */

$overlays = array(
	'Dialog'       => array( 'DIALOG_SECRET', 'Dialog, DialogTrigger, DialogContent, DialogTitle', 'dialog', '<Dialog><DialogTrigger>Open</DialogTrigger><DialogContent><DialogTitle>DIALOG_SECRET</DialogTitle></DialogContent></Dialog>' ),
	'Sheet'        => array( 'SHEET_SECRET', 'Sheet, SheetTrigger, SheetContent, SheetTitle', 'sheet', '<Sheet><SheetTrigger>Open</SheetTrigger><SheetContent><SheetTitle>SHEET_SECRET</SheetTitle></SheetContent></Sheet>' ),
	'Tooltip'      => array( 'TOOLTIP_SECRET', 'Tooltip, TooltipTrigger, TooltipContent', 'tooltip', '<Tooltip><TooltipTrigger>Hover</TooltipTrigger><TooltipContent>TOOLTIP_SECRET</TooltipContent></Tooltip>' ),
	'Popover'      => array( 'POPOVER_SECRET', 'Popover, PopoverTrigger, PopoverContent', 'popover', '<Popover><PopoverTrigger>Open</PopoverTrigger><PopoverContent>POPOVER_SECRET</PopoverContent></Popover>' ),
);

print "\n--- 3. portal bodies must not paint ---\n";
foreach ( $overlays as $label => [ $secret, $imports, $module, $markup ] ) {
	$set = $files;
	$set['src/components/Overlay.tsx'] = "import { {$imports} } from \"@/components/ui/{$module}\";\n\n"
		. "export function Overlay() {\n  return (\n    <section>{$markup}</section>\n  );\n}\n";

	$page = "import { Overlay } from \"@/components/Overlay\";\n\nexport default function Page() {\n  return (\n    <main>\n      <Overlay />\n    </main>\n  );\n}\n";

	$c = new DXAI_UI\Compiler\Jsx_Compiler();
	try {
		$html = implode( "\n", array_column( $c->compile_file( $page, array(), $set ), 'html' ) );
	} catch ( Throwable $e ) {
		$html = '';
	}

	if ( ! str_contains( $html, $secret ) ) {
		// Absent is acceptable: nothing is published.
		printf( "  %-14s ok    (body not rendered)\n", $label );
		continue;
	}

	$guarded = false;
	$doc     = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	$nodes = ( new DOMXPath( $doc ) )->query( '//*[contains(text(), "' . $secret . '")]' );
	if ( $nodes instanceof DOMNodeList && $nodes->length > 0 ) {
		for ( $node = $nodes->item( 0 ); $node instanceof DOMElement; $node = $node->parentNode ) {
			$style = str_replace( ' ', '', strtolower( $node->getAttribute( 'style' ) ) );
			if ( $node->hasAttribute( 'hidden' ) || str_contains( $style, 'display:none' )
				|| $node->getAttribute( 'aria-hidden' ) === 'true' || $node->hasAttribute( 'data-dxai-portal' ) ) {
				$guarded = true;
				break;
			}
		}
	}

	if ( ! $guarded ) {
		++$fail;
	}
	printf( "  %-14s %s\n", $label, $guarded ? 'ok    (hidden)' : 'FAIL  body published VISIBLE on the page' );
}

printf( "\n%s\n", $fail === 0 ? 'component surface: all groups pass' : $fail . ' failure(s)' );
exit( $fail === 0 ? 0 : 1 );

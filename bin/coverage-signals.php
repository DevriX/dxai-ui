<?php
/**
 * What Design_Coverage reads out of a design's own code.
 *
 * Three of the coverage report's rows can only be counted from the source a
 * design was written in — lucide icons, framer-motion elements and the
 * Radix/shadcn components that carry its interaction — plus the JSX handlers
 * behind all of it. Source_Compiler counts them while the archive is open and
 * carries the answer in its result, because by the time the admin saves a page
 * the archive is gone.
 *
 * That makes the counting worth pinning: a regex that quietly stops matching
 * turns a real row into "unknown", and unknown rows are the ones nobody chases.
 * Fixtures only — no WordPress, no database.
 *
 * usage: bin/wp-php.sh bin/coverage-signals.php
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Verification\Design_Coverage;

$fails = 0;
$ran   = 0;

/**
 * @param array<string, string> $sources
 * @param array<string, int>    $want
 */
$check = function ( array $sources, array $want, string $label ) use ( &$fails, &$ran ): void {
	++$ran;
	$got  = Design_Coverage::source_signals( $sources );
	$diff = array();
	foreach ( $want as $key => $value ) {
		if ( ( $got[ $key ] ?? null ) !== $value ) {
			$diff[] = $key . ' want=' . $value . ' got=' . var_export( $got[ $key ] ?? null, true );
		}
	}
	if ( $diff !== array() ) {
		++$fails;
		echo "FAIL {$label}: " . implode( ', ', $diff ) . PHP_EOL;
		return;
	}
	echo "ok   {$label}" . PHP_EOL;
};

/* Lucide: uses, not imports, and the local name after `as`. */
$check(
	array(
		'src/components/Hero.tsx' => <<<'TSX'
		import { ArrowRight, Check as CheckIcon } from "lucide-react";
		export const Hero = () => (
		  <div>
		    <ArrowRight className="h-4 w-4" />
		    <ArrowRight />
		    <CheckIcon />
		  </div>
		);
		TSX,
	),
	array( 'lucide' => 3 ),
	'lucide counts every use, aliases included'
);

// A name imported and never rendered is not an icon on the page.
$check(
	array( 'src/x.tsx' => 'import { Star } from "lucide-react";' . "\n" . 'export const X = () => <div />;' ),
	array( 'lucide' => 0 ),
	'an unused import draws nothing'
);

// An icon-shaped element from somewhere else is not a lucide icon.
$check(
	array( 'src/x.tsx' => 'export const X = () => <ArrowRight />;' ),
	array( 'lucide' => 0 ),
	'no lucide import, no lucide icon'
);

/* framer-motion: the elements and the props that animate them. */
$check(
	array(
		'src/Section.tsx' => <<<'TSX'
		import { motion } from "framer-motion";
		export const Section = () => (
		  <motion.div initial={{ opacity: 0 }} whileInView={{ opacity: 1 }}>
		    <motion.span animate={{ y: 0 }} />
		    <div whileHover={{ scale: 1.02 }} />
		  </motion.div>
		);
		TSX,
	),
	// 2 motion.* elements + initial, whileInView, animate, whileHover.
	array( 'motion' => 6 ),
	'motion elements and animation props'
);

/* Radix through shadcn: the elements, with or without their part suffix. */
$check(
	array(
		'src/Nav.tsx' => <<<'TSX'
		import { Dialog, DialogTrigger, DialogContent } from "@/components/ui/dialog";
		import { Accordion, AccordionItem } from "@/components/ui/accordion";
		export const Nav = () => (
		  <Dialog>
		    <DialogTrigger />
		    <DialogContent />
		    <Accordion>
		      <AccordionItem />
		    </Accordion>
		    <Tabs />
		  </Dialog>
		);
		TSX,
	),
	// Dialog, DialogTrigger, DialogContent, Accordion, Tabs. `AccordionItem`
	// is a row inside one, not an interactive component of its own.
	array( 'radix' => 5 ),
	'Radix components, triggers and content'
);

/* Handlers: every JSX event prop. */
$check(
	array(
		'src/Form.tsx' => <<<'TSX'
		export const Form = () => (
		  <form onSubmit={handle}>
		    <input onChange={set} onBlur={touch} />
		    <button onClick={() => send()}>Send</button>
		    <a href="/x">Link</a>
		  </form>
		);
		TSX,
	),
	array( 'handlers' => 4 ),
	'JSX event props counted, plain links not'
);

/* Nothing in, zeros out — never nulls, which would read as unmeasurable. */
$check( array(), array( 'lucide' => 0, 'handlers' => 0, 'motion' => 0, 'radix' => 0 ), 'no sources gives zeros' );

echo PHP_EOL . 'coverage-signals: ' . ( $ran - $fails ) . '/' . $ran . ' pass' . PHP_EOL;
if ( $fails > 0 ) {
	exit( 1 );
}

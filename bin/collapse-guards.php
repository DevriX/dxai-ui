<?php
/**
 * The guards on the container-collapse path, asserted at every depth.
 *
 * text_run() folds a container whose whole child list is one inline run into a
 * single dxai-ui/text. Three things must stop it, and none of the three
 * reasons is about depth:
 *
 *   - a tag that has a block of its own (`a` is dxai-ui/link, `img` is
 *     dxai-ui/image, `label` reaches group()) — folding it trades a typed
 *     editing surface for a string, and for a link hands the href to rich
 *     text's escaper;
 *   - an element with no text, which Gutenberg's rich text cannot hold and
 *     which swallows the rest of the run on the first edit;
 *   - a data attribute, which is behaviour rather than markup.
 *
 * The tag test used to live in text_run()'s child loop, one level shallower
 * than the other two, and the gap was reachable: `<span><a href>…</a></span>`
 * folded where a bare `<a href>` was refused, and so did `button`, `label` and
 * `div` — which is not phrasing content at all. `img`, `svg` and `input`
 * escaped only because they carry no text and the zero-text clause caught
 * them, which is an accident rather than a reason.
 *
 * No container in the corpus is shaped that way, so no corpus check can see
 * this regress. That is what this file is for.
 *
 * usage: bin/wp-php.sh bin/collapse-guards.php
 * exit:  0 every guard holds, 1 a guard is bypassed, 2 bad usage
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 2 );
}

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Html_To_Blocks;

/**
 * Each case: the source HTML, and whether text_run() is allowed to fold it.
 *
 * The two controls at the end are what prove the instrument can still see a
 * fold at all — without them a converter that folded nothing would pass.
 */
$cases = array(
	// tag with a block of its own, at three depths
	array( 'a, depth 1', '<div class="rv-x"><a href="/guide">Read the guide</a><span>label</span></div>', false ),
	array( 'a, depth 2', '<div class="rv-x"><span><a href="/guide">Read the guide</a></span><span>label</span></div>', false ),
	array( 'a, depth 3', '<div class="rv-x"><span><em><a href="/guide">Read</a></em></span><span>label</span></div>', false ),
	array( 'img, depth 1', '<div class="rv-x"><img src="/i.png" alt="Seal"><span>label</span></div>', false ),
	array( 'img, depth 2', '<div class="rv-x"><span><img src="/i.png" alt="Seal">Seal</span><span>label</span></div>', false ),
	array( 'button, depth 1', '<div class="rv-x"><button type="button">Call</button><span>label</span></div>', false ),
	array( 'button, depth 2', '<div class="rv-x"><span><button type="button">Call</button></span><span>label</span></div>', false ),
	array( 'label, depth 2', '<div class="rv-x"><span><label for="e">Email</label></span><span>label</span></div>', false ),
	array( 'input, depth 2', '<div class="rv-x"><span><input type="text" value="v">t</span><span>label</span></div>', false ),
	array( 'svg, depth 2', '<div class="rv-x"><span><svg viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg>Icon</span><span>label</span></div>', false ),
	// not phrasing content at all
	array( 'div, depth 2', '<div class="rv-x"><span><div>Block in a run</div></span><span>label</span></div>', false ),
	// zero text, at two depths
	array( 'empty span, depth 1', '<div class="rv-x"><span class="rv-check" aria-hidden="true"></span><span>label</span></div>', false ),
	array( 'empty span, depth 2', '<div class="rv-x"><span class="rv-label"><span class="rv-rule" aria-hidden="true"></span>Outcomes</span></div>', false ),
	// behaviour, at two depths
	array( 'data attr, depth 1', '<div class="rv-x"><span data-countup="62" data-suffix="%">62</span><span>label</span></div>', false ),
	array( 'data attr, depth 2', '<div class="rv-x"><span><span data-countup="62">62</span>pct</span><span>label</span></div>', false ),
	// loose copy beside inline children is is_text_flow()'s case, not this one
	array( 'loose text', '<div class="rv-x">lead <span>label</span></div>', false ),
	// controls: these MUST still fold
	array( 'control: two spans', '<div class="rv-x"><span aria-hidden="true">&#10003;</span><span>We do the work</span></div>', true ),
	array( 'control: nested em', '<div class="rv-x"><span><em>Emphasis</em></span><span>label</span></div>', true ),
);

$fail = 0;
printf( "%-22s %-16s %s\n", 'case', 'block', 'verdict' );

foreach ( $cases as [ $label, $html, $may_fold ] ) {
	$blocks = parse_blocks( ( new Html_To_Blocks() )->convert( $html ) );
	$row    = $blocks[0] ?? array();
	$name   = (string) ( $row['blockName'] ?? '(none)' );
	$folded = $name === 'dxai-ui/text';

	if ( $folded === $may_fold ) {
		printf( "%-22s %-16s ok\n", $label, $name );
		continue;
	}
	++$fail;
	printf(
		"%-22s %-16s FAIL (expected %s)\n",
		$label,
		$name,
		$may_fold ? 'a fold into dxai-ui/text' : 'the container to be kept'
	);
	if ( $folded ) {
		$attrs = is_array( $row['attrs'] ?? null ) ? $row['attrs'] : array();
		printf( "%-22s   folded content: %s\n", '', var_export( (string) ( $attrs['content'] ?? '' ), true ) );
	}
}

printf( "\n%d of %d guards hold\n", count( $cases ) - $fail, count( $cases ) );
exit( $fail === 0 ? 0 : 1 );

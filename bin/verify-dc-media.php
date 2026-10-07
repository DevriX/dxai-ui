<?php
/**
 * A Claude Design component that watches two media queries is read with each state following its own.
 *
 *   bash bin/wp-php.sh bin/verify-dc-media.php        (or: wp eval-file bin/verify-dc-media.php --user=1)
 *
 * Five Star's header keeps two states of the viewport in JavaScript: `mobile` follows `(max-width: 860px)` and `wide` follows
 * `(min-width: 1120px)`, and the template shows its rating link when `isWide` (`s.wide !== false`) and a compact trust line when
 * `isCompact` (`s.wide === false && !s.mobile`). The compiler read ONE query for every key (the first), so `wide` followed the
 * mobile breakpoint, inverted, and was missing from the initial state: the page's first render used `wide` unset (shown) and every
 * other render `wide` false, and each element that reads it came out depending on every state of the component — the rating link
 * carried `dxai-off-open dxai-off-mSvc dxai-off-mArea … dxai-off-scrolled`, so it vanished, and the compact line appeared, the moment
 * a dropdown opened. Now each key follows the query its own `matchMedia()` was given (Dc_Script::mount_effects()) and each variant
 * is worked out at the width it stands for (Dc_Renderer: the mobile twin at the breakpoint, the primary one wider than any).
 *
 * Pure: nothing is written. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

require __DIR__ . '/wp-boot.php';

use DXAI_UI\Compiler\Dc_Renderer;
use DXAI_UI\Compiler\Dc_Script;

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

$component = static fn( string $mount, string $vals ): string => 'class Component extends DCLogic {
	state = { mobile: false, open: null, step: 1 };
	componentDidMount() {
' . $mount . '
	}
	renderVals() {
		const s = this.state;
		return {
' . $vals . '
			isMobile: s.mobile, isDesktop: !s.mobile,
			toggle: () => this.setState({ open: s.open === \'a\' ? null : \'a\' }),
			next: () => this.setState({ step: Math.min(s.step + 1, 3) }),
		};
	}
}';

$two_mount = '		this.mq = window.matchMedia(\'(max-width: 860px)\');
		this.onMq = () => { this.setState({ mobile: this.mq.matches, open: null }); };
		this.onMq(); this.mq.addEventListener(\'change\', this.onMq);
		this.wq = window.matchMedia(\'(min-width: 1120px)\');
		this.onWq = () => this.setState({ wide: this.wq.matches });
		this.onWq(); this.wq.addEventListener(\'change\', this.onWq);';
$two_vals  = '			isWide: s.wide !== false, isCompact: s.wide === false && !s.mobile,';
$template  = '<header>'
	. '<sc-if value="{{ isDesktop }}"><div>'
	. '<sc-if value="{{ isWide }}"><a id="rating" href="#reviews">Rating</a></sc-if>'
	. '<sc-if value="{{ isCompact }}"><span id="compact">Compact</span></sc-if>'
	. '<button id="menu" onClick="{{ toggle }}">Menu</button><button id="step" onClick="{{ next }}">Next</button>'
	. '</div></sc-if>'
	. '<sc-if value="{{ isMobile }}"><div id="mobile">Mobile row</div></sc-if>'
	. '</header>';

echo "Two queries\n";
$logic   = new Dc_Script( $component( $two_mount, $two_vals ), array() );
$effects = $logic->mount_effects();
$expect( 'each key follows the query its own matchMedia() was given: mobile the max-width one', '(max-width: 860px)' === ( $effects['media']['mobile'] ?? '' ), wp_json_encode( $effects['media'] ?? null ) );
$expect( '…and wide the min-width one', '(min-width: 1120px)' === ( $effects['media']['wide'] ?? '' ), wp_json_encode( $effects['media'] ?? null ) );

$renderer = new Dc_Renderer( $logic );
$out      = $renderer->render( $template );
$html     = $out['html'];
$tag      = static function ( string $html, string $id ): string {
	return preg_match( '/<[a-z]+\b[^>]*\bid="' . preg_quote( $id, '/' ) . '"[^>]*>/i', $html, $m ) === 1 ? $m[0] : '';
};
$rating = $tag( $html, 'rating' );
$expect( 'the layout switches at the first query\'s breakpoint', 860 === $renderer->breakpoint() && str_contains( $out['css'], '@media (max-width: 860px)' ), (string) $renderer->breakpoint() );
$expect( 'the rating link is in the page', '' !== $rating );
$expect( 'it depends on no state of the component: no dxai-on-/dxai-off- class (it vanished when a dropdown opened)', '' !== $rating && preg_match( '/dxai-(?:on|off)-/', $rating ) !== 1, $rating );
$expect( 'it sits in the desktop row, so it needs no media mark of its own', '' !== $rating && ! str_contains( $rating, 'dxai-dc-' ), $rating );
$expect( 'the compact line, true on neither side, is not drawn (it appeared when a dropdown opened)', ! str_contains( $html, 'Compact' ), substr( $html, 0, 300 ) );
$expect( 'the other states keep their markers: the menu toggle and the step', str_contains( $html, 'dxai-toggle-open--a' ) && str_contains( $html, 'data-dxai-step="1"' ) );
$expect( 'the mobile row is the mobile side\'s', str_contains( $tag( $html, 'mobile' ), 'dxai-dc-mobile' ) );

// A second query that is a min-width BELOW the first's breakpoint (a "tablet" state, true on both sides of it) is true in both variants.
$tablet_mount = '		this.mq = window.matchMedia(\'(max-width: 860px)\');
		this.onMq = () => { this.setState({ mobile: this.mq.matches }); };
		this.onMq(); this.mq.addEventListener(\'change\', this.onMq);
		this.tq = window.matchMedia(\'(min-width: 600px)\');
		this.onTq = () => this.setState({ tablet: this.tq.matches });
		this.onTq(); this.tq.addEventListener(\'change\', this.onTq);';
$tablet       = new Dc_Script( $component( $tablet_mount, '			isTablet: s.tablet !== false,' ), array() );
$tablet_out   = ( new Dc_Renderer( $tablet ) )->render( '<header><sc-if value="{{ isTablet }}"><b id="tab">Tablet and up</b></sc-if></header>' );
$expect( 'a state that is true on both sides of the breakpoint (a min-width below it) is drawn without a media mark', str_contains( $tablet_out['html'], 'Tablet and up' ) && ! str_contains( $tag( $tablet_out['html'], 'tab' ), 'dxai-dc-' ), $tablet_out['html'] );

echo "\nOne query, as before\n";
$one_mount = '		this.mq = window.matchMedia(\'(max-width: 900px)\');
		this.onMq = () => { this.setState({ mobile: this.mq.matches }); };
		this.onMq(); this.mq.addEventListener(\'change\', this.onMq);';
$one       = new Dc_Script( $component( $one_mount, '			isWide: true, isCompact: false,' ), array() );
$one_r     = new Dc_Renderer( $one );
$one_out   = $one_r->render( $template );
$expect( 'a component with one query: its breakpoint, its mobile key, its marks', 900 === $one_r->breakpoint() && '(max-width: 900px)' === ( $one->mount_effects()['media']['mobile'] ?? '' ) && str_contains( $tag( $one_out['html'], 'mobile' ), 'dxai-dc-mobile' ) && str_contains( $one_out['html'], 'id="rating"' ) );

$min_only = new Dc_Script( $component( '		this.mq = window.matchMedia(\'(min-width: 901px)\');
		this.onMq = () => { this.setState({ desktop: this.mq.matches }); };
		this.onMq(); this.mq.addEventListener(\'change\', this.onMq);', '			isWide: true, isCompact: false,' ), array() );
$min_r    = new Dc_Renderer( $min_only );
$min_r->render( $template );
$expect( 'a min-width query alone is still read: its key, and the breakpoint just under it', '(min-width: 901px)' === ( $min_only->mount_effects()['media']['desktop'] ?? '' ) && 900 === $min_r->breakpoint(), (string) $min_r->breakpoint() );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

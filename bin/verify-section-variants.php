<?php
/**
 * The ways a section of the Home can be shown another way (Section_Variants), against sections made for the purpose: the order
 * of cards, the questions kept, the ticks and the second button, the side of the picture, the picture itself; that a class the
 * design styles by its place is not moved; that the same seed makes the same section and another seed another; and that every
 * variant is blocks that come back the same.
 *
 *   bash bin/wp-php.sh bin/verify-section-variants.php        (or: wp eval-file bin/verify-section-variants.php --user=1)
 *
 * Makes two pictures and a stylesheet and removes them again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Library;
use DXAI_UI\Pages\Section_Variants;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

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

$heading = static fn( string $t, int $l = 2 ): string => '<!-- wp:heading {"level":' . $l . '} --><h' . $l . ' class="wp-block-heading">' . $t . '</h' . $l . '><!-- /wp:heading -->';
$para    = static fn( string $t ): string => '<!-- wp:paragraph --><p>' . $t . '</p><!-- /wp:paragraph -->';
$group   = static fn( string $class, string $inner, string $tag = 'div' ): string => '<!-- wp:group {"className":"' . $class . '"' . ( $tag !== 'div' ? ',"tagName":"' . $tag . '"' : '' ) . '} --><' . $tag . ' class="wp-block-group ' . $class . '">' . $inner . '</' . $tag . '><!-- /wp:group -->';
$cards   = static function ( string $class, int $n ) use ( $heading, $para, $group ): string {
	$out = '';
	for ( $i = 1; $i <= $n; $i++ ) {
		$out .= $group( $class, $heading( 'Card ' . $i, 3 ) . $para( 'Words of card ' . $i ) );
	}

	return $out;
};
$section = static fn( string $inner ): array => parse_blocks( $group( 'sec', $inner, 'section' ) )[0];
$analyse = static fn( array $block ): array => Section_Library::analyze( $block, 0 );
$texts   = static function ( array $block ): array {
	$out  = array();
	$walk = static function ( array $b ) use ( &$walk, &$out ): void {
		if ( ( $b['blockName'] ?? '' ) === 'core/heading' ) {
			$out[] = trim( wp_strip_all_tags( (string) $b['innerHTML'] ) );
		}
		foreach ( (array) $b['innerBlocks'] as $c ) {
			$walk( (array) $c );
		}
	};
	$walk( $block );

	return $out;
};
$call = new ReflectionMethod( Section_Variants::class, 'try_one' );
$call->setAccessible( true );
$zero = static fn( string $k ): int => 0;
$one  = static fn( string $k ): int => 1;
$try  = static function ( string $kind, array $block, array $ctx, ?callable $h = null ) use ( $call, $analyse, $zero ): ?array {
	return $call->invoke( null, $kind, $analyse( $block ), $block, $ctx, $h ?? $zero, array() );
};
$ctx = array( 'seed' => 'x', 'positional' => array(), 'pool' => array(), 'used' => array(), 'index' => 0, 'role' => 'cards' );

echo "The order of cards\n";
$sec = $section( $heading( 'Our services' ) . $group( 'grid', $cards( 'card', 4 ) ) );
$r   = $try( 'rotate', $sec, $ctx );
$h0  = $texts( $sec );
$h1  = $texts( $r['block'] ?? array() );
$expect( 'four cards of one kind come in another order: the same cards, the section\'s heading first', $r !== null && count( $h1 ) === count( $h0 ) && $h1[0] === $h0[0] && $h1 !== $h0 && array_diff( $h1, $h0 ) === array() && array_diff( $h0, $h1 ) === array(), json_encode( $h1 ) );
$expect( 'and the change has a name, with the order in it', preg_match( '/^order:card:[0-3]-[0-3]-[0-3]-[0-3]$/', (string) ( $r['op'] ?? '' ) ) === 1, (string) ( $r['op'] ?? '' ) );
$expect( 'a class the design styles by its place (:nth-child) is not moved', $try( 'rotate', $sec, array_merge( $ctx, array( 'positional' => array( 'card' => true ) ) ) ) === null );
$expect( 'a design whose stylesheet cannot be read has nothing moved', $try( 'rotate', $sec, array_merge( $ctx, array( 'positional' => null ) ) ) === null );
$mixed = $section( $heading( 'Our services' ) . $group( 'grid', $group( 'card featured', $heading( 'A', 3 ) . $para( 'a' ) ) . $group( 'card', $heading( 'B', 3 ) . $para( 'b' ) ) . $group( 'card', $heading( 'C', 3 ) . $para( 'c' ) ) ) );
$expect( 'cards that are not all of one kind (a featured one) are left', $try( 'rotate', $mixed, $ctx ) === null );
$expect( 'two cards are not turned', $try( 'rotate', $section( $heading( 'Few' ) . $group( 'grid', $cards( 'card', 2 ) ) ), $ctx ) === null );
$expect( 'the order is what the seed says: another seed, another order', count( array_unique( array_map( static fn( $n ) => (string) ( $try( 'rotate', $sec, $ctx, static fn( $k ) => crc32( $n . $k ) )['op'] ?? '' ), range( 1, 12 ) ) ) ) > 3 );
$logos = $section( $heading( 'Trusted' ) . $group( 'row', '<!-- wp:image {"className":"w-auto h-40px"} --><figure class="wp-block-image w-auto h-40px"><img src="https://example.test/1.png" alt="One"/></figure><!-- /wp:image --><!-- wp:image {"className":"w-auto h-58px"} --><figure class="wp-block-image w-auto h-58px"><img src="https://example.test/2.png" alt="Two"/></figure><!-- /wp:image --><!-- wp:image {"className":"w-auto h-72px"} --><figure class="wp-block-image w-auto h-72px"><img src="https://example.test/3.png" alt="Three"/></figure><!-- /wp:image -->' ) );
$expect( 'logos, each its own size, are put in another order too, each keeping its own size', ( function () use ( $try, $logos, $ctx ) { $r = $try( 'rotate', $logos, $ctx ); return $r !== null && str_contains( $r['op'], 'order:logo:' ) && substr_count( serialize_block( $r['block'] ), 'h-40px' ) === 2 && serialize_block( $r['block'] ) !== serialize_block( $logos ); } )() );

echo "\nThe questions\n";
$qa = '';
for ( $i = 1; $i <= 6; $i++ ) {
	$qa .= $group( 'qa', '<!-- wp:dxai-ui/text {"tagName":"summary"} --><summary>Question ' . $i . '</summary><!-- /wp:dxai-ui/text -->' . $para( 'Answer ' . $i ), 'details' );
}
$faq = $section( $heading( 'Questions' ) . $group( 'list', $qa ) );
$r   = $try( 'faq', $faq, $ctx );
$left = $r === null ? 0 : preg_match_all( '/Question \d/', serialize_block( $r['block'] ) );
$expect( 'six questions become four or five', $r !== null && $left >= 4 && $left <= 5, (string) $left );
$expect( 'the first question stays', $r !== null && str_contains( serialize_block( $r['block'] ), 'Question 1<' ) );
$expect( 'the answers go with their questions', $r !== null && preg_match_all( '/Answer \d/', serialize_block( $r['block'] ) ) === $left );
$expect( 'four questions are all there are', $try( 'faq', $section( $heading( 'Questions' ) . $group( 'list', $group( 'qa', '<!-- wp:dxai-ui/text {"tagName":"summary"} --><summary>Q</summary><!-- /wp:dxai-ui/text -->' . $para( 'A' ), 'details' ) . $group( 'qa', '<!-- wp:dxai-ui/text {"tagName":"summary"} --><summary>Q</summary><!-- /wp:dxai-ui/text -->' . $para( 'A' ), 'details' ) ) ), $ctx ) === null );

echo "\nWhat a section can do without\n";
$list = '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Free estimate</li><!-- /wp:list-item --><!-- wp:list-item --><li>24/7</li><!-- /wp:list-item --><!-- wp:list-item --><li>Insured</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
$btns = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#a">Call now</a></div><!-- /wp:button --><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#b">Our work</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';
$hero = $section( $heading( 'Hero', 1 ) . $para( 'Lead words of the hero section.' ) . $list . $btns );
$ctxh = array_merge( $ctx, array( 'role' => 'hero' ) );
$two  = static fn( string $k ): int => 2;
$all  = static fn( string $k ): int => 3;
$r0   = $try( 'drop', $hero, $ctxh, $one );
$r1   = $try( 'drop', $hero, $ctxh, $two );
$rb   = $try( 'drop', $hero, $ctxh, $all );
$expect( 'the ticks can be left out', $r0 !== null && $r0['op'] === 'drop:list' && ! str_contains( serialize_block( $r0['block'] ), 'Free estimate' ) );
$expect( 'or the second button, and the first stays', $r1 !== null && $r1['op'] === 'drop:button' && str_contains( serialize_block( $r1['block'] ), 'Call now' ) && ! str_contains( serialize_block( $r1['block'] ), 'Our work' ) );
$expect( 'both can go (a section that can do without two things has four ways: none, either, both)', $rb !== null && $rb['op'] === 'drop:list+button' && ! str_contains( serialize_block( $rb['block'] ), 'Free estimate' ) && ! str_contains( serialize_block( $rb['block'] ), 'Our work' ) && str_contains( serialize_block( $rb['block'] ), 'Call now' ) );
$expect( 'and the seed can leave it as it is', $try( 'drop', $hero, $ctxh, $zero ) === null );
$expect( 'the heading and the words stay either way', str_contains( serialize_block( $r0['block'] ?? array() ), 'Hero' ) && str_contains( serialize_block( $r0['block'] ?? array() ), 'Lead words' ) );
$expect( 'the section is valid blocks after it', ! is_wp_error( Block_Tree::serialize_checked( array( $r0['block'] ?? array(), $r1['block'] ?? array() ) ) ) );
$expect( 'a section of cards is not stripped', $try( 'drop', $sec, $ctx ) === null );
$lonely = $section( $heading( 'Only' ) . $btns );
$expect( 'a section that would be left with no words keeps what it has', $try( 'drop', $lonely, $ctxh ) === null );

echo "\nThe side of the picture\n";
$row = $section( $group( 'd-flex gap', $group( 'words', $heading( 'About us' ) . $para( 'Family run since 1983, in the words of the Home.' ) ) . $group( 'photo', '<!-- wp:image {"id":0} --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt="A crew"/></figure><!-- /wp:image -->' ) ) );
$ctxt = array_merge( $ctx, array( 'role' => 'two-col' ) );
$r    = $try( 'flip', $row, $ctxt );
$first = $r === null ? '' : (string) ( $r['block']['innerBlocks'][0]['innerBlocks'][0]['blockName'] ?? '' );
$expect( 'words on one side and a picture on the other swap sides', $r !== null && $r['op'] === 'flip' && $first === 'core/group' && str_contains( serialize_block( (array) $r['block']['innerBlocks'][0]['innerBlocks'][0] ), 'wp-block-image' ) );
$expect( 'and it is the same blocks', $r !== null && substr_count( serialize_block( $r['block'] ), 'About us' ) === 1 && substr_count( serialize_block( $r['block'] ), 'wp-block-image' ) === 1 );
$expect( 'a side styled by its place is not swapped', $try( 'flip', $row, array_merge( $ctxt, array( 'positional' => array( 'words' => true ) ) ) ) === null );
$stack = $section( $group( 'd-flex flex-column', $group( 'words', $heading( 'About us' ) . $para( 'Words.' ) ) . $group( 'photo', '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/a.jpg" alt=""/></figure><!-- /wp:image -->' ) ) );
$expect( 'a column (words above the picture) is not a row, and is left', $try( 'flip', $stack, $ctxt ) === null );
$expect( 'a section that is not one of two sides is left', $try( 'flip', $sec, $ctxt ) === null );

echo "\nThe picture\n";
$up   = wp_upload_dir();
$mk   = static function ( string $name, int $w, int $h ) use ( $up ): int {
	$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $name, 'post_mime_type' => 'image/jpeg' ) );
	update_post_meta( $id, '_wp_attached_file', 'dxai-ui/' . $name . '.jpg' );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => $w, 'height' => $h, 'file' => 'dxai-ui/' . $name . '.jpg' ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Alt of ' . $name );

	return (int) $id;
};
$ids  = array( $mk( 'vtxsv-a', 1600, 900 ), $mk( 'vtxsv-b', 1500, 860 ), $mk( 'vtxsv-tall', 600, 1200 ), $mk( 'vtxsv-small', 120, 80 ) );
$img  = static fn( int $id, string $name ): string => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $id ) . '" alt="Alt of ' . $name . '" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
$lib  = array(
	$analyse( $section( $heading( 'One' ) . $para( 'First section words.' ) . $img( $ids[0], 'vtxsv-a' ) ) ),
	array_merge( $analyse( $section( $heading( 'Two' ) . $para( 'Second section words.' ) . $img( $ids[1], 'vtxsv-b' ) ) ), array( 'index' => 1 ) ),
	array_merge( $analyse( $section( $heading( 'Three' ) . $para( 'Third section words.' ) . $img( $ids[2], 'vtxsv-tall' ) ) ), array( 'index' => 2 ) ),
	array_merge( $analyse( $section( $heading( 'Four' ) . $para( 'Fourth section words.' ) . $img( $ids[3], 'vtxsv-small' ) ) ), array( 'index' => 3 ) ),
);
$pool = Section_Variants::pool( $lib );
$expect( 'the Home\'s pictures are known with their shape, the small ones (logos, badges) are not', array_column( $pool, 'id' ) === array( $ids[0], $ids[1], $ids[2] ), json_encode( array_column( $pool, 'id' ) ) );
$ctxi = array_merge( $ctx, array( 'role' => 'two-col', 'pool' => $pool, 'index' => 0 ) );
$r    = $try( 'image', $lib[0]['block'], $ctxi );
$expect( 'a section shows another of the Home\'s pictures of nearly its shape', $r !== null && (int) $r['image'] === $ids[1] && str_contains( serialize_block( $r['block'] ), 'wp-image-' . $ids[1] ) && str_contains( serialize_block( $r['block'] ), 'Alt of vtxsv-b' ), json_encode( $r['image'] ?? null ) );
$expect( 'not a tall one for a wide one', $r !== null && (int) $r['image'] !== $ids[2] );
$expect( 'a picture already used on the page is not used twice', $call->invoke( null, 'image', $analyse( $lib[0]['block'] ), $lib[0]['block'], array_merge( $ctxi, array( 'used' => array( $ids[1] ) ) ), $zero, array() ) === null );
$expect( 'a section in a role that has no picture of its own is left', $try( 'image', $lib[0]['block'], array_merge( $ctxi, array( 'role' => 'faq' ) ) ) === null );
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}

echo "\nThe seed\n";
$full = array_merge( $analyse( $section( $heading( 'Our work', 1 ) . $para( 'Lead words of the section.' ) . $list . $btns . $group( 'grid', $cards( 'card', 4 ) ) ) ), array() );
$a    = Section_Variants::apply( $full, array_merge( $ctx, array( 'seed' => 'page-a|3', 'role' => 'hero' ) ) );
$b    = Section_Variants::apply( $full, array_merge( $ctx, array( 'seed' => 'page-a|3', 'role' => 'hero' ) ) );
$expect( 'the same seed makes the same section', serialize_block( $a['block'] ) === serialize_block( $b['block'] ) && $a['ops'] === $b['ops'] );
$seen = array();
for ( $i = 0; $i < 40; $i++ ) {
	$v = Section_Variants::apply( $full, array_merge( $ctx, array( 'seed' => 'page-' . $i . '|3', 'role' => 'hero' ) ) );
	$seen[ implode( ',', $v['ops'] ) ] = true;
	if ( is_wp_error( Block_Tree::serialize_checked( array( $v['block'] ) ) ) ) {
		$expect( 'every variant is blocks that come back the same', false, 'seed ' . $i );
		break;
	}
}
$expect( 'other seeds make other sections (more than three ways in forty pages)', count( $seen ) > 3, json_encode( array_keys( $seen ) ) );
$expect( 'and the section is still the Home\'s: its heading and its words', str_contains( serialize_block( $a['block'] ), 'Our work' ) && str_contains( serialize_block( $a['block'] ), 'Lead words' ) );
$expect( 'no style is added: no inline style', ! str_contains( serialize_block( $a['block'] ), ' style="' ) );

echo "\nThe classes a design styles by place\n";
$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Variants', 'post_content' => '' ) );
update_post_meta( $home, Page_Scope::META, $home );
update_post_meta( $home, '_dxai_ui_css_url', 'dxai-ui/fixture-variants-none.css' );
$expect( 'a stylesheet that is not there gives null: nothing is moved', Section_Variants::positional_classes( $home ) === null );
$path = Upload_Paths::path( Upload_Paths::DIR . '/fixture-variants.css' );
wp_mkdir_p( dirname( $path ) );
file_put_contents( $path, ".grid > .card:first-child { grid-column: span 2 }\n.row .tile:nth-child(2n+1){margin:0}\n/* .ghost:last-child { x:y } */\n.plain { color: red }\n@media (min-width: 600px) { .list > .qa:last-child { border: 0 } }\n.cls\\:x:only-child{a:b}\n" );
update_post_meta( $home, '_dxai_ui_css_url', Upload_Paths::DIR . '/fixture-variants.css' );
$pos = Section_Variants::positional_classes( $home );
$expect( 'the classes in a rule about a place are found, those of a comment and of other rules are not', is_array( $pos ) && isset( $pos['card'], $pos['tile'], $pos['qa'], $pos['grid'] ) && ! isset( $pos['ghost'] ) && ! isset( $pos['plain'] ), json_encode( $pos ) );
wp_delete_file( $path );
wp_delete_post( $home, true );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

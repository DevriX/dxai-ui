<?php
/**
 * A design's page opens in the editor without its wrapper groups, and is stored exactly as it was.
 *
 *   bash bin/wp-php.sh bin/verify-wrap-fold.php        (or: wp eval-file bin/verify-wrap-fold.php --user=1)
 *
 * Wrap_Fold folds a Group (or a Box) around one block into that block's `metadata.dxaiWrap` when the editor asks for a design's page
 * (`context=edit`), lifts the page's frame (the one group around all its sections) onto the sections, names the sections in the List
 * View, and puts every one of these back when the editor saves (`rest_pre_insert_page`). What is stored, rendered and read by the rest
 * of the plugin never carries a fold. Checked here: what folds and what does not (a wrapper with a setting of the editor's own, a wrapper
 * of two blocks, a page that is not a design's), that unfold(fold(x)) is x byte for byte, that what someone does in the editor — an edit,
 * a rename, a section added, moved or deleted — is what is stored, and the same through the REST API the editor uses.
 *
 * Makes pages and removes them. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Wrap_Fold;

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

$made = array();
$fin  = static function () use ( &$made ): void {
	foreach ( $made as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$made = array();
};
register_shutdown_function( $fin );

$para    = static fn( string $words ): string => '<!-- wp:paragraph --><p>' . $words . '</p><!-- /wp:paragraph -->';
$heading = static fn( string $words, int $level = 2 ): string => '<!-- wp:heading {"level":' . $level . '} --><h' . $level . ' class="wp-block-heading">' . $words . '</h' . $level . '><!-- /wp:heading -->';
$group   = static fn( string $class, string $inner, string $extra = '', string $tag = 'div' ): string => '<!-- wp:group {' . ( $tag !== 'div' ? '"tagName":"' . $tag . '",' : '' ) . '"className":"' . $class . '"' . $extra . '} --><' . $tag . ' class="wp-block-group ' . $class . '">' . $inner . '</' . $tag . '><!-- /wp:group -->';
$box     = static fn( string $class, string $inner ): string => '<!-- wp:dxai-ui/box {"tagName":"span","className":"' . $class . '"} --><span class="' . $class . '">' . $inner . '</span><!-- /wp:dxai-ui/box -->';
$skip    = '<!-- wp:dxai-ui/link {"className":"skip-link","url":"#content","text":"Skip to content"} --><a class="wp-block-dxai-ui-link skip-link" href="#content">Skip to content</a><!-- /wp:dxai-ui/link -->';

// An Elementor-shaped page: main > .page-content > .gem-1 around three sections, each section a box around its inner box around
// widgets, each widget a group around its heading or text; one widget with a colour setting; one with two blocks.
$widget   = static fn( string $id, string $inner ) => $group( 'gem-element gem-element-' . $id . ' gem-widget', $inner, ',"dxaiData":{"data-id":"' . $id . '"}' );
$section  = static fn( string $id, string $inner ) => $group( 'gem-element gem-element-' . $id . ' e-con e-parent', $group( 'e-con-inner', $inner ) );
$hero     = $section( 's1', $widget( 'w1', $heading( 'Hero words', 1 ) ) . $widget( 'w2', $para( 'Hero text' ) ) );
$services = $section( 's2', $widget( 'w3', $heading( 'Our services' ) ) . $group( 'gem-colored', $para( 'Coloured' ), ',"backgroundColor":"primary"' ) . $widget( 'w4', $box( 'gem-button-content-wrapper', $box( 'gem-button-icon', '<!-- wp:dxai-ui/svg {"svgInner":""} --><svg></svg><!-- /wp:dxai-ui/svg -->' ) ) ) );
$contact  = $section( 's3', $widget( 'w5', $heading( 'Talk to us' ) . $para( 'Two blocks in one widget' ) ) );
$frame    = $group( 'site-main', $group( 'page-content', $group( 'gem gem-1', $hero . $services . $contact ) ), ',"anchor":"content"', 'main' );
$content  = $skip . "\n" . $frame . "\n";

echo "Folding\n";
$folded = Wrap_Fold::fold( $content );
$tree   = parse_blocks( $folded );
$real   = array_values( array_filter( $tree, static fn( $b ) => ! empty( $b['blockName'] ) ) );
$expect( 'the sections stand at the top of the page, after the skip link: the frame around them is lifted', count( $real ) === 4 && $real[0]['blockName'] === 'dxai-ui/link', wp_json_encode( array_map( static fn( $b ) => $b['attrs']['className'] ?? $b['blockName'], $real ) ) );
$expect( '…each carrying the frame it was in (main, the content box, the page root)', count( (array) ( $real[1]['attrs']['metadata'][ Wrap_Fold::FRAME ]['wraps'] ?? array() ) ) === 3 && ( $real[1]['attrs']['metadata'][ Wrap_Fold::FRAME ]['id'] ?? '' ) === ( $real[3]['attrs']['metadata'][ Wrap_Fold::FRAME ]['id'] ?? 'x' ) );
$expect( 'a section is its inner box, its own box folded into it', ( $real[1]['attrs']['className'] ?? '' ) === 'e-con-inner' && count( (array) ( $real[1]['attrs']['metadata'][ Wrap_Fold::KEY ] ?? array() ) ) === 1 );
$expect( 'each section is named in the List View: the hero, then by its heading', ( $real[1]['attrs']['metadata']['name'] ?? '' ) === 'Hero Section' && ( $real[2]['attrs']['metadata']['name'] ?? '' ) === 'Our services', wp_json_encode( array_map( static fn( $b ) => $b['attrs']['metadata']['name'] ?? '', $real ) ) );
$hero_kids = $real[1]['innerBlocks'];
$expect( 'a widget around one heading is the heading: the group is folded into it', $hero_kids[0]['blockName'] === 'core/heading' && ( $hero_kids[0]['attrs']['metadata'][ Wrap_Fold::KEY ][0]['attrs']['className'] ?? '' ) === 'gem-element gem-element-w1 gem-widget' );
$svc = $real[2]['innerBlocks'];
$expect( 'a group with a colour of the editor\'s own stays a group someone edits', $svc[1]['blockName'] === 'core/group' && ( $svc[1]['attrs']['backgroundColor'] ?? '' ) === 'primary' );
$expect( 'two boxes around an icon fold into it, outermost first, under the widget around them', $svc[2]['blockName'] === 'dxai-ui/svg' && array_map( static fn( $w ) => $w['attrs']['className'] ?? '', (array) $svc[2]['attrs']['metadata'][ Wrap_Fold::KEY ] ) === array( 'gem-element gem-element-w4 gem-widget', 'gem-button-content-wrapper', 'gem-button-icon' ) );
$expect( 'a widget of two blocks stays a group: the section whose one widget it is, is that group', $real[3]['blockName'] === 'core/group' && str_contains( (string) ( $real[3]['attrs']['className'] ?? '' ), 'gem-element-w5' ) && count( $real[3]['innerBlocks'] ) === 2 && count( (array) $real[3]['attrs']['metadata'][ Wrap_Fold::KEY ] ) === 2 );
$before = Wrap_Fold::measure( $content );
$after  = Wrap_Fold::measure( $folded );
$expect( 'the List View is shorter and shallower', $after['blocks'] < $before['blocks'] && $after['depth'] < $before['depth'], wp_json_encode( array( $before, $after ) ) );
$expect( 'unfolded, the page is what it was, byte for byte', Wrap_Fold::unfold( $folded ) === $content );
$expect( 'content with nothing to fold is left as it is', Wrap_Fold::fold( $para( 'Alone' ) ) === $para( 'Alone' ) && Wrap_Fold::unfold( $para( 'Alone' ) ) === $para( 'Alone' ) );
// A section the import named (Section_Names), around a box, around a group of two: the name travels down to the block that stands for
// the section in the List View, and back to the section it belongs to.
$named_page = $group( 'team-section', $group( 'team-box', $group( 'team-cards', $para( 'One' ) . $para( 'Two' ) ) ), ',"metadata":{"name":"Our team"}' ) . $para( 'After' );
$named_fold = parse_blocks( Wrap_Fold::fold( $named_page ) );
$expect( 'a named section folded twice: the block standing for it carries its name', ( $named_fold[0]['attrs']['className'] ?? '' ) === 'team-cards' && ( $named_fold[0]['attrs']['metadata']['name'] ?? '' ) === 'Our team' );
$expect( '…and unfolded, the name is the section\'s again, and no other block took it', Wrap_Fold::unfold( Wrap_Fold::fold( $named_page ) ) === $named_page );

echo "\nWhat someone does in the editor is what is stored\n";
// The editor's own serializer: a paragraph changed in a folded widget.
$edited = str_replace( 'Hero text', 'New hero text', $folded );
$expect( 'an edit inside a folded widget: the page is the same but for the words', Wrap_Fold::unfold( $edited ) === str_replace( 'Hero text', 'New hero text', $content ) );
// A section renamed in the List View keeps its new name; the name the editor gave is not stored.
$renamed = str_replace( '"name":"Our services"', '"name":"Services we offer"', $folded );
$out     = Wrap_Fold::unfold( $renamed );
$expect( 'a section someone renamed keeps the name on its own block', str_contains( $out, '"name":"Services we offer"' ) && ! str_contains( $out, 'Our services"' ) && ! str_contains( $out, Wrap_Fold::NAMED ) );
// Sections moved: the frame holds them in their new order.
$blocks   = parse_blocks( $folded );
$keys     = array_keys( array_filter( $blocks, static fn( $b ) => ! empty( $b['blockName'] ) ) );
$moved    = $blocks;
$moved[ $keys[1] ] = $blocks[ $keys[3] ];
$moved[ $keys[3] ] = $blocks[ $keys[1] ];
$out      = Wrap_Fold::unfold( serialize_blocks( $moved ) );
$expect( 'sections moved are moved inside the frame', substr_count( $out, '<main' ) === 1 && strpos( $out, 'Talk to us' ) < strpos( $out, 'Hero words' ) && strpos( $out, 'gem gem-1' ) < strpos( $out, 'Talk to us' ) );
// A section added between two of them, and one after the last: both inside the frame.
$added = $blocks;
array_splice( $added, $keys[2], 0, array( parse_blocks( $group( 'new-one', $para( 'Added between' ) ) )[0] ) );
$added[] = parse_blocks( $group( 'new-two', $para( 'Added last' ) ) )[0];
$out     = Wrap_Fold::unfold( serialize_blocks( $added ) );
$close   = strrpos( $out, '</main>' );
$expect( 'a section added among them, and one added after the last, are in the page\'s frame', substr_count( $out, '<main' ) === 1 && strpos( $out, 'Added between' ) < $close && strpos( $out, 'Added last' ) < $close && strpos( $out, 'Added between' ) > strpos( $out, 'Hero words' ) );
// A section deleted.
$deleted = $blocks;
unset( $deleted[ $keys[2] ] );
$out = Wrap_Fold::unfold( serialize_blocks( array_values( $deleted ) ) );
$expect( 'a section deleted is gone and the frame holds the rest', ! str_contains( $out, 'Our services' ) && substr_count( $out, '<main' ) === 1 && str_contains( $out, 'Talk to us' ) );
// A folded heading duplicated: both are wrapped as the one was.
$hero_block                   = $blocks[ $keys[1] ];
array_splice( $hero_block['innerBlocks'], 1, 0, array( $hero_block['innerBlocks'][0] ) );
$dup                          = $blocks;
$dup[ $keys[1] ]              = $hero_block;
$out                          = Wrap_Fold::unfold( serialize_blocks( $dup ) );
$expect( 'a folded heading duplicated: both carry the widget around them', substr_count( $out, 'gem-element-w1 gem-widget' ) === 4 /* comment + class, twice */ && substr_count( $out, 'Hero words' ) === 2 );
$expect( 'nothing of the fold is left in what is stored', ! preg_match( '/dxaiWrap|dxaiFrame|dxaiNamed/', Wrap_Fold::unfold( serialize_blocks( $dup ) ) ) );

echo "\nThrough the REST API, as the editor asks for it\n";
$design = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Wrap fold fixture design page', 'post_content' => wp_slash( $content ) ) );
$plain  = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Wrap fold fixture theme page', 'post_content' => wp_slash( $content ) ) );
$made[] = $design;
$made[] = $plain;
update_post_meta( $design, '_dxai_ui_generated_page', '1' );
$get = static function ( int $id, string $context ): string {
	$request = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $id );
	$request->set_param( 'context', $context );
	$data = rest_do_request( $request )->get_data();

	return (string) ( $data['content']['raw'] ?? ( $data['content']['rendered'] ?? '' ) );
};
wp_set_current_user( 1 );
$raw = $get( $design, 'edit' );
$expect( 'a design\'s page opens folded', str_contains( $raw, Wrap_Fold::KEY ) && str_contains( $raw, Wrap_Fold::FRAME ) );
$expect( 'a page that is not a design\'s opens as it is stored', $get( $plain, 'edit' ) === $content );
$view = $get( $design, 'view' );
$expect( 'what a visitor reads of it (context=view) is not folded', ! str_contains( $view, 'dxaiWrap' ) && str_contains( $view, 'gem-element-w1' ) );
$save = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $design );
$save->set_param( 'content', str_replace( 'Hero text', 'Saved hero text', $raw ) );
$status = rest_do_request( $save )->get_status();
clean_post_cache( $design );
$expect( 'saved from the editor, it is stored unfolded, with the edit and nothing else changed', 200 === $status && get_post_field( 'post_content', $design ) === str_replace( 'Hero text', 'Saved hero text', $content ), (string) $status );
add_filter( 'dxai_ui_fold_wrappers', '__return_false' );
$expect( 'a site that turns it off opens a design\'s page as it is stored', $get( $design, 'edit' ) === get_post_field( 'post_content', $design ) );
remove_all_filters( 'dxai_ui_fold_wrappers' );

echo "\nA fold does not let anything else in\n";
$forged = str_replace( '"open":"', '"open":"<script>', $folded );
$out    = Wrap_Fold::unfold( $forged );
$expect( 'a wrapper whose tags are not one opening and one closing tag is dropped, not written', ! str_contains( $out, '<script' ) );
$bad_name = preg_replace( '/"name":"core\/group"/', '"name":"core/html"', $folded, 1 );
$expect( 'nor is one that is not a group or a box', ! str_contains( Wrap_Fold::unfold( (string) $bad_name ), 'wp:html' ) );

$fin();
echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

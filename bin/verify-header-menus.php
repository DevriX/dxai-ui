<?php
/**
 * A header whose menu is a mega menu is read as menus, and the menus draw it again.
 *
 *   bash bin/wp-php.sh bin/verify-header-menus.php        (or: wp eval-file bin/verify-header-menus.php --user=1)
 *
 * A design's header becomes the items of Appearance > Menus when its menu slots can be read so that the design's own items render the
 * design's header byte for byte (Header_Menus::verify()); otherwise it stays a template part. A menu whose rows hold a trigger and a panel
 * (a dropdown, a mega menu with columns and a line of description under each link) was one blob of HTML per row — a list item's rich text —
 * so the reader saw one link out of every five and the design (Five Star, ARA Guide) kept its header as a part: no menus. Now:
 *
 *   - the converter writes such a row, in a header's navigation only, as blocks (Html_To_Blocks::list_block());
 *   - the reader finds the dropdowns, the columns of a panel and the links in them, a link's title and its description apart
 *     (Header_Template, Header_Html::stack()), a picture block's link as the logo it is;
 *   - the renderer writes a changed title, a changed or an emptied description, and a new item, each into its own place.
 *
 * Writes nothing but three menus (the locations checks), which it removes. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Chrome\Header_Html;
use DXAI_UI\Chrome\Header_Menus;
use DXAI_UI\Chrome\Header_Renderer;
use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Compiler\Html_To_Blocks;

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

$convert = static fn( string $html ): string => ( new Html_To_Blocks( '' ) )->convert( $html );

/** The rows (inner blocks) of the first core/list of a markup. */
$rows_of = static function ( string $markup ): array {
	$rows = null;
	$find = static function ( array $b ) use ( &$find, &$rows ): void {
		if ( null === $rows && 'core/list' === ( $b['blockName'] ?? '' ) ) {
			$rows = array_values( array_filter( (array) $b['innerBlocks'], static fn( array $r ): bool => ! empty( $r['blockName'] ) ) );
			return;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $kid ) {
			$find( $kid );
		}
	};
	foreach ( parse_blocks( $markup ) as $top ) {
		$find( $top );
	}

	return (array) $rows;
};

/** The block names of a markup, as a count per name. */
$names = static function ( string $markup ): array {
	$out  = array();
	$walk = static function ( array $blocks ) use ( &$walk, &$out ): void {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$out[ $b['blockName'] ] = ( $out[ $b['blockName'] ] ?? 0 ) + 1;
			}
			$walk( (array) ( $b['innerBlocks'] ?? array() ) );
		}
	};
	$walk( parse_blocks( $markup ) );

	return $out;
};

// A header the way Five Star writes its menu: a row is a trigger and a panel of columns, each link a title and a line under it.
$mega = '<li class="relative"><button class="dxai-toggle-open--svc" type="button" aria-expanded="false" aria-haspopup="true" data-dxai-hover="enter">Services<svg aria-hidden="true" width="10" height="10" viewBox="0 0 10 10"><path d="M1 3l4 4 4-4" stroke="currentColor"/></svg></button>'
	. '<div class="dxai-on-open--svc hidden">'
	. '<div><p>Emergency cleanup</p><ul>'
	. '<li><a href="/service/water/"><span>Water Damage</span><span>Burst pipes, flooded basements</span></a></li>'
	. '<li><a href="/service/fire/"><span>Fire Damage</span><span>Soot, odor, board-up</span></a></li>'
	. '</ul></div>'
	. '<div><p>Restore and rebuild</p><ul>'
	. '<li><a href="/service/mold/"><span>Mold Removal</span><span>Containment and remediation</span></a></li>'
	. '<li><a href="/service/rebuild/"><span>Rebuild</span><span>Repairs through final paint</span></a></li>'
	. '</ul></div>'
	. '<a href="tel:+15550100"><span>Not sure?</span><span>(555) 0100</span></a>'
	. '</div></li>';
$flat = '<li><a href="#reviews">Reviews</a></li><li><a href="#faq">FAQ</a></li>';
$bar  = static fn( string $rows ): string => '<header><div><a href="/" aria-label="Acme home"><img src="/logo.png" alt="Acme"></a><nav aria-label="Main"><ul>' . $rows . '</ul></nav></div></header>';

echo "The converter\n";
$markup = $convert( $bar( $mega . $flat ) );
$n      = $names( $markup );
$blob = false;
$scan = static function ( array $blocks ) use ( &$scan, &$blob ): void {
	foreach ( $blocks as $b ) {
		if ( 'core/list-item' === ( $b['blockName'] ?? '' ) && str_contains( (string) $b['innerHTML'], 'dxai-on-open' ) ) {
			$blob = true;
		}
		$scan( (array) ( $b['innerBlocks'] ?? array() ) );
	}
};
$scan( parse_blocks( $markup ) );
$expect( 'a header row that holds a trigger and a panel is blocks, not one list item\'s HTML', ! $blob && ( $n['dxai-ui/box'] ?? 0 ) >= 1, wp_json_encode( $n ) );
$rows = array();
foreach ( parse_blocks( $markup ) as $top ) {
	$find = static function ( array $b ) use ( &$find, &$rows ): void {
		if ( 'core/list' === ( $b['blockName'] ?? '' ) && count( $rows ) === 0 ) {
			$rows = (array) $b['innerBlocks'];
			return;
		}
		foreach ( (array) ( $b['innerBlocks'] ?? array() ) as $kid ) {
			$find( $kid );
		}
	};
	$find( $top );
}
$kinds = array_map( static fn( array $b ): string => (string) $b['blockName'], array_values( array_filter( $rows, static fn( array $b ): bool => ! empty( $b['blockName'] ) ) ) );
$expect( 'the plain rows of the same list stay list items', 3 === count( $kinds ) && 'core/list-item' === $kinds[1] && 'core/list-item' === $kinds[2], wp_json_encode( $kinds ) );
$expect( 'the structural row is the first one, a box (the trigger and the panel are its children)', 'core/list-item' !== ( $kinds[0] ?? '' ) && count( (array) ( $rows[0]['innerBlocks'] ?? array() ) ) >= 2, wp_json_encode( $kinds ) );

$section = $convert( '<section><ul>' . $mega . $flat . '</ul></section>' );
$expect( 'a list anywhere but a header\'s menu is converted as it was (a card grid in list items)', ( $names( $section )['core/list-item'] ?? 0 ) === 3 && ! isset( $names( $section )['dxai-ui/box'] ), wp_json_encode( $names( $section ) ) );
$footer = $convert( '<footer><nav><ul>' . $mega . $flat . '</ul></nav></footer>' );
$expect( 'a footer\'s link columns are not touched (their own reader takes them)', ( $names( $footer )['core/list-item'] ?? 0 ) === 3, wp_json_encode( $names( $footer ) ) );
$simple = $convert( $bar( '<li><a href="#a">A</a></li><li><a href="#b"><span>B</span><span class="badge">new</span></a></li><li><a href="#c"><svg width="8" height="8"></svg>C</a></li>' ) );
$expect( 'rows that are only a link (an icon, a badge in it) stay list items', ( $names( $simple )['core/list-item'] ?? 0 ) === 3, wp_json_encode( $names( $simple ) ) );
$nested = $convert( $bar( '<li><a href="#a">About</a><ul><li><a href="#b">Team</a></li><li><a href="#c">Press</a></li></ul></li><li><a href="#d">Blog</a></li>' ) );
$nrows = $rows_of( $nested );
$expect( 'a row with a nested list is blocks too (the classic dropdown): a box holding the link and the list; the plain row after it stays a list item', 2 === count( $nrows ) && 'core/list-item' !== $nrows[0]['blockName'] && 'core/list-item' === $nrows[1]['blockName'] && 2 === count( (array) $nrows[0]['innerBlocks'] ), wp_json_encode( array_column( $nrows, 'blockName' ) ) );

echo "\nThe reader\n";
$spec  = Header_Template::build( $markup );
$check = Header_Menus::verify( $spec );
$expect( 'every link of the navigation is read (none is left out of a slot)', 0 === (int) ( $spec['checks']['unread_links'] ?? -1 ), wp_json_encode( $spec['checks'] ?? null ) );
$expect( 'the navigation has its three items: Services, Reviews, FAQ', 3 === (int) ( $spec['checks']['nav_items'] ?? 0 ) );
$expect( 'and the design\'s items render the design\'s header byte for byte (verify)', $check['ok'], $check['reason'] . ' at ' . $check['at'] );
$tree = $spec['trees'][ Header_Template::NAV ] ?? array();
$cols = (array) ( $tree[0]['children'] ?? array() );
$expect( 'Services has the two columns and the call link', 3 === count( $cols ) && 'Emergency cleanup' === ( $cols[0]['label'] ?? '' ) && 'Restore and rebuild' === ( $cols[1]['label'] ?? '' ), wp_json_encode( array_column( $cols, 'label' ) ) );
$first = (array) ( $cols[0]['children'][0] ?? array() );
$expect( 'a link\'s title and its description are two things', 'Water Damage' === ( $first['label'] ?? '' ) && 'Burst pipes, flooded basements' === ( $first['description'] ?? '' ), wp_json_encode( $first ) );
$expect( 'its address is the link\'s', '/service/water/' === ( $first['url'] ?? '' ) );
$call = (array) ( $cols[2] ?? array() );
$expect( 'the call link\'s second line is its description', 'Not sure?' === ( $call['label'] ?? '' ) && '(555) 0100' === ( $call['description'] ?? '' ), wp_json_encode( $call ) );
$expect( 'a plain link has none', '' === (string) ( $tree[1]['description'] ?? 'x' ) && 'Reviews' === ( $tree[1]['label'] ?? '' ) );
$expect( 'the logo link is a logo, not a menu item or a button', 1 === count( (array) $spec['logos'] ) && ! isset( $spec['trees'][ Header_Template::ACTIONS ] ), wp_json_encode( array_keys( (array) $spec['trees'] ) ) );

$read = static function ( string $html ) use ( $convert, $bar ): array {
	$s = Header_Template::build( $convert( $bar( '<li><a href="#x">One</a></li><li><a href="#y">Two</a></li><li>' . $html . '</li>' ) ) );

	return (array) ( $s['trees'][ Header_Template::NAV ][2] ?? array() );
};
$arrow = $read( '<a href="#z"><span>Contact</span><span aria-hidden="true">→</span></a>' );
$expect( 'an arrow beside a label is not a description', 'Contact' === ( $arrow['label'] ?? '' ) && '' === (string) ( $arrow['description'] ?? '' ), wp_json_encode( $arrow ) );
$styled = $read( '<a href="#z">Call <b>Now</b></a>' );
$expect( 'a label with a styled word in it is one label', 'Call Now' === ( $styled['label'] ?? '' ) && '' === (string) ( $styled['description'] ?? '' ), wp_json_encode( $styled ) );
$all = Header_Template::build( $convert( $bar( '<li><a href="#x">X</a></li><li><a href="#y">Y</a></li><li class="relative"><button class="dxai-toggle-open--m" type="button" data-dxai-hover="enter">More</button><div class="dxai-on-open--m hidden"><div><p>Group</p><ul><li><a href="#a">Alpha</a></li><li><a href="#b">Beta</a></li></ul></div><a href="/all/" data-dxai-set="null"><span>See all</span><span>→</span><span>Every service</span></a></div></li>' ) ) );
$seeall = (array) ( $all['trees'][ Header_Template::NAV ][2]['children'][1] ?? array() );
$expect( 'in a link of blocks the description is the next words, not an arrow between them', 'See all' === ( $seeall['label'] ?? '' ) && 'Every service' === ( $seeall['description'] ?? '' ), wp_json_encode( $seeall ) );
$column = $read( '<a href="#z"><i class="icon"></i><div><span>Car Accidents</span><span>Auto collisions, hit and run</span></div></a>' );
$expect( 'a title and a description in a column of their own beside an icon are told apart', 'Car Accidents' === ( $column['label'] ?? '' ) && 'Auto collisions, hit and run' === ( $column['description'] ?? '' ), wp_json_encode( $column ) );

// A trigger the design never drew a panel for (a button in a list item) is still an item of the bar; in a panel, a run of words is bullet points.
$trigger = Header_Template::build( $convert( $bar( '<li><a href="#a">A</a></li><li class="relative"><button class="dxai-toggle-open" type="button">Locations</button></li><li><a href="#b">B</a></li>' ) ) );
$expect( 'a trigger with no panel does not end the navigation: the items after it are read', array( 'A', 'Locations', 'B' ) === array_column( (array) ( $trigger['trees'][ Header_Template::NAV ] ?? array() ), 'label' ) && 0 === (int) ( $trigger['checks']['unread_links'] ?? -1 ), wp_json_encode( $trigger['trees'][ Header_Template::NAV ] ?? null ) );
$promo = Header_Template::build(
	$convert(
		$bar(
			'<li><a href="#a">A</a></li><li><a href="#b">B</a></li>'
			. '<li class="relative"><button class="dxai-toggle-open--pa" type="button" data-dxai-hover="enter">Practice</button><div class="dxai-on-open--pa hidden">'
			. '<div><p>Auto</p><ul><li><a href="#c">Cars</a></li><li><a href="#t">Trucks</a></li></ul></div>'
			. '<div><p>Other</p><ul><li><a href="#p">Premises</a></li><li><a href="#w">Wrongful</a></li></ul></div>'
			. '<div><p>Estimate</p><ul><li>Personalized estimate</li><li>Backed by case data</li><li>Takes a minute</li></ul></div>'
			. '</div></li>'
		)
	)
);
$practice = (array) ( $promo['trees'][ Header_Template::NAV ][2] ?? array() );
$expect( 'the bullet points of a promo in a panel are not its links: the panel\'s items are the two columns', array( 'Auto', 'Other' ) === array_column( (array) ( $practice['children'] ?? array() ), 'label' ), wp_json_encode( array_column( (array) ( $practice['children'] ?? array() ), 'label' ) ) );

// A logo that is a picture block draws its image at render time: the markup it saved has no <img>.
$picture = serialize_blocks(
	array(
		array(
			'blockName'    => 'core/group',
			'attrs'        => array( 'tagName' => 'header' ),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'dxai-ui/box',
					'attrs'        => array( 'tagName' => 'a', 'url' => '/', 'ariaLabel' => 'Acme home' ),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'dx/picture',
							'attrs'        => array( 'imageId' => 4242, 'imageUrl' => 'http://example.test/logo.png', 'imageAlt' => 'Acme', 'imageWidth' => 300, 'imageHeight' => 100 ),
							'innerBlocks'  => array(),
							'innerHTML'    => '',
							'innerContent' => array(),
						),
					),
					'innerHTML'    => '<a href="/" aria-label="Acme home"></a>',
					'innerContent' => array( '<a href="/" aria-label="Acme home">', null, '</a>' ),
				),
				array(
					'blockName'    => 'core/group',
					'attrs'        => array( 'tagName' => 'nav' ),
					'innerBlocks'  => array(
						array( 'blockName' => 'dxai-ui/link', 'attrs' => array( 'url' => '#a', 'text' => 'One' ), 'innerBlocks' => array(), 'innerHTML' => '<a class="wp-block-dxai-ui-link" href="#a">One</a>', 'innerContent' => array( '<a class="wp-block-dxai-ui-link" href="#a">One</a>' ) ),
						array( 'blockName' => 'dxai-ui/link', 'attrs' => array( 'url' => '#b', 'text' => 'Two' ), 'innerBlocks' => array(), 'innerHTML' => '<a class="wp-block-dxai-ui-link" href="#b">Two</a>', 'innerContent' => array( '<a class="wp-block-dxai-ui-link" href="#b">Two</a>' ) ),
					),
					'innerHTML'    => '<nav class="wp-block-group"></nav>',
					'innerContent' => array( '<nav class="wp-block-group">', null, null, '</nav>' ),
				),
			),
			'innerHTML'    => '<header class="wp-block-group"></header>',
			'innerContent' => array( '<header class="wp-block-group">', null, null, '</header>' ),
		),
	)
);
$pspec = Header_Template::build( $picture );
$expect( 'a link around a picture block, with no words, is the logo (it was a menu item, "home")', 1 === count( (array) $pspec['logos'] ) && ! isset( $pspec['trees'][ Header_Template::ACTIONS ] ) && ! isset( $pspec['trees'][ Header_Template::TOP ] ), wp_json_encode( array( $pspec['logos'], array_keys( (array) $pspec['trees'] ) ) ) );
$expect( '…and its image is the block\'s own (url, alt, attachment)', 'http://example.test/logo.png' === ( $pspec['logos'][0]['src'] ?? '' ) && 'Acme' === ( $pspec['logos'][0]['alt'] ?? '' ) && 4242 === (int) ( $pspec['logos'][0]['id'] ?? 0 ), wp_json_encode( $pspec['logos'] ) );
$site = ( new Header_Renderer( $pspec, array(), array( 'id' => 77, 'url' => 'http://example.test/site-logo.png', 'alt' => 'Site' ) ) )->root();
$mark = Header_Renderer::markup( $site );
$expect( 'the Site Logo is written into the picture block', str_contains( $mark, '"imageUrl":"http://example.test/site-logo.png"' ) && str_contains( $mark, '"imageId":77' ) && ! str_contains( $mark, '"imageWidth"' ), substr( $mark, 0, 300 ) );
$same = ( new Header_Renderer( $pspec, array(), array( 'id' => 4242, 'url' => 'http://example.test/logo.png', 'alt' => 'Acme' ) ) )->root();
$expect( 'and left as it is when the Site Logo is the design\'s own image', str_contains( Header_Renderer::markup( $same ), '"imageWidth":300' ) );

echo "\nThe renderer\n";
$fresh  = static fn(): array => Header_Menus::design_nodes( $tree );
$nodes  = $fresh();
$render = static fn( array $n ): string => ( new Header_Renderer( $spec, array( Header_Template::NAV => $n ), array() ) )->render();
$item   = static fn( string $title, string $url = '#', string $desc = '' ): object => (object) array(
	'ID'               => 0,
	'menu_item_parent' => 0,
	'title'            => $title,
	'url'              => $url,
	'target'           => '',
	'xfn'              => '',
	'attr_title'       => '',
	'description'      => $desc,
	'classes'          => array( '' ),
	'current'          => false,
);
$design = do_blocks( (string) $spec['markup'] );
$expect( 'the design\'s items draw the design\'s header exactly', $render( $nodes ) === $design );

$edit = $fresh();
$edit[0]['children'][0]['children'][0]['item']->title = 'Flood Damage';
$out = $render( $edit );
$expect( 'a renamed link shows its new title', str_contains( $out, '>Flood Damage<' ) && ! str_contains( $out, '>Water Damage<' ) );
$expect( '…with its description beside it, untouched', str_contains( $out, 'Burst pipes, flooded basements' ) );

$edit = $fresh();
$edit[0]['children'][0]['children'][0]['item']->description = 'Pipes and floods';
$out = $render( $edit );
$expect( 'a changed description shows, the old one is gone, the title stays', str_contains( $out, '>Pipes and floods<' ) && ! str_contains( $out, 'Burst pipes' ) && str_contains( $out, '>Water Damage<' ) );

$edit = $fresh();
$edit[0]['children'][0]['children'][0]['item']->description = '';
$out = $render( $edit );
$expect( 'an emptied description takes its element out, no empty line is left', ! str_contains( $out, 'Burst pipes' ) && str_contains( $out, '>Water Damage<' ) && substr_count( $out, '<span' ) === substr_count( $design, '<span' ) - 1, substr_count( $out, '<span' ) . ' vs ' . substr_count( $design, '<span' ) );

$edit                                 = $fresh();
$edit[0]['children'][1]['children'][] = array( 'item' => $item( 'Reconstruction', '/service/reconstruction/' ), 'children' => array() );
$out                                                  = $render( $edit );
$expect( 'a new link in a column is there', str_contains( $out, '>Reconstruction<' ) && str_contains( $out, '/service/reconstruction/' ) );
$expect( '…and does not carry the words of the link it was drawn from', substr_count( $out, 'Repairs through final paint' ) === 1 && substr_count( $out, 'Containment and remediation' ) === 1 );

$edit                                 = $fresh();
$edit[0]['children'][0]['children'][] = array( 'item' => $item( 'Storm Damage', '/service/storm/', 'Wind and hail' ), 'children' => array() );
$out                                   = $render( $edit );
$expect( 'a new link with a description shows both', str_contains( $out, '>Storm Damage<' ) && str_contains( $out, '>Wind and hail<' ) );

$edit   = $fresh();
$edit[] = array( 'item' => $item( 'Pricing', '/pricing/' ), 'children' => array() );
$out    = $render( $edit );
$expect( 'a new link in the bar is there, the design\'s are all still there', str_contains( $out, '>Pricing<' ) && str_contains( $out, '>Reviews<' ) && str_contains( $out, '>FAQ<' ) && str_contains( $out, 'Water Damage' ) );

$edit   = $fresh();
$edit[] = array(
	'item'     => $item( 'Resources' ),
	'children' => array(
		array( 'item' => $item( 'Insurance claims', '/insurance/', 'We bill your carrier' ), 'children' => array() ),
		array( 'item' => $item( 'Blog', '/blog/' ), 'children' => array() ),
	),
);
$out = $render( $edit );
preg_match_all( '/dxai-on-open--([A-Za-z0-9_-]+)/', $out, $states );
$expect( 'a new dropdown has its panel with its links', str_contains( $out, '>Resources<' ) && str_contains( $out, '/insurance/' ) && str_contains( $out, '>We bill your carrier<' ) && str_contains( $out, '/blog/' ) );
$expect( '…and a state of its own, so it never opens together with another', count( array_unique( $states[1] ) ) === 2 && in_array( 'svc', $states[1], true ), wp_json_encode( array_count_values( $states[1] ) ) );

$edit                   = $fresh();
$edit[0]['item']->title = 'Our Services';
$out                 = $render( $edit );
$expect( 'a renamed dropdown keeps its panel and its columns', str_contains( $out, '>Our Services<' ) && str_contains( $out, 'Emergency cleanup' ) && str_contains( $out, 'Water Damage' ) );

echo "\nLocations\n";
// A menu the install wrote for one location is the design's items for that slot; assigned to another location in Appearance > Menus it is
// not read there (the primary menu on "Header buttons" drew the navigation's items as call-to-action buttons over the logo).
$made_menus = array();
$fin_menus  = static function () use ( &$made_menus ): void {
	foreach ( $made_menus as $id ) {
		wp_delete_nav_menu( (int) $id );
	}
	$made_menus = array();
};
register_shutdown_function( $fin_menus );
$make_menu = static function ( string $name, string $for ) use ( &$made_menus ): int {
	$id = wp_create_nav_menu( $name . ' ' . substr( md5( (string) wp_rand() ), 0, 8 ) );
	$id = is_wp_error( $id ) ? 0 : (int) $id;
	if ( $id > 0 ) {
		$made_menus[] = $id;
		if ( $for !== '' ) {
			update_term_meta( $id, \DXAI_UI\Structures\Navigation_Factory::HEADER_MENU_META, $for );
		}
	}

	return $id;
};
$primary = $make_menu( 'Locations fixture primary', Header_Template::NAV );
$buttons = $make_menu( 'Locations fixture buttons', Header_Template::ACTIONS );
$mine    = $make_menu( 'Locations fixture own', '' );
$site    = array_merge(
	Header_Template::build( $markup ),
	array(
		'menus'  => array(
			Header_Template::NAV     => $primary,
			Header_Template::ACTIONS => $buttons,
		),
		'source' => array( 'scope_id' => 0 ),
	)
);
add_filter( 'pre_option_' . Header_Template::OPTION, static fn() => $site );
$assign = static function ( array $locations ): void {
	remove_all_filters( 'theme_mod_nav_menu_locations' );
	add_filter( 'theme_mod_nav_menu_locations', static fn() => $locations );
};
$assign(
	array(
		Header_Template::NAV     => $primary,
		Header_Template::ACTIONS => $primary,
		Header_Template::TOP     => $buttons,
	)
);
$expect( 'the site\'s header is the one the options hold', Header_Template::is_site( $site ) );
$expect( 'a location holding the menu written for it reads it', $primary === Header_Menus::menu_for( Header_Template::NAV, $site ) );
$expect( 'the navigation\'s menu assigned to the buttons\' location is not read there: the slot keeps the menu written for it', $buttons === Header_Menus::menu_for( Header_Template::ACTIONS, $site ), (string) Header_Menus::menu_for( Header_Template::ACTIONS, $site ) );
$expect( 'the buttons\' menu on a location the design has no menu for is not read there either: the design\'s own items stay', 0 === Header_Menus::menu_for( Header_Template::TOP, $site ) );
$assign(
	array(
		Header_Template::NAV     => $primary,
		Header_Template::ACTIONS => $mine,
	)
);
$expect( 'a menu of a person\'s own, assigned to a location, is read there', $mine === Header_Menus::menu_for( Header_Template::ACTIONS, $site ) );
$assign( array() );
$expect( 'a location with nothing assigned reads the menu the install wrote for it', $buttons === Header_Menus::menu_for( Header_Template::ACTIONS, $site ) );
remove_all_filters( 'theme_mod_nav_menu_locations' );
remove_all_filters( 'pre_option_' . Header_Template::OPTION );
$fin_menus();

echo "\nThe stack of a link, in Header_Html\n";
$expect( 'two elements with words are a stack', null !== Header_Html::parts( '<span>A</span><span>B</span>' ) );
$expect( 'one element is not', null === Header_Html::parts( '<span>A</span>' ) );
$expect( 'bare words beside an element are one label', null === Header_Html::parts( 'Call <span>Now</span> or <span>Later</span>' ) );
$expect( 'a glyph is not a part', null === Header_Html::parts( '<span>Go</span><span aria-hidden="true">→</span>' ) );
$expect( 'replace_part writes one element and leaves the rest', '<span>A</span> <span>X</span>' === Header_Html::replace_part( '<span>A</span> <span>B</span>', 1, 'X' ) );
$expect( 'and takes it out on null', '<span>A</span> ' === Header_Html::replace_part( '<span>A</span> <span>B</span>', 1, null ) );
$stack = Header_Html::stack( '<i></i><div><span>T</span><span>D</span></div>' );
$expect( 'a stack inside the one element that holds the words is found, with the way in', null !== $stack && 'T' === $stack['parts'][0]['text'] && 'D' === $stack['parts'][1]['text'] && 1 === count( $stack['via'] ), wp_json_encode( $stack ) );
$expect( '…and written through that wrapper', '<i></i><div><span>T</span><span>NEW</span></div>' === Header_Html::replace_part( '<i></i><div><span>T</span><span>D</span></div>', 1, 'NEW', (array) ( $stack['via'] ?? array() ) ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

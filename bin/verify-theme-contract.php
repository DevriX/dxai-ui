<?php
/**
 * The theme capability contract everywhere: the plugin asks Theme\Capabilities, and only the adapters know a theme's name.
 *
 *   bash bin/wp-php.sh bin/verify-theme-contract.php        (or: wp eval-file bin/verify-theme-contract.php --user=1)
 *
 * Phase 2в of docs/PLAN-ARCHITECTURE.md. Four adapters (Theme\Adapters: DX Base, a DX theme, a block theme, a classic theme) answer what
 * kind of theme the site has, which blocks of the theme's own stand in for a design's pictures, link boxes and labels, how those blocks are
 * written, and what the theme brings on its own pages; Capabilities reads the active theme through the first adapter that matches, and
 * every converter, reader and screen asks Capabilities. This suite checks the contract on the active theme, each adapter on its own (assumed
 * in place of the theme's), that the readers follow the contract and not a name, and — reading the plugin's own source — that no file
 * outside the adapters spells a theme's name or a theme's block. Nothing on the site is written; the adapter assumed for a check is put
 * back, in a shutdown function as well. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Library_View;
use DXAI_UI\Blocks\Native\Link_Box;
use DXAI_UI\Blocks\Native\Picture;
use DXAI_UI\Blocks\Native\Sizes;
use DXAI_UI\Blocks\Native\Span;
use DXAI_UI\Blocks\Native\Text_Color;
use DXAI_UI\Blocks\Youtube_Facade_View;
use DXAI_UI\Pages\Block_Tree;
use DXAI_UI\Pages\Section_Roles;
use DXAI_UI\Theme\Adapters\Amr_Adapter;
use DXAI_UI\Theme\Adapters\Block_Theme_Adapter;
use DXAI_UI\Theme\Adapters\Classic_Adapter;
use DXAI_UI\Theme\Adapters\Dx_Base_Adapter;
use DXAI_UI\Theme\Adapters\Theme_Adapter;
use DXAI_UI\Theme\Capabilities;
use DXAI_UI\Theme\Theme_Compat;
use DXAI_UI\Transfer\Page_Export;

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
register_shutdown_function( static function (): void {
	Capabilities::assume( null );
	remove_theme_support( 'dxai-youtube-facade' );
} );

echo "The contract on this theme\n";
$adapter = Capabilities::adapter();
$kind    = Theme_Compat::is_base_theme() ? 'dx-base' : ( Theme_Compat::is_dx_theme() ? 'amr' : ( Capabilities::is_block_theme() ? 'block' : 'classic' ) );
$expect( 'the active theme is read through the adapter of its kind (' . $kind . ')', $adapter instanceof Theme_Adapter && $adapter->id() === $kind && Capabilities::report()['theme']['adapter'] === $kind, $adapter->id() );
$expect( 'exactly one of the four adapters reads a theme, and the four are the four', 4 === count( Capabilities::adapters() ) && array( 'dx-base', 'amr', 'block', 'classic' ) === array_map( static fn( Theme_Adapter $a ): string => $a->id(), Capabilities::adapters() ) );
$expect( 'is_dx() is what Theme_Compat says, and the adapter of a DX theme says so too', Capabilities::is_dx() === Theme_Compat::is_dx_theme() && ( ! Capabilities::is_dx() || $adapter->is_dx() ) );
$picture = Capabilities::theme_block( 'picture' );
$expect( 'the picture block a theme names is first among the picture blocks, and the one to make is it when registered, else core\'s', $picture !== '' && Capabilities::blocks( 'picture' )[0] === $picture && Capabilities::block( 'picture' ) === ( Capabilities::has_block( $picture ) ? $picture : 'core/image' ) && Capabilities::picture_block() === Capabilities::block( 'picture' ), $picture . ' → ' . Capabilities::block( 'picture' ) );
$expect( 'the blocks that are pictures on a site: core\'s, the plugin\'s own, then the themes\'', array( 'core/image', 'dxai-ui/image' ) === array_slice( Capabilities::image_blocks(), 0, 2 ) && in_array( $picture, Capabilities::image_blocks(), true ) && Capabilities::is_block( $picture, 'picture' ) && ! Capabilities::is_block( 'core/image', 'picture' ) );
$span = Capabilities::theme_block( 'span' );
$expect( 'a theme\'s label block says how it is written (its tag and its own classes); a core block is no theme\'s', $span !== '' && is_array( Capabilities::markup_of( $span ) ) && 'span' === Capabilities::markup_of( $span )['tag'] && Capabilities::markup_of( $span )['classes'] !== array() && null === Capabilities::markup_of( 'core/paragraph' ), wp_json_encode( Capabilities::markup_of( $span ) ) );
$shape = Capabilities::picture_shape( $picture );
$expect( 'a theme\'s picture block says which attributes hold its image, and the phone\'s; core\'s Image has no such shape', isset( $shape['id'], $shape['url'], $shape['alt'], $shape['width'], $shape['height'] ) && is_array( $shape['mobile'] ) && $shape['mobile'] !== array() && array() === Capabilities::picture_shape( 'core/image' ), wp_json_encode( $shape ) );
$expect( 'the attributes that hold an attachment: a transfer learns the theme\'s from the contract', isset( Capabilities::media_id_attrs()[ $picture ] ) && in_array( $shape['id'], Capabilities::media_id_attrs()[ $picture ], true ) && isset( Page_Export::media_id_attrs()['core/image'], Page_Export::media_id_attrs()[ $picture ] ) );
$expect( 'a feature nobody declared is not brought; a theme support any theme declares makes it brought', ! Capabilities::brings( 'nothing-of-the-kind' ) && ( static function (): bool {
	Capabilities::assume( new Classic_Adapter() );
	$before = Capabilities::brings( 'video-facade' );
	add_theme_support( 'dxai-youtube-facade' );
	$with = Capabilities::brings( 'video-facade' );
	remove_theme_support( 'dxai-youtube-facade' );
	Capabilities::assume( null );

	return ! $before && $with;
} )() );

echo "\nEach adapter on its own\n";
$each = array( new Dx_Base_Adapter(), new Amr_Adapter(), new Block_Theme_Adapter(), new Classic_Adapter() );
foreach ( $each as $one ) {
	Capabilities::assume( $one );
	$blocks = $one->blocks();
	$expect( $one->id() . ': assumed, the contract reads the theme through it', Capabilities::adapter() === $one && Capabilities::report()['theme']['adapter'] === $one->id() );
	$expect( $one->id() . ': ' . ( $one->is_dx() ? 'a DX theme' : 'not a DX theme' ) . ', ' . ( $blocks === array() ? 'no blocks of its own' : 'blocks of its own for ' . implode( ', ', array_keys( $blocks ) ) ), ( $one->is_dx() === in_array( $one->id(), array( 'dx-base', 'amr' ), true ) ) && ( ( $blocks !== array() ) === ( 'amr' === $one->id() ) ) );
	$expect( $one->id() . ': the picture blocks of every theme are still recognised, and the converters\' target stays the theme block', in_array( $picture, Capabilities::blocks( 'picture' ), true ) && Capabilities::theme_block( 'picture' ) === $picture && Capabilities::theme_block( 'span' ) === $span, implode( ',', Capabilities::blocks( 'picture' ) ) );
	$expect( $one->id() . ': what it brings is what the contract says it brings, and nothing else', array_values( array_filter( array_keys( Capabilities::FEATURES ), array( Capabilities::class, 'brings' ) ) ) === array_values( array_intersect( array_keys( Capabilities::FEATURES ), $one->brings() ) ), wp_json_encode( $one->brings() ) );
}
Capabilities::assume( null );
$expect( 'the adapter of a kind of theme is the one that matches first: DX Base before a DX theme (it is one), a block theme before the rest', ( new Classic_Adapter() )->matches() && ( ! Theme_Compat::is_base_theme() || ( new Dx_Base_Adapter() )->matches() ) && ( ! ( new Amr_Adapter() )->matches() || Theme_Compat::is_dx_theme() ) );
// A theme whose adapter names a picture block the site does not register (a theme without the plugin that brings it, a typo in a
// child theme): the block to make is core's, the converters' target is still the theme's name — so the registry decides, not the name.
$unregistered = new class() extends Theme_Adapter {
	public function id(): string {
		return 'test';
	}
	public function matches(): bool {
		return true;
	}
	public function blocks(): array {
		return array( 'picture' => 'test/nothing-registers-this' );
	}
};
Capabilities::assume( $unregistered );
$expect( 'a theme block the site does not have is named (the converters\' target) but never made: the block to make is a registered one', 'test/nothing-registers-this' === Capabilities::theme_block( 'picture' ) && Capabilities::has_block( Capabilities::block( 'picture' ) ) && Capabilities::is_block( 'test/nothing-registers-this', 'picture' ) && Capabilities::is_block( $picture, 'picture' ), Capabilities::block( 'picture' ) );
Capabilities::assume( null );
// …and with no theme picture block registered at all, core's Image is made (the theme's block taken out of the registry for a moment).
$registry = \WP_Block_Type_Registry::get_instance();
$taken    = Capabilities::has_block( $picture ) ? $registry->get_registered( $picture ) : null;
if ( $taken instanceof \WP_Block_Type ) {
	$registry->unregister( $picture );
}
$without = Capabilities::block( 'picture' );
$named   = Capabilities::theme_block( 'picture' );
if ( $taken instanceof \WP_Block_Type ) {
	$registry->register( $taken );
}
$expect( 'with no theme picture block registered, core\'s Image is made and the theme\'s is still the name the converters target', 'core/image' === $without && $named === $picture && Capabilities::block( 'picture' ) === ( $taken instanceof \WP_Block_Type ? $picture : 'core/image' ), $without );

echo "\nThe readers ask the contract\n";
$expect( 'the converters make the theme\'s own blocks: a picture, a link box, a span', ( new Picture() )->target() === $picture && ( new Link_Box() )->target() === Capabilities::theme_block( 'link_box' ) && ( new Span() )->target() === $span );
$expect( 'the sizes pass reads the theme\'s blocks too', array() === array_diff( array( $picture, $span, Capabilities::theme_block( 'link_box' ) ), ( new Sizes() )->sources() ) );
$expect( 'the text-colour pass reads the theme\'s label block only where the site has it', in_array( $span, ( new Text_Color() )->sources(), true ) === Capabilities::has_block( $span ) );
Capabilities::assume( new Classic_Adapter() );
$plain_facade  = Youtube_Facade_View::theme_handles();
$plain_library = Library_View::theme_handles();
Capabilities::assume( new Amr_Adapter() );
$dx_facade  = Youtube_Facade_View::theme_handles();
$dx_library = Library_View::theme_handles();
Capabilities::assume( null );
$expect( 'the facade\'s and the library\'s styles are the theme\'s where its adapter brings them, the plugin\'s elsewhere', ! $plain_facade && ! $plain_library && $dx_facade && $dx_library, wp_json_encode( array( $plain_facade, $plain_library, $dx_facade, $dx_library ) ) );
$hero = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:' . $picture . ' {"' . $shape['id'] . '":1} /--><!-- wp:paragraph --><p>Words under a picture</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
$text = parse_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Words alone</p><!-- /wp:paragraph --></div><!-- /wp:group -->' );
$expect( 'a first section with the theme\'s picture behind its words is a hero (Section_Roles asks the contract for the picture block)', 'hero' === Section_Roles::of( $hero[0], true ) && 'hero' !== Section_Roles::of( $text[0], true ), Section_Roles::of( $hero[0], true ) . ' / ' . Section_Roles::of( $text[0], true ) );
$pic = parse_blocks( '<!-- wp:' . $picture . ' {"' . $shape['id'] . '":5,"' . $shape['url'] . '":"a.jpg","' . $shape['mobile'][0] . '":9} /-->' )[0];
Block_Tree::set_image( $pic, 'https://example.test/b.jpg', 'Another', 77 );
$expect( 'another image in the theme\'s picture block is set through its shape: the id and the address, the phone\'s picture dropped', 77 === ( $pic['attrs'][ $shape['id'] ] ?? 0 ) && 'https://example.test/b.jpg' === ( $pic['attrs'][ $shape['url'] ] ?? '' ) && 'Another' === ( $pic['attrs'][ $shape['alt'] ] ?? '' ) && ! isset( $pic['attrs'][ $shape['mobile'][0] ] ), wp_json_encode( $pic['attrs'] ) );

echo "\nNo file outside the adapters spells a theme's name or a theme's block\n";
/**
 * The theme names and theme block names found in the code of a PHP source (comments left out), with their lines.
 *
 * @return array<int, string>
 */
$spelled = static function ( string $code ): array {
	$found = array();
	foreach ( token_get_all( $code ) as $token ) {
		if ( ! is_array( $token ) || in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		if ( preg_match_all( '#american-restoration|dx/picture|amr/link-box|amr/span|[\'"]dx-base[\'"]#', (string) $token[1], $m ) ) {
			foreach ( $m[0] as $hit ) {
				$found[] = $hit . ' at line ' . $token[2];
			}
		}
	}

	return $found;
};
$allowed = array( 'src/Theme/Adapters/', 'src/Theme/Base_Theme.php' );
$hits    = array();
$files   = 0;
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( DXAI_UI_DIR . 'src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( ! $file instanceof SplFileInfo || $file->getExtension() !== 'php' ) {
		continue;
	}
	$rel = str_replace( '\\', '/', substr( (string) $file->getPathname(), strlen( DXAI_UI_DIR ) ) );
	$ok  = false;
	foreach ( $allowed as $prefix ) {
		if ( str_starts_with( $rel, $prefix ) ) {
			$ok = true;
		}
	}
	if ( $ok ) {
		continue;
	}
	++$files;
	foreach ( $spelled( (string) file_get_contents( (string) $file->getPathname() ) ) as $hit ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$hits[] = $rel . ': ' . $hit;
	}
}
$expect( 'in the ' . $files . ' source files outside the adapters, no theme name and no theme block in code (comments may say where a thing was measured)', $files > 100 && $hits === array(), implode( '; ', array_slice( $hits, 0, 8 ) ) );
$expect( '…and the reader would see one: a planted name in code is found, one in a comment is not', array( 'american-restoration at line 3', 'dx/picture at line 4' ) === $spelled( "<?php\n// american-restoration in a comment\n\$a = 'american-restoration';\n\$b = \"dx/picture\";\n/* amr/span in a block comment */\n" ) );
$expect( 'the adapters are where the names live: the DX theme\'s folder and its three blocks are spelled there', in_array( 'american-restoration', Amr_Adapter::SLUGS, true ) && array( 'picture', 'link_box', 'span' ) === array_keys( ( new Amr_Adapter() )->blocks() ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

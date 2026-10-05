<?php
/**
 * A YouTube video is added the team's way (the example the plugin follows): hidden embeds for the editor, a facade for the phone and for the
 * desktop, never a live player. Against the HTML converter, the markup an engine writes, and what the page prints.
 *
 *   bash bin/wp-php.sh bin/verify-video-facade.php        (or: wp eval-file bin/verify-video-facade.php --user=1)
 *
 * Makes nothing. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Blocks\Youtube_Facade_View;
use DXAI_UI\Compiler\Html_To_Blocks;
use DXAI_UI\Theme\Theme_Compat;
use DXAI_UI\Compiler\Prompt_Builder;
use DXAI_UI\Compiler\Video_Facade;

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

$ID    = 'dQw4w9WgXcQ';
$OTHER = '8Yb6Vk_c_Tw';

echo "Which address is a video\n";
$ids = array(
	'https://www.youtube.com/watch?v=' . $ID                                  => $ID,
	'https://youtube.com/watch?feature=share&v=' . $ID . '&t=42s'             => $ID,
	'https://m.youtube.com/watch?v=' . $ID                                    => $ID,
	'https://youtu.be/' . $ID . '?si=abc'                                     => $ID,
	'https://www.youtube.com/embed/' . $ID . '?rel=0&amp;autoplay=1'          => $ID,
	'//www.youtube.com/embed/' . $ID                                          => $ID,
	'https://www.youtube-nocookie.com/embed/' . $ID                           => $ID,
	'https://www.youtube.com/shorts/' . $ID                                   => $ID,
	'https://www.youtube.com/live/' . $ID . '?feature=share'                  => $ID,
	'https://www.youtube.com/v/' . $ID                                        => $ID,
	'https://www.youtube.com/embed/' . $OTHER                                 => $OTHER,
	'https://www.youtube.com/embed/videoseries?list=PL123456789012'           => '',
	'https://www.youtube.com/@channel'                                        => '',
	'https://www.youtube.com/watch?v=short'                                   => '',
	'https://vimeo.com/123456789'                                             => '',
	'https://www.google.com/maps/embed?pb=!1m18'                              => '',
	'https://notyoutube.com/watch?v=' . $ID                                   => '',
	'https://example.com/?u=https://www.youtube.com/watch?v=' . $ID           => '',
	''                                                                        => '',
);
$bad = array();
foreach ( $ids as $url => $want ) {
	if ( Video_Facade::youtube_id( (string) $url ) !== $want ) {
		$bad[] = $url . ' → ' . Video_Facade::youtube_id( (string) $url );
	}
}
$expect( 'the id comes from watch, youtu.be, embed, nocookie, shorts, live and v addresses; a playlist, a channel, another site or a short id has none', $bad === array(), implode( ' | ', $bad ) );

echo "\nThe example, with the video's id and title\n";
// The team's example, as it was given, with the one id (it had two) and the title put in.
$example = <<<'EX'
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=%%ID%%","type":"video","providerNameSlug":"youtube","responsive":true,"metadata":{"blockVisibility":false},"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio h-[190px] md-d-none","style":{"css":"border-radius: 8px !important; overflow: hidden;"}} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio h-[190px] md-d-none has-custom-css"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=%%ID%%
</div></figure>
<!-- /wp:embed -->
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=%%ID%%","type":"video","providerNameSlug":"youtube","responsive":true,"metadata":{"blockVisibility":false},"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio d-none md-d-block","style":{"css":"border-radius: 8px !important; overflow: hidden;"}} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio d-none md-d-block has-custom-css"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=%%ID%%
</div></figure>
<!-- /wp:embed -->
<!-- wp:group {"metadata":{"name":"Mobile iFrame"},"className":"youtube-embed mobile-show","style":{"css":"overflow: hidden;\nmin-height: 300px;","border":{"radius":{"topLeft":"0px","topRight":"0px","bottomLeft":"0px","bottomRight":"0px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group youtube-embed mobile-show has-custom-css" style="border-top-left-radius:0px;border-top-right-radius:0px;border-bottom-left-radius:0px;border-bottom-right-radius:0px"><!-- wp:html -->
<style data-wp-block-html="css">
.youtube-embed iframe {
min-height: 300px;
}
</style>
<div class="youtube-facade" data-video-id="%%ID%%" data-video-title="%%TITLE%%" style="position:relative;aspect-ratio:unset;background:#000 center/cover no-repeat; min-height: 300px;"></div>
<!-- /wp:html --></div>
<!-- /wp:group -->
<!-- wp:group {"metadata":{"name":"Desktop iFrame"},"className":"youtube-embed mobile-hide","style":{"css":"overflow: hidden;"},"layout":{"type":"constrained"}} -->
<div class="wp-block-group youtube-embed mobile-hide has-custom-css"><!-- wp:html -->
<style data-wp-block-html="css">
.youtube-embed iframe {
min-height: 300px;
}
</style>
<div class="youtube-facade" data-video-id="%%ID%%" data-video-title="%%TITLE%%" style="position:relative;aspect-ratio:16/9;background:#000 center/cover no-repeat;"></div>
<!-- /wp:html --></div>
<!-- /wp:group -->
EX;
$want = strtr( $example, array( '%%ID%%' => $ID, '%%TITLE%%' => 'Roof repair tour' ) );
$got  = Video_Facade::markup( $ID, 'Roof repair tour', true );
$expect( 'a video is the example, byte for byte, with the id in every place and the title in the facades', $got === $want );
$top = array_values( array_filter( parse_blocks( $got ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
$expect( 'it is four blocks: two embeds, then the mobile group and the desktop group', array_column( $top, 'blockName' ) === array( 'core/embed', 'core/embed', 'core/group', 'core/group' ) );
$expect( 'the embeds are hidden and carry the address; each group holds one HTML block', ( $top[0]['attrs']['metadata']['blockVisibility'] ?? null ) === false && ( $top[1]['attrs']['metadata']['blockVisibility'] ?? null ) === false && $top[0]['attrs']['url'] === 'https://www.youtube.com/watch?v=' . $ID && count( $top[2]['innerBlocks'] ) === 1 && $top[2]['innerBlocks'][0]['blockName'] === 'core/html' && count( $top[3]['innerBlocks'] ) === 1 );
$expect( 'the blocks serialise to the same markup (nothing the editor would rewrite)', trim( serialize_blocks( parse_blocks( $got ) ) ) === trim( $got ) );
$expect( 'a title with quotes and tags is only text in the attribute', ! str_contains( Video_Facade::markup( $ID, 'He said "go" <script>x</script>', true ), '<script>x' ) && str_contains( Video_Facade::markup( $ID, 'He said "go"', true ), 'data-video-title="He said &quot;go&quot;"' ) );
$expect( 'a video with no title is called what it is', str_contains( Video_Facade::markup( $ID, '   ', true ), 'data-video-title="YouTube video"' ) );
$off = Video_Facade::markup( $ID, 'x', false );
$expect( 'without the hidden embeds a video is its two facades', count( array_filter( parse_blocks( $off ), static fn( $b ) => ! empty( $b['blockName'] ) ) ) === 2 && ! str_contains( $off, 'wp:embed' ) );
add_filter( 'dxai_ui_video_hidden_embeds', '__return_false' );
$expect( 'a WordPress that does not hide blocks (before 6.9) gets no embeds: they would be printed as live players', ! str_contains( Video_Facade::markup( $ID, 'x' ), 'wp:embed' ) && ! Video_Facade::hides_blocks() );
remove_all_filters( 'dxai_ui_video_hidden_embeds' );
$expect( 'and this one hides them', Video_Facade::hides_blocks() === version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) );

echo "\nWhat the page prints\n";
$html = do_blocks( Video_Facade::markup( $ID, 'Roof repair tour', true ) );
$expect( 'no player: nothing of YouTube is loaded until the facade is pressed', ! str_contains( $html, '<iframe' ) && ! str_contains( $html, 'youtube.com/embed' ) && ! str_contains( $html, 'youtube-nocookie' ) );
$expect( 'the hidden embeds print nothing', ! str_contains( $html, 'wp-block-embed' ) );
$expect( 'the facade is there for the phone and for the desktop, with the id and the title', substr_count( $html, 'class="youtube-facade"' ) === 2 && substr_count( $html, 'data-video-id="' . $ID . '"' ) === 2 && substr_count( $html, 'data-video-title="Roof repair tour"' ) === 2 && str_contains( $html, 'mobile-show' ) && str_contains( $html, 'mobile-hide' ) );

echo "\nAn iframe of a design\n";
$convert = static fn( string $h ): string => ( new Html_To_Blocks( '' ) )->convert( $h );
$page    = '<section class="video-band"><h2>See us at work</h2><div class="ratio"><iframe src="https://www.youtube.com/embed/' . $ID . '?rel=0" title="Roof repair tour" width="560" height="315" allowfullscreen></iframe></div></section>';
$out     = $convert( $page );
$expect( 'a YouTube iframe becomes the example, with its title', str_contains( $out, 'data-video-id="' . $ID . '"' ) && str_contains( $out, 'data-video-title="Roof repair tour"' ) && substr_count( $out, 'class="youtube-facade"' ) === 2 );
$expect( 'and is no longer an iframe', ! str_contains( $out, '<iframe' ) );
$expect( 'the words around it are kept', str_contains( $out, 'See us at work' ) );
$expect( 'what it makes parses back to the same markup', trim( serialize_blocks( parse_blocks( $out ) ) ) === trim( $out ) );
$two = $convert( '<div><iframe src="https://www.youtube-nocookie.com/embed/' . $ID . '"></iframe></div><div><iframe data-src="https://youtu.be/' . $OTHER . '" aria-label="Second one"></iframe></div>' );
$expect( 'each video is its own: the address of a nocookie iframe, the data-src of a lazy one, the label for a title', str_contains( $two, 'data-video-id="' . $ID . '"' ) && str_contains( $two, 'data-video-id="' . $OTHER . '"' ) && str_contains( $two, 'data-video-title="Second one"' ) && str_contains( $two, 'data-video-title="YouTube video"' ) && substr_count( $two, 'class="youtube-facade"' ) === 4 );
$same = $convert( '<div><iframe src="https://www.youtube.com/embed/' . $ID . '"></iframe></div><div><iframe src="https://www.youtube.com/embed/' . $ID . '"></iframe></div>' );
$expect( 'the same video twice on a page is two videos', substr_count( $same, 'class="youtube-facade"' ) === 4 );
$map = $convert( '<div><iframe src="https://www.google.com/maps/embed?pb=!1m18" width="600" height="450"></iframe></div>' );
$expect( 'a map is not a video: its iframe is as the design wrote it', str_contains( $map, '<iframe' ) && str_contains( $map, 'google.com/maps' ) && ! str_contains( $map, 'youtube-facade' ) );
$list = $convert( '<div><iframe src="https://www.youtube.com/embed/videoseries?list=PL123456789012"></iframe></div>' );
$expect( 'a playlist is not one video: left as it is', str_contains( $list, '<iframe' ) && ! str_contains( $list, 'youtube-facade' ) );
$file = $convert( '<div><video src="/tour.mp4" controls></video></div>' );
$expect( 'a video file is as the design wrote it', str_contains( $file, '<video' ) && ! str_contains( $file, 'youtube-facade' ) );

echo "\nThe markup an engine writes\n";
$engine = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Watch</h2><!-- /wp:heading -->'
	. '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=' . $ID . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->'
	. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=' . $ID . '</div></figure><!-- /wp:embed -->'
	. '<!-- wp:html --><iframe src="https://www.youtube.com/embed/' . $OTHER . '" title="Our story" width="560" height="315"></iframe><!-- /wp:html -->'
	. '<!-- wp:embed {"url":"https://vimeo.com/123456789","type":"video","providerNameSlug":"vimeo","responsive":true} --><figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo"><div class="wp-block-embed__wrapper">https://vimeo.com/123456789</div></figure><!-- /wp:embed -->'
	. '<!-- wp:html --><div class="map"><iframe src="https://www.google.com/maps/embed?pb=1"></iframe></div><!-- /wp:html --></div><!-- /wp:group -->';
$fixed = Video_Facade::rewrite( $engine );
$expect( 'a YouTube embed block and an HTML block that is only a YouTube iframe become the example', substr_count( $fixed, 'class="youtube-facade"' ) === 4 && str_contains( $fixed, 'data-video-id="' . $ID . '"' ) && str_contains( $fixed, 'data-video-id="' . $OTHER . '"' ) && str_contains( $fixed, 'data-video-title="Our story"' ) );
$expect( 'no live player is left', ! preg_match( '#<iframe[^>]+youtube#i', $fixed ) && ! str_contains( do_blocks( $fixed ), 'youtube.com/embed' ) );
$expect( 'a Vimeo embed, a map and the rest of the page are as they were', str_contains( $fixed, 'vimeo.com/123456789' ) && str_contains( $fixed, 'google.com/maps/embed' ) && str_contains( $fixed, 'Watch' ) );
$expect( 'run again it changes nothing (the example\'s hidden embeds are left as they are)', Video_Facade::rewrite( $fixed ) === $fixed );
$expect( 'markup with no video is the same string', Video_Facade::rewrite( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ) === '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );
$expect( 'and so is a page whose iframe is mixed with other markup (it cannot be told apart safely)', str_contains( Video_Facade::rewrite( '<!-- wp:html --><div><iframe src="https://www.youtube.com/embed/' . $ID . '"></iframe><p>x</p></div><!-- /wp:html -->' ), '<iframe' ) );

echo "\nThe script and styles, where the theme does not bring them\n";
$H = Youtube_Facade_View::HANDLE;
$expect( 'the plugin has its own copy of the facade\'s script and styles, registered', file_exists( DXAI_UI_DIR . 'assets/js/youtube-facade.js' ) && file_exists( DXAI_UI_DIR . 'assets/css/youtube-facade.css' ) && wp_script_is( $H, 'registered' ) && wp_style_is( $H, 'registered' ) );
$off = static function () use ( $H ): void {
	wp_dequeue_script( $H );
	wp_dequeue_style( $H );
};
$on  = static fn(): bool => wp_script_is( $H, 'enqueued' ) && wp_style_is( $H, 'enqueued' );
$facade_block = Video_Facade::markup( $ID, 'Roof repair tour', true );

add_filter( 'dxai_ui_theme_has_youtube_facade', '__return_false' );
$off();
do_blocks( $facade_block );
$expect( 'a page with a facade, on a theme that does not bring the script: both are printed (the script in the footer)', $on() && (int) wp_scripts()->get_data( $H, 'group' ) === 1 );
$off();
do_blocks( '<!-- wp:html --><p>No video here</p><!-- /wp:html -->' );
$expect( 'a page without a facade asks for nothing', ! wp_script_is( $H, 'enqueued' ) && ! wp_style_is( $H, 'enqueued' ) );
$off();
do_blocks( '<!-- wp:paragraph --><p>youtube-facade is only a word here</p><!-- /wp:paragraph -->' );
$expect( 'and neither does a block that is not an HTML block', ! wp_script_is( $H, 'enqueued' ) );
remove_all_filters( 'dxai_ui_theme_has_youtube_facade' );

add_filter( 'dxai_ui_theme_has_youtube_facade', '__return_true' );
$off();
do_blocks( $facade_block );
$expect( 'on a page whose theme brings its own, nothing of the plugin\'s is printed', ! wp_script_is( $H, 'enqueued' ) && ! wp_style_is( $H, 'enqueued' ) );
remove_all_filters( 'dxai_ui_theme_has_youtube_facade' );

$set_theme = static function ( string $name ): void {
	add_filter( 'template', static fn() => $name, 99 );
	add_filter( 'stylesheet', static fn() => $name, 99 );
};
$set_theme( 'american-restoration' );
$expect( 'the American Restoration theme brings it on its own pages', Youtube_Facade_View::theme_handles() );
remove_all_filters( 'template' );
remove_all_filters( 'stylesheet' );
$set_theme( 'twentytwentyfive' );
$expect( 'another theme does not', ! Youtube_Facade_View::theme_handles() );
remove_all_filters( 'template' );
remove_all_filters( 'stylesheet' );

// A converted page: the theme's scripts are detached from it, so the plugin's are printed even on the American Restoration theme.
$conv = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Fixture Video Page', 'post_content' => '' ) );
update_post_meta( $conv, '_wp_page_template', \DXAI_UI\Theme\Blank_Template::SLUG );
global $wp_query, $wp_the_query, $post;
$saved        = array( $wp_query, $wp_the_query, $post );
$wp_query     = new WP_Query( array( 'page_id' => $conv, 'post_type' => 'page', 'post_status' => 'any' ) );
$wp_the_query = $wp_query;
$post         = get_post( $conv );
$set_theme( 'american-restoration' );
$expect( 'the fixture is a converted page', Theme_Compat::is_converted_page() );
$expect( 'on a converted page the theme does not bring it, whatever the theme', ! Youtube_Facade_View::theme_handles() );
$off();
do_blocks( $facade_block );
$expect( 'so the plugin prints its own there', $on() );
$off();
remove_all_filters( 'template' );
remove_all_filters( 'stylesheet' );
[ $wp_query, $wp_the_query, $post ] = $saved;
wp_delete_post( (int) $conv, true );

$js  = (string) file_get_contents( DXAI_UI_DIR . 'assets/js/youtube-facade.js' );
$css = (string) file_get_contents( DXAI_UI_DIR . 'assets/css/youtube-facade.css' );
$expect( 'the script loads the player from youtube-nocookie.com only when pressed, and builds the thumbnail and the play button the theme\'s does', str_contains( $js, 'youtube-nocookie.com/embed/' ) && str_contains( $js, 'youtube-facade__thumb' ) && str_contains( $js, 'youtube-facade__play' ) && str_contains( $js, "addEventListener( 'click'" ) && str_contains( $js, "'Enter'" ) );
$expect( 'the styles show one facade on a phone and the other elsewhere, at 768px as the theme does', str_contains( $css, '.youtube-embed.mobile-show' ) && str_contains( $css, '.youtube-embed.mobile-hide' ) && str_contains( $css, 'max-width: 48rem' ) );

echo "\nWhat an engine is told\n";
$rules = new ReflectionMethod( Prompt_Builder::class, 'rules' );
$rules->setAccessible( true );
$text = (string) $rules->invoke( null );
$expect( 'a YouTube video is never a live iframe: an embed of its address, which the plugin makes a facade', str_contains( $text, 'YouTube video is never a live iframe' ) && str_contains( $text, 'core/video' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

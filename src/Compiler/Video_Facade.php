<?php
/**
 * A YouTube video, added the way the team adds one: a facade, not a live player.
 *
 * @package DXAI_UI\Compiler
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

/**
 * A YouTube iframe on a page loads a player, its scripts and its trackers whether anyone watches the video or not, and that is
 * a page-speed bill paid by every visitor. The team's pattern (the example the plugin follows to the letter) puts a facade where
 * the player would be: a box with the video's thumbnail and a play button, drawn by the theme's own script, which swaps in the
 * player (youtube-nocookie.com) when a visitor presses it.
 *
 * One video is four blocks, as the example has them:
 *
 *  - two `core/embed` blocks with the video's address, one for the phone and one for the desktop, **hidden** (block visibility:
 *    nothing is printed on the page; they are what the editor shows, so the address can be changed there);
 *  - a group "Mobile iFrame" and a group "Desktop iFrame", each holding a `core/html` block with the facade
 *    (`<div class="youtube-facade" data-video-id data-video-title>`) and the rule that gives the player its height; the theme shows
 *    one on a phone (`mobile-show`) and the other elsewhere (`mobile-hide`).
 *
 * Only the video's id and its title change from one video to the next. A WordPress that does not know block visibility (before
 * 6.9) would print the two embeds as live players, so there they are left out and the facades are all there is.
 *
 * Used by Html_To_Blocks (an iframe of a design) and by the markup an AI engine writes (rewrite()).
 */
final class Video_Facade {

	/** The example, with the video's id and title left to be filled in. Nothing else is changed from one video to the next. */
	private const EXAMPLE_EMBED_MOBILE = <<<'BLOCK'
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=%%ID%%","type":"video","providerNameSlug":"youtube","responsive":true,"metadata":{"blockVisibility":false},"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio h-[190px] md-d-none","style":{"css":"border-radius: 8px !important; overflow: hidden;"}} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio h-[190px] md-d-none has-custom-css"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=%%ID%%
</div></figure>
<!-- /wp:embed -->
BLOCK;

	private const EXAMPLE_EMBED_DESKTOP = <<<'BLOCK'
<!-- wp:embed {"url":"https://www.youtube.com/watch?v=%%ID%%","type":"video","providerNameSlug":"youtube","responsive":true,"metadata":{"blockVisibility":false},"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio d-none md-d-block","style":{"css":"border-radius: 8px !important; overflow: hidden;"}} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio d-none md-d-block has-custom-css"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=%%ID%%
</div></figure>
<!-- /wp:embed -->
BLOCK;

	private const EXAMPLE_MOBILE = <<<'BLOCK'
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
BLOCK;

	private const EXAMPLE_DESKTOP = <<<'BLOCK'
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
BLOCK;

	/** What a video is called when nothing on the page says. */
	private const DEFAULT_TITLE = 'YouTube video';

	/**
	 * The id of a YouTube video, from any address that names one: watch?v=, youtu.be/, /embed/, /shorts/, /live/, /v/, also on
	 * youtube-nocookie.com and m.youtube.com. A playlist, a channel or a page that is not a video has none.
	 */
	public static function youtube_id( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host  = (string) preg_replace( '/^(www|m|music)\./', '', $host );
		$path  = (string) ( $parts['path'] ?? '' );
		$id    = '';
		if ( $host === 'youtu.be' ) {
			$id = ltrim( $path, '/' );
		} elseif ( in_array( $host, array( 'youtube.com', 'youtube-nocookie.com' ), true ) ) {
			if ( preg_match( '#^/(?:embed|shorts|live|v)/([^/?&\#]+)#', $path, $m ) === 1 ) {
				$id = $m[1];
			} elseif ( $path === '/watch' ) {
				parse_str( (string) ( $parts['query'] ?? '' ), $query );
				$id = (string) ( $query['v'] ?? '' );
			}
		}

		// An id is eleven characters; "videoseries", the playlist embed's, is eleven characters too and is not one.
		return preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) === 1 && $id !== 'videoseries' ? $id : '';
	}

	/** Whether this WordPress hides a block that says so (block visibility, 6.9): the example's embeds rely on it. */
	public static function hides_blocks(): bool {
		$can = version_compare( (string) get_bloginfo( 'version' ), '6.9', '>=' );

		/**
		 * Whether the two hidden embeds of the example are written. Off, a video is its two facades alone.
		 *
		 * @param bool $can This WordPress knows block visibility.
		 */
		return (bool) apply_filters( 'dxai_ui_video_hidden_embeds', $can );
	}

	/**
	 * One video as block markup: the example, with this video's id and title.
	 *
	 * @param string    $id    The YouTube id.
	 * @param string    $title What the video is called (the facade says it to a screen reader).
	 * @param bool|null $embeds Write the two hidden embeds (null: when this WordPress hides them).
	 */
	public static function markup( string $id, string $title = '', ?bool $embeds = null ): string {
		$title  = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );
		$title  = $title === '' ? self::DEFAULT_TITLE : $title;
		$embeds = $embeds ?? self::hides_blocks();
		$parts  = $embeds ? array( self::EXAMPLE_EMBED_MOBILE, self::EXAMPLE_EMBED_DESKTOP ) : array();
		$parts  = array_merge( $parts, array( self::EXAMPLE_MOBILE, self::EXAMPLE_DESKTOP ) );
		$fill   = array(
			'%%ID%%'    => $id,
			// In an attribute, and in a block comment's JSON it would be escaped as well: the title is only in the markup.
			'%%TITLE%%' => esc_attr( $title ),
		);

		return strtr( implode( "\n", $parts ), $fill );
	}

	/**
	 * The same as block arrays, to put in a tree.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function blocks( string $id, string $title = '', ?bool $embeds = null ): array {
		return array_values( array_filter( parse_blocks( self::markup( $id, $title, $embeds ) ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
	}

	/** The id an iframe plays, from its address (src, or data-src of one loaded later), or ''. */
	public static function iframe_id( \DOMElement $iframe ): string {
		foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
			$id = self::youtube_id( (string) $iframe->getAttribute( $attr ) );
			if ( $id !== '' ) {
				return $id;
			}
		}

		return '';
	}

	/** What an iframe says it is: its title, else its label. */
	public static function iframe_title( \DOMElement $iframe ): string {
		foreach ( array( 'title', 'aria-label' ) as $attr ) {
			$title = trim( (string) $iframe->getAttribute( $attr ) );
			if ( $title !== '' ) {
				return $title;
			}
		}

		return '';
	}

	/**
	 * The markup an engine (or an earlier import) wrote, with every YouTube player in it made the team's way: a YouTube embed block, and
	 * an HTML block that is only a YouTube iframe, become the example. The example's own embeds are hidden and are left as they are,
	 * so this can be run again.
	 */
	public static function rewrite( string $markup ): string {
		if ( ! preg_match( '/youtu\.?be/i', $markup ) || ! str_contains( $markup, '<!-- wp:' ) ) {
			return $markup;
		}
		// An embed block: its address is in the comment. The example's own embeds are hidden and are left as they are.
		$markup = (string) preg_replace_callback(
			'#<!-- wp:embed (\{.*?\}) -->.*?<!-- /wp:embed -->#s',
			static function ( array $m ): string {
				$attrs = json_decode( $m[1], true );
				if ( ! is_array( $attrs ) || ( $attrs['metadata']['blockVisibility'] ?? null ) === false ) {
					return $m[0];
				}
				$id = self::youtube_id( (string) ( $attrs['url'] ?? '' ) );

				return $id === '' ? $m[0] : self::markup( $id );
			},
			$markup
		);
		// A block that is nothing but a YouTube iframe (a raw HTML block of the design, or of an engine).
		$markup = (string) preg_replace_callback(
			'#<!-- wp:(html|dxai-ui/html)(?: \{[^\n]*?\})? -->\s*(<iframe\b[^>]*>\s*</iframe>)\s*<!-- /wp:\1 -->#s',
			static function ( array $m ): string {
				$doc = new \DOMDocument();
				$old = libxml_use_internal_errors( true );
				$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $m[2], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
				libxml_clear_errors();
				libxml_use_internal_errors( $old );
				$frame = $doc->getElementsByTagName( 'iframe' )->item( 0 );
				$id    = $frame instanceof \DOMElement ? self::iframe_id( $frame ) : '';

				return $id === '' ? $m[0] : self::markup( $id, self::iframe_title( $frame ) );
			},
			$markup
		);

		return $markup;
	}
}

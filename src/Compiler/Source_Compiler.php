<?php
/**
 * Compile a harvested source document to Gutenberg without an LLM.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

use DXAI_UI\Connectors\Html_Zip_Pages;
use DXAI_UI\Connectors\Site_Origin;
use DXAI_UI\Connectors\Source_Document;
use DXAI_UI\Verification\Design_Coverage;
use DXAI_UI\Media\Asset_Harvester;

final class Source_Compiler {

	private Rewrite_Policy $policy;

	/**
	 * Whether blog / form / slider / menu structures keep the design or become
	 * native dynamic blocks. Defaults to the site setting, which is fidelity.
	 */
	public function __construct( ?Rewrite_Policy $policy = null ) {
		$this->policy = $policy ?? Rewrite_Policy::from_options();
	}

	/**
	 * @return array{
	 *   block_title:string,
	 *   gutenberg_markup:string,
	 *   structures:array<int, array<string, mixed>>,
	 *   custom_css:string,
	 *   custom_js:string,
	 *   required_media:array<int, string>,
	 *   pages:array<int, array<string, mixed>>,
	 *   compiler:string
	 * }
	 */
	public function compile( Source_Document $source ): array {
		/*
		 * Start the compiler's loss report from empty for this design.
		 *
		 * Jsx_Compiler has reported everything it could not evaluate since it
		 * was written, and nothing ever read it — verified across src/, bin/
		 * and assets/, where the only matches outside Jsx_Compiler itself were
		 * comments. That made it the one mechanism able to see a JSX-stage loss
		 * and also the one nobody consulted: the geometry check cannot see that
		 * class of defect at all, because design-oracle.php builds its design
		 * side with this same compiler and a loss lands on both sides as 0px.
		 *
		 * So the report is reset here and carried out on the result below.
		 */
		Jsx_Compiler::reset_unevaluated();

		$payload = $source->payload;
		$harvest = is_array( $payload['harvest'] ?? null ) ? $payload['harvest'] : array();
		$raw_css = $this->stylesheet( $payload, $harvest );
		$css     = Design_Css::prepare( $raw_css );
		/*
		 * The fonts a page loads are exactly the fonts the design loads — the
		 * harvest's list, unchanged.
		 *
		 * This used to synthesise a Google Fonts stylesheet for any family the
		 * CSS merely NAMED when the harvest had found no font source, on the
		 * theory that a missing face is the worse failure. Market Insights Hub
		 * and ICP Segmentation showed the opposite: neither ships a <link>, an
		 * @import, an @font-face or a font file anywhere in its export, so the
		 * real build renders `font-family: Inter, …` in the browser's fallback
		 * face. We linked Inter anyway and every nav link came out 3% wider —
		 * 222 boxes off at 1280px, 5697px over three widths. The harvest reads
		 * every text file in the export, so "nothing found" is the design's
		 * own answer; and the compiled oracle mirrors whatever the page loads,
		 * which is why it could not see this either.
		 */
		$title   = $source->title !== '' ? $source->title : __( 'DXAI-UI Pattern', 'dxai-ui' );

		$pages = is_array( $payload['pages'] ?? null ) ? $payload['pages'] : array();
		if ( $pages === array() ) {
			$pages = $this->pages_from_payload( $payload, $title );
		}
		if ( $pages === array() ) {
			$pages = Html_Zip_Pages::from_sources( is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array() );
		}

		$compiled_pages = array();
		$primary        = array();
		$all_sources    = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();

		// A Claude Design export: one template, rendered by Dc_Renderer.
		if ( is_array( $payload['dc'] ?? null ) && $payload['dc'] !== array() ) {
			$primary = $this->compile_dc( $payload['dc'], $title, $css );
			$pages   = array();
		}

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$code = (string) ( $page['code'] ?? '' );
			$file = (string) ( $page['file'] ?? '' );
			if ( $code === '' && isset( $payload['components'][ $file ] ) ) {
				$code = (string) $payload['components'][ $file ];
			}
			if ( $code === '' && $file !== '' && isset( $all_sources[ $file ] ) ) {
				$code = (string) $all_sources[ $file ];
			}
			$page_title = (string) ( $page['title'] ?? $title );
			$slug       = Site_Origin::path_of( (string) ( $page['slug'] ?? Html_Zip_Pages::slug_from_path( $file ) ) );
			if ( Html_Zip_Pages::is_html_page( $file, $code ) ) {
				$row = $this->compile_html_document( $code, $all_sources, $page_title, $slug, $file );
			} else {
				$row = $this->compile_page(
					$code,
					$page_title,
					$slug,
					$harvest,
					$this->source_files( $payload ),
					$css
				);
			}
			if ( $row['structures'] === array() ) {
				continue;
			}
			if ( $primary === array() || ( ( $primary['slug'] ?? '' ) !== '/' && $slug === '/' ) ) {
				if ( $primary !== array() && ( $primary['slug'] ?? '/' ) !== '/' ) {
					$compiled_pages[] = $primary;
				}
				$primary = $row;
			} else {
				$compiled_pages[] = $row;
			}
		}

		if ( $primary === array() ) {
			$html = $this->compile_html_zip( $payload );
			if ( $html !== array() ) {
				$primary = $html;
			}
		}

		$structures = is_array( $primary['structures'] ?? null ) ? $primary['structures'] : array();
		$page_title = (string) ( $primary['block_title'] ?? $title );

		$media = array();
		foreach ( $harvest['images'] ?? array() as $image ) {
			if ( is_array( $image ) && ! empty( $image['url'] ) ) {
				$media[] = (string) $image['url'];
			}
		}

		$rewriter   = new Dynamic_Rewriter( $this->policy );
		$structures = $rewriter->rewrite_all( $structures );
		/*
		 * Hydrating turns designed article cards into a Query Loop, so it only
		 * runs when the blog structure was asked to become dynamic — and only
		 * structure by structure. A second pass over the assembled page used
		 * to rebuild it from its raw islands alone (or flatten it whole into
		 * raw HTML when it had none), dropping every native block, the site
		 * header and the forms with them.
		 */
		$blog_native = $this->policy->is_dynamic( array(), 'blog' );
		$seeded      = array();
		if ( $blog_native ) {
			$structures = $this->hydrate_blog_structures( $structures, $page_title, $seeded );
		}
		// Always assemble from native blocks (header/nav/footer/hero/…). Never
		// ship a single-page <!-- wp:html --> fidelity blob.
		$markup = $this->markup_from_structures( $structures );
		if ( $markup === '' ) {
			$markup = (string) ( $primary['gutenberg_markup'] ?? '' );
		}
		$markup = $this->scrub_artifacts( $markup );

		/*
		 * An HTML ZIP's page script is the page's own <script> list, in
		 * document order (scripts_from_html()). Every .js of the archive on top
		 * of it put a script the page loads in the file twice — a top-level
		 * `const` declared twice is a SyntaxError that stops the whole file,
		 * the motion runtime included — and added files the page never loads.
		 */
		$from_html = ! empty( $primary['from_html'] );
		$js        = (string) Motion_Runtime::should_attach( $markup . wp_json_encode( $structures ), $css );
		$js        = trim( $js . "\n" . (string) ( $primary['custom_js'] ?? '' ) . "\n" . ( $from_html ? '' : $this->source_javascript( $payload ) ) );

		$hover = trim( (string) ( $primary['hover_css'] ?? '' ) );
		if ( $hover !== '' ) {
			$css = trim( $css . "\n" . $hover );
		}

		foreach ( $compiled_pages as $i => $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_structures = is_array( $page['structures'] ?? null ) ? $page['structures'] : array();
			$page_structures = $rewriter->rewrite_all( $page_structures );
			if ( $blog_native ) {
				$page_structures = $this->hydrate_blog_structures( $page_structures, (string) ( $page['block_title'] ?? $page_title ), $seeded );
			}
			$page_markup                              = $this->markup_from_structures( $page_structures );
			$compiled_pages[ $i ]['structures']       = $page_structures;
			$compiled_pages[ $i ]['gutenberg_markup'] = $page_markup;
		}

		return array(
			'block_title'      => $page_title,
			'gutenberg_markup' => $markup,
			'structures'       => $structures,
			'custom_css'       => $css,
			'custom_js'        => $js,
			'required_media'   => $media,
			'pages'            => $compiled_pages,
			'compiler'         => 'source',
			'fidelity'         => false,
			'design_assets'    => $harvest,
			'wrapper_class'    => (string) ( $primary['wrapper_class'] ?? '' ),
			'wrapper_style'    => (string) ( $primary['wrapper_style'] ?? '' ),
			'slug'             => (string) ( $primary['slug'] ?? '/' ),
			// Kept unprepared so the Tailwind theme can still read @theme.
			'design_css_raw'   => $raw_css,
			/*
			 * A Tailwind v3 `tailwind.config.ts`, verbatim, for the designs
			 * that keep their tokens there instead of in a CSS `@theme` block.
			 * Travels beside the raw CSS because it is the other half of the
			 * same thing: on a v3 project the CSS declares `--primary` as bare
			 * HSL channels and only the config says which utility reads it.
			 */
			'tailwind_config'  => (string) ( $payload['tailwind_config'] ?? '' ),
			// The archive every page of this design came from — see the
			// connectors, which read it off the upload, and
			// Structure_Repository::save(), which records it on each page.
			'source_name'      => (string) ( $payload['source_name'] ?? '' ),
			'seeded_posts'     => $seeded,
			// Carried so the repository rewrites on the same terms the compiler
			// already used, instead of re-reading the option and disagreeing.
			'structure_mode'   => $this->policy->to_array(),
			'source_html'      => (string) ( $primary['source_html'] ?? '' ),
			// A design that never ran under Tailwind: no preflight, no isolate
			// reset. Set by the connectors whose format has no Tailwind.
			'static_html'      => ! empty( $payload['static_html'] ),
			/*
			 * Everything the compiler could not evaluate while producing this
			 * design, so a caller can gate on it. Each row is
			 * { kind, source, count } — see Jsx_Compiler::note_unevaluated().
			 *
			 * An empty list is not a promise that nothing was lost: it means
			 * nothing NOTICED. A silent wrong answer still reports nothing,
			 * which is why `Math.round(x)` evaluating to the string 'Math'
			 * shipped to a live page with this list empty. The fix for that
			 * class is to make each path report; this is what makes reporting
			 * worth anything.
			 */
			'unevaluated'      => array_merge( Jsx_Compiler::all_unevaluated(), $this->dc_notes ),
			/*
			 * How much of this design stayed raw HTML, by the element that gave
			 * up, as `[ tag => count ]`.
			 *
			 * A raw-HTML island renders exactly right, so the pixel oracle and
			 * block validity are both blind to it — and it is markup nobody can
			 * edit in the editor, which is the whole point of converting. The
			 * count belongs in the result for the same reason `unevaluated`
			 * does: a number nobody reports is a number nobody fixes.
			 */
			'islands'          => $this->islands,
			/*
			 * The two things only the design's own code can say: how many
			 * lucide icon elements it draws, and how many JSX event handlers it
			 * declares. Design_Coverage counts them against the page, and by
			 * then the archive is gone on the admin path — so they travel here,
			 * with the design, for the same reason the source HTML does.
			 */
			'source_signals'   => Design_Coverage::source_signals( $this->source_files( $payload ) ),
		);
	}

	/** @var array<string, int> Raw-HTML fallbacks emitted, by element. */
	private array $islands = array();

	/**
	 * Fold one converter's island tally into the compile's own.
	 *
	 * Every page and every section runs its own Html_To_Blocks, so the totals
	 * are summed per element rather than replaced.
	 */
	private function count_islands( Html_To_Blocks $converter ): void {
		foreach ( $converter->islands() as $tag => $count ) {
			$this->islands[ $tag ] = ( $this->islands[ $tag ] ?? 0 ) + (int) $count;
		}
	}

	/** @var array<int, array<string, mixed>> What Dc_Renderer could not evaluate. */
	private array $dc_notes = array();

	/**
	 * A Claude Design export, rendered and split into structures.
	 *
	 * The template's own wrapper `<div>` becomes the page wrapper (its style
	 * travels as wrapper_style); each landmark under it — header, sections,
	 * footer — becomes a structure, so the header and footer turn into the
	 * site's template parts exactly as a TSX design's do. The runtime
	 * markers the renderer needs at page level (which state a click outside
	 * resets, which follows scrolling) are written on the first structure's
	 * root, since the page wrapper itself is minted by the repository.
	 *
	 * @param array<string, mixed> $dc
	 * @return array<string, mixed>
	 */
	private function compile_dc( array $dc, string $title, string $design_css ): array {
		$logic    = new Dc_Script( (string) ( $dc['script'] ?? '' ), is_array( $dc['props'] ?? null ) ? $dc['props'] : array() );
		$renderer = new Dc_Renderer( $logic );
		$out      = $renderer->render( (string) ( $dc['template'] ?? '' ) );
		foreach ( $renderer->notes() as $note ) {
			$this->dc_notes[] = array( 'kind' => 'dc', 'source' => $note, 'count' => 1 );
		}
		if ( trim( $out['html'] ) === '' ) {
			return array();
		}

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $out['html'] . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array();
		}

		// Unwrap the template's single root <div>: it is the page.
		$wrapper_class = '';
		$wrapper_style = '';
		$root          = $body;
		$children      = array_values( array_filter( iterator_to_array( $body->childNodes ), static fn( $n ) => $n instanceof \DOMElement ) );
		if ( count( $children ) === 1 && $children[0] instanceof \DOMElement && strtolower( $children[0]->tagName ) === 'div' ) {
			$root          = $children[0];
			$wrapper_class = trim( (string) $root->getAttribute( 'class' ) );
			$wrapper_style = trim( (string) $root->getAttribute( 'style' ) );
		}

		$converter  = new Html_To_Blocks( $design_css );
		$structures = array();
		$source     = array();
		$first      = true;
		$saw_hero   = false;
		/*
		 * The renderer emits a landmark per viewport branch when the design
		 * writes one: H2O has a desktop `<header>` and a mobile `<header>`,
		 * each hidden by a media query on the other side of its breakpoint.
		 * The repository keeps ONE header template part, so consecutive
		 * landmarks of the same kind travel as one structure — both headers in
		 * the part, each still showing only at its own widths.
		 */
		$nodes = array_values( array_filter( iterator_to_array( $root->childNodes ), static fn( $n ) => $n instanceof \DOMElement ) );
		// A plain <main> is only a landmark around the bands: its bands become
		// structures of their own, so the page's sections are top-level blocks
		// rather than one group (Design_Html::is_plain_main()).
		$flat = array();
		foreach ( $nodes as $node ) {
			if ( Design_Html::is_plain_main( $node ) ) {
				foreach ( Design_Html::unwrap_main( $node ) as $child ) {
					$flat[] = $child;
				}
				continue;
			}
			$flat[] = $node;
		}
		$nodes  = $flat;
		$groups = array();
		foreach ( $nodes as $node ) {
			$tag  = strtolower( $node->tagName );
			$last = $groups === array() ? null : $groups[ count( $groups ) - 1 ];
			if ( $last !== null && in_array( $tag, array( 'header', 'footer' ), true ) && $last['tag'] === $tag ) {
				$groups[ count( $groups ) - 1 ]['nodes'][] = $node;
				continue;
			}
			$groups[] = array( 'tag' => $tag, 'nodes' => array( $node ) );
		}
		foreach ( $groups as $group ) {
			$node = $group['nodes'][0];
			if ( $first ) {
				foreach ( $out['root_attrs'] as $name => $value ) {
					$node->setAttribute( $name, $value );
				}
				$first = false;
			}
			$html = '';
			foreach ( $group['nodes'] as $member ) {
				$html .= (string) $dom->saveHTML( $member );
			}
			$tag  = $group['tag'];
			$type = match ( $tag ) {
				'header' => 'header',
				'footer' => 'footer',
				'nav'    => 'navigation',
				default  => $this->chrome_wrapper( $node ),
			};
			if ( $type === 'section' && ! $saw_hero && str_contains( $html, '<h1' ) ) {
				$type     = 'hero';
				$saw_hero = true;
			}
			if ( str_contains( $html, '<form' ) ) {
				$type = 'form';
			}
			$name = $node->hasAttribute( 'data-screen-label' ) ? (string) $node->getAttribute( 'data-screen-label' )
				: ( $node->hasAttribute( 'id' ) ? ucfirst( (string) $node->getAttribute( 'id' ) ) : ucfirst( $type ) );
			$structures[] = array(
				'type'             => $type,
				'title'            => $name,
				'gutenberg_markup' => $converter->convert( $html ),
				'menu_items'       => $this->menu_items( $html, $type ),
				'menu_tree'        => $this->menu_tree( $html, $type ),
				'form_fields'      => $type === 'form' ? $this->form_fields( $html ) : array(),
				'source_html'      => $html,
			);
			$source[] = $html;
		}
		if ( $structures === array() ) {
			return array();
		}

		$this->count_islands( $converter );

		return array(
			'block_title'      => \DXAI_UI\Support\Page_Title::for_home( (string) ( $dc['title'] ?? $title ) ),
			'seo_title'        => trim( (string) ( $dc['title'] ?? '' ) ),
			'gutenberg_markup' => '',
			'structures'       => $structures,
			'wrapper_class'    => $wrapper_class,
			'wrapper_style'    => $wrapper_style,
			'slug'             => '/',
			'source_html'      => implode( "\n", $source ),
			// The renderer's classes: media variants, state projections, pseudo-classes.
			'hover_css'        => $out['css'],
			'custom_js'        => '',
		);
	}

	public function can_compile( Source_Document $source ): bool {
		$payload = $source->payload;
		if ( ! empty( $payload['pages'] ) && is_array( $payload['pages'] ) ) {
			return true;
		}
		$components = is_array( $payload['components'] ?? null ) ? $payload['components'] : array();
		foreach ( $components as $code ) {
			if ( is_string( $code ) && ( str_contains( $code, 'return (' ) || str_contains( $code, '<' ) ) ) {
				return true;
			}
		}
		$sources = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();
		foreach ( $sources as $path => $code ) {
			if ( is_string( $path ) && preg_match( '/\.html?$/i', $path ) && is_string( $code ) && $code !== '' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $harvest
	 * @return array{block_title:string, slug:string, gutenberg_markup:string, structures:array<int, array<string, mixed>>}
	 */
	private function compile_page( string $code, string $title, string $slug, array $harvest, array $files = array(), string $design_css = '' ): array {
		$compiler   = new Jsx_Compiler();
		$converter  = new Html_To_Blocks( $design_css );
		$sections   = $compiler->compile_file( $code, $harvest, $files );
		$wrapper    = $compiler->wrapper_class();
		$wrap_style = $compiler->wrapper_style();
		$structures = array();
		$raw_parts  = array();
		$hover_css  = '';

		foreach ( $sections as $section ) {
			$html = (string) ( $section['html'] ?? '' );
			if ( trim( $html ) === '' ) {
				continue;
			}
			$type = (string) ( $section['type'] ?? 'section' );
			$name = (string) ( $section['name'] ?? 'Section' );
			[ $html, $part_hover ] = Style_Hover::apply( $html );
			$hover_css           .= $part_hover;
			$item                 = array(
				'type'             => $type,
				'title'            => $name,
				'gutenberg_markup' => $converter->convert( $html ),
				'menu_items'       => $this->menu_items( $html, $type ),
				'menu_tree'        => $this->menu_tree( $html, $type ),
				'form_fields'      => array(),
				'source_html'      => $html,
			);
			if ( str_contains( $html, '<form' ) ) {
				$item['type']        = 'form';
				$item['form_fields'] = $this->form_fields( $html );
			}
			$structures[] = $item;
			$raw_parts[]  = $html;
		}
		$hover_css .= $converter->drain_hover_css();

		$this->count_islands( $converter );

		return array(
			'block_title'      => $title,
			'slug'             => $slug,
			'gutenberg_markup' => $this->markup_from_structures( $structures ),
			'structures'       => $structures,
			'wrapper_class'    => $wrapper,
			'wrapper_style'    => $wrap_style,
			'fidelity'         => false,
			'source_html'      => Design_Html::join_parts( $raw_parts ),
			'hover_css'        => $hover_css,
		);
	}

	/**
	 * Every source file available for import resolution.
	 *
	 * Connectors expose the unpruned set as `sources`; `components` is the
	 * prompt-sized subset and is only a fallback.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	private function source_files( array $payload ): array {
		$files = array();
		foreach ( array( 'components', 'sources' ) as $key ) {
			if ( ! is_array( $payload[ $key ] ?? null ) ) {
				continue;
			}
			foreach ( $payload[ $key ] as $path => $code ) {
				if ( is_string( $code ) && $code !== '' ) {
					$files[ (string) $path ] = $code;
				}
			}
		}

		return $files;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<int, array<string, mixed>>
	 */
	private function pages_from_payload( array $payload, string $title ): array {
		$pages = array();
		$components = is_array( $payload['components'] ?? null ) ? $payload['components'] : array();
		$preferred  = array();
		$fallback   = array();
		foreach ( $components as $path => $code ) {
			if ( ! is_string( $code ) || $code === '' ) {
				continue;
			}
			$norm = str_replace( '\\', '/', (string) $path );
			$row  = array(
				'path'  => $norm,
				'slug'  => $this->slug_from_path( $norm ),
				'title' => $this->title_from_code( $code, $title ),
				'code'  => $code,
			);
			if ( preg_match( '#/(routes|pages|app)/#', $norm ) && ! str_contains( $norm, 'components/ui' ) ) {
				$preferred[] = $row;
			} else {
				$fallback[] = $row;
			}
		}
		$use = $preferred !== array() ? $preferred : $fallback;
		usort(
			$use,
			static function ( array $a, array $b ): int {
				$ap = (string) $a['path'];
				$bp = (string) $b['path'];
				$ai = str_contains( $ap, 'index.' ) ? 0 : 1;
				$bi = str_contains( $bp, 'index.' ) ? 0 : 1;
				return $ai <=> $bi ?: strlen( $ap ) <=> strlen( $bp );
			}
		);

		return $use;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{block_title:string, gutenberg_markup:string, structures:array<int, array<string, mixed>>, slug?:string, from_html?:bool}
	 */
	private function compile_html_zip( array $payload ): array {
		$sources = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();
		$html    = '';
		$path    = '';
		foreach ( $sources as $source_path => $code ) {
			if ( is_string( $source_path ) && preg_match( '/index\.html?$/i', $source_path ) && is_string( $code ) ) {
				$html = $code;
				$path = $source_path;
				break;
			}
		}
		if ( $html === '' ) {
			foreach ( $sources as $source_path => $code ) {
				if ( is_string( $source_path ) && preg_match( '/\.html?$/i', $source_path ) && is_string( $code ) ) {
					$html = $code;
					$path = $source_path;
					break;
				}
			}
		}
		if ( $html === '' ) {
			return array();
		}

		$title = $this->title_from_code( $html, 'Generated page' );

		return $this->compile_html_document( $html, $sources, $title, '/', $path );
	}

	/**
	 * Compile one HTML document into Gutenberg structures (shared CSS/JS from the ZIP).
	 *
	 * @param array<string, string> $sources Full ZIP source map (for shared scripts).
	 * @return array{block_title:string, slug:string, gutenberg_markup:string, structures:array<int, array<string, mixed>>, custom_js:string, from_html:bool, fidelity:bool, source_html:string, hover_css:string, wrapper_class:string, wrapper_style:string}
	 */
	private function compile_html_document( string $full, array $sources, string $title, string $slug, string $path = '' ): array {
		if ( trim( $full ) === '' ) {
			return array(
				'block_title'      => $title,
				'slug'             => $slug,
				'gutenberg_markup' => '',
				'structures'       => array(),
				'custom_js'        => '',
				'from_html'        => true,
				'fidelity'         => false,
				'source_html'      => '',
				'hover_css'        => '',
				'wrapper_class'    => '',
				'wrapper_style'    => '',
			);
		}

		$is_dc   = $path !== '' && $this->is_dc_html( $path, $full );
		$scripts = $is_dc ? '' : $this->scripts_from_html( $full, $sources );
		$html    = $is_dc ? $this->normalize_dc_html( $full ) : $full;
		if ( preg_match( '/<body[^>]*>([\s\S]*?)<\/body>/i', $html, $m ) ) {
			$html = $m[1];
		}

		/*
		 * The body's own children are the structures, read from the DOM the
		 * way compile_dc() reads a template.
		 *
		 * This used to split the text before every <header>, <section>,
		 * <footer> and <main> at any depth. A section's own
		 * `<header class="section-header">`, an article's header or a
		 * testimonial's `<footer>— Jane</footer>` then started a structure
		 * typed as the site's header or footer: the repository keeps those out
		 * of the page and lets the last one win, so the band disappeared, and
		 * with chrome installed the menus were rebuilt from its links. Each cut
		 * also left an element's opening tag in one structure and its content
		 * and closing tag in the next.
		 */
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			return array(
				'block_title'      => $title,
				'slug'             => $slug,
				'gutenberg_markup' => '',
				'structures'       => array(),
				'custom_js'        => $scripts,
				'from_html'        => true,
				'fidelity'         => false,
				'source_html'      => '',
				'hover_css'        => '',
				'wrapper_class'    => '',
				'wrapper_style'    => '',
			);
		}
		$hover_css = '';
		$children  = static fn( \DOMNode $parent ): array => array_values( array_filter( iterator_to_array( $parent->childNodes ), static fn( $n ) => $n instanceof \DOMElement ) );
		// Scripts are the page script (scripts_from_html()); a <style> goes
		// where the converter puts one (Html_To_Blocks::collect_style()).
		$content = static function ( array $elements ) use ( &$hover_css ): array {
			$out = array();
			foreach ( $elements as $element ) {
				$tag = strtolower( $element->tagName );
				if ( $tag === 'style' ) {
					$hover_css .= trim( (string) $element->textContent ) . "\n";
					continue;
				}
				if ( ! in_array( $tag, array( 'script', 'noscript', 'template', 'link', 'meta' ), true ) ) {
					$out[] = $element;
				}
			}

			return $out;
		};
		$nodes         = $content( $children( $body ) );
		$wrapper_class = '';
		$wrapper_style = '';
		// A single wrapper <div> around the whole page is the page, as in a
		// Claude Design template: its bands are the structures.
		if ( count( $nodes ) === 1 && strtolower( $nodes[0]->tagName ) === 'div' && $children( $nodes[0] ) !== array() ) {
			$wrapper_class = trim( (string) $nodes[0]->getAttribute( 'class' ) );
			$wrapper_style = trim( (string) $nodes[0]->getAttribute( 'style' ) );
			$nodes         = $content( $children( $nodes[0] ) );
		}
		$groups  = array();
		$content_seen = false;
		foreach ( $nodes as $node ) {
			// A plain <main>'s bands are top-level (Design_Html::is_plain_main()).
			$in_main = Design_Html::is_plain_main( $node );
			$bands   = $in_main ? $content( Design_Html::unwrap_main( $node ) ) : array( $node );
			foreach ( $bands as $band ) {
				$type = $this->html_zip_type( $band );
				/*
				 * Site chrome is at the edges of the page, outside its <main>. A header inside <main> is the page's
				 * own heading band; a <nav> or <header> after the content has begun is an in-page table of
				 * contents or a section header. Typed as chrome, either replaced the site header or rebuilt the
				 * site menu from its anchors.
				 */
				if ( ( $in_main && $type !== 'section' ) || ( $content_seen && in_array( $type, array( 'header', 'navigation' ), true ) ) ) {
					$type = 'section';
				}
				if ( $type === 'section' ) {
					$content_seen = true;
				}
				// Consecutive headers (a desktop and a mobile one) or footers are one template part, as in
				// compile_dc(). Merged here, before the loop below: that loop reads a copy of the list.
				$last = count( $groups ) - 1;
				if ( $last >= 0 && in_array( $type, array( 'header', 'footer' ), true ) && $groups[ $last ]['type'] === $type ) {
					$groups[ $last ]['nodes'][] = $band;
					continue;
				}
				$groups[] = array(
					'type'  => $type,
					'nodes' => array( $band ),
				);
			}
		}

		$structures = array();
		$raw_parts  = array();
		$converter  = new Html_To_Blocks();
		foreach ( $groups as $group ) {
			$part = '';
			foreach ( $group['nodes'] as $member ) {
				$part .= (string) $dom->saveHTML( $member );
			}
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			[ $part, $part_hover ] = Style_Hover::apply( $part );
			$hover_css            .= $part_hover;
			$type                  = $group['type'];
			if ( $type === 'section' && preg_match( '/hero|cover/i', $part ) ) {
				$type = 'hero';
			}
			// Non-home routes: drop site chrome — Home already owns header/footer parts.
			if ( $slug !== '/' && in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
				continue;
			}
			$item = array(
				'type'             => $type,
				'title'            => ucfirst( $type ),
				'gutenberg_markup' => $converter->convert( $part ),
				'menu_items'       => $this->menu_items( $part, $type ),
				'menu_tree'        => $this->menu_tree( $part, $type ),
				'form_fields'      => $this->form_fields( $part ),
				'source_html'      => $part,
			);
			$structures[] = $item;
			$raw_parts[]  = $part;
		}
		$hover_css .= $converter->drain_hover_css();

		$this->count_islands( $converter );

		if ( $structures === array() ) {
			return array(
				'block_title'      => $title,
				'slug'             => $slug,
				'gutenberg_markup' => '',
				'structures'       => array(),
				'custom_js'        => $scripts,
				'from_html'        => true,
				'fidelity'         => false,
				'source_html'      => '',
				'hover_css'        => $hover_css,
				'wrapper_class'    => $wrapper_class,
				'wrapper_style'    => $wrapper_style,
			);
		}

		$page_title = $slug === '/'
			? \DXAI_UI\Support\Page_Title::for_home( $title )
			: \DXAI_UI\Support\Page_Title::for_page( $title, $title );

		return array(
			'block_title'      => $page_title,
			'slug'             => $slug,
			'gutenberg_markup' => $this->markup_from_structures( $structures ),
			'structures'       => $structures,
			'custom_js'        => $scripts,
			'from_html'        => true,
			'fidelity'         => false,
			'source_html'      => Design_Html::join_parts( $raw_parts ),
			'hover_css'        => $hover_css,
			'wrapper_class'    => $wrapper_class,
			'wrapper_style'    => $wrapper_style,
		);
	}

	/**
	 * The type of one band of an HTML ZIP's body, by its own tag.
	 *
	 * A <header>, <footer> or <nav> inside a section, an article or a
	 * blockquote is that element's own heading or attribution, never the
	 * site's chrome, and a band that is not one of those is a section. Only a
	 * <div> can wrap the chrome (chrome_wrapper()), and only when its one
	 * header or footer does not sit inside such an element itself.
	 */
	private function html_zip_type( \DOMElement $node ): string {
		$tag = strtolower( $node->tagName );
		if ( $tag === 'header' || $tag === 'footer' ) {
			return $tag;
		}
		if ( $tag === 'nav' ) {
			return 'navigation';
		}
		if ( $tag !== 'div' ) {
			return 'section';
		}
		$type = $this->chrome_wrapper( $node );
		if ( $type === 'section' ) {
			return 'section';
		}
		$landmark = $node->getElementsByTagName( $type )->item( 0 );
		for ( $up = $landmark instanceof \DOMElement ? $landmark->parentNode : null; $up instanceof \DOMElement && ! $up->isSameNode( $node ); $up = $up->parentNode ) {
			if ( in_array( strtolower( $up->tagName ), array( 'article', 'aside', 'blockquote', 'figure', 'main', 'nav', 'section' ), true ) ) {
				return 'section';
			}
		}

		return $type;
	}

	/**
	 * Vanilla JS shipped in an HTML/CSS/JS ZIP, plus inline <script> blocks.
	 *
	 * React/TSX stays with Jsx_Compiler; this only keeps scripts that already
	 * run in a browser without a bundler.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function source_javascript( array $payload ): string {
		$chunks  = array();
		$sources = is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array();
		$scripts = is_array( $payload['scripts'] ?? null ) ? $payload['scripts'] : array();

		foreach ( $scripts as $code ) {
			if ( is_string( $code ) && $this->is_browser_script( $code ) ) {
				$chunks[] = trim( $code );
			}
		}
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $path ) || ! is_string( $code ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/\.(?:m?js)$/i', $path ) ) {
				continue;
			}
			if ( preg_match( '/(?:vite|webpack|tailwind|postcss|eslint)\.config/i', $path ) ) {
				continue;
			}
			if ( $this->is_browser_script( $code ) ) {
				$chunks[] = trim( $code );
			}
		}

		return trim( implode( "\n;\n", array_unique( $chunks ) ) );
	}

	private function is_browser_script( string $code ): bool {
		$trim = trim( $code );
		if ( $trim === '' ) {
			return false;
		}
		if ( preg_match( '/(?:^|\n)\s*(?:import|export)\s/', $trim ) ) {
			return false;
		}
		if ( preg_match( '/\bfrom\s+[\'"]react(?:-dom)?[\'"]/', $trim ) ) {
			return false;
		}
		if ( str_contains( $trim, 'ReactDOM.createRoot' ) || str_contains( $trim, 'createRoot(' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The page's own scripts, in document order, each once: the archive's
	 * file for a `src` it holds, and inline bodies.
	 *
	 * Only what a browser runs as a classic script: no type, or a JavaScript
	 * MIME type. JSON-LD, a template or any other data block written into
	 * the page script was a SyntaxError that stopped all of it. A module
	 * file with no import or export runs the same way and is kept; an inline
	 * module is not, as it may await at its top level. A file the page loads
	 * twice is written once, or its top-level `const` is declared twice.
	 *
	 * @param array<string, string> $sources
	 */
	private function scripts_from_html( string $html, array $sources ): string {
		$chunks = array();
		if ( preg_match_all( '/<script\b([^>]*)>([\s\S]*?)<\/script>/i', $html, $rows, PREG_SET_ORDER ) ) {
			foreach ( $rows as $row ) {
				$attrs = $row[1];
				$kind  = self::script_kind( $attrs );
				if ( $kind === '' ) {
					continue;
				}
				if ( preg_match( '/(?:^|\s)src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $src ) ) {
					$code = trim( $this->script_from_sources( $src[1], $sources ) );
				} elseif ( $kind === 'module' ) {
					continue;
				} else {
					$code = trim( $row[2] );
					// The Tailwind Play CDN is never loaded here (the utilities
					// are compiled), so its inline config could only throw — and
					// a throw stops every chunk after it.
					if ( preg_match( '/^tailwind\.config\s*=/', $code ) ) {
						continue;
					}
				}
				if ( $code !== '' && $this->is_browser_script( $code ) ) {
					$chunks[ md5( $code ) ] = $code;
				}
			}
		}

		return trim( implode( "\n;\n", $chunks ) );
	}

	/**
	 * What a <script>'s type attribute makes it: 'classic' (none, or one of
	 * HTML's JavaScript MIME types), 'module', or '' for a data block.
	 */
	private static function script_kind( string $attrs ): string {
		if ( ! preg_match( '/(?:^|\s)type\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attrs, $m ) ) {
			return 'classic';
		}
		$type = strtolower( trim( (string) preg_replace( '/;.*$/s', '', ( $m[1] ?? '' ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' ) ) ) );
		if ( $type === '' || 1 === preg_match( '#^(?:(?:text|application)/(?:x-)?(?:java|ecma)script|text/javascript1\.[0-5]|text/(?:jscript|livescript))$#', $type ) ) {
			return 'classic';
		}

		return $type === 'module' ? 'module' : '';
	}

	private function is_dc_html( string $path, string $html ): bool {
		return str_ends_with( strtolower( $path ), '.dc.html' ) || str_contains( $html, '<x-dc' ) || str_contains( $html, 'data-dc-script' );
	}

	private function normalize_dc_html( string $html ): string {
		return ( new Xdc_Template_Renderer() )->render( $html );
	}

	/**
	 * @param array<string, string> $sources
	 */
	private function script_from_sources( string $src, array $sources ): string {
		$src  = strtok( $src, '?' ) ?: $src;
		$base = ltrim( str_replace( '\\', '/', $src ), './' );
		foreach ( $sources as $path => $code ) {
			$norm = ltrim( str_replace( '\\', '/', (string) $path ), './' );
			if ( $norm === $base || str_ends_with( $norm, '/' . $base ) || basename( $norm ) === basename( $base ) ) {
				return is_string( $code ) ? $code : '';
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, mixed> $harvest
	 */
	private function stylesheet( array $payload, array $harvest ): string {
		$blob = '';
		if ( ! empty( $payload['design_css_raw'] ) && is_string( $payload['design_css_raw'] ) ) {
			$blob = $payload['design_css_raw'];
		}
		$styles = is_array( $payload['styles'] ?? null ) ? $payload['styles'] : array();
		$from_files = '';
		foreach ( $styles as $path => $css ) {
			if ( ! is_string( $css ) ) {
				continue;
			}
			$norm = str_replace( '\\', '/', (string) $path );
			if ( str_ends_with( $norm, 'styles.css' ) || str_ends_with( $norm, 'index.css' ) || str_ends_with( $norm, 'app.css' ) ) {
				$from_files = $css . "\n" . $from_files;
			} else {
				$from_files .= $css . "\n";
			}
		}
		if ( trim( $blob ) === '' && trim( $from_files ) !== '' ) {
			$blob = $from_files;
		} elseif ( trim( $from_files ) !== '' && ! str_contains( $blob, trim( substr( $from_files, 0, 40 ) ) ) ) {
			$blob = trim( $blob . "\n" . $from_files );
		}
		if ( trim( $blob ) === '' && ! empty( $payload['design_css'] ) && is_string( $payload['design_css'] ) ) {
			$blob = $payload['design_css'];
		}
		if ( trim( $blob ) === '' ) {
			$blob = (string) ( $harvest['css'] ?? '' );
		}
		$inline = $this->styles_from_sources( is_array( $payload['sources'] ?? null ) ? $payload['sources'] : array() );
		if ( $inline !== '' && ! str_contains( $blob, $inline ) ) {
			$blob = trim( $blob . "\n" . $inline );
		}

		return trim( $blob );
	}

	/**
	 * @param array<string, string> $sources
	 */
	private function styles_from_sources( array $sources ): string {
		$chunks = array();
		foreach ( $sources as $path => $code ) {
			if ( ! is_string( $code ) || $code === '' ) {
				continue;
			}
			if ( ! preg_match( '/\.(?:dc\.)?html?$/i', str_replace( '\\', '/', (string) $path ) ) ) {
				continue;
			}
			if ( preg_match_all( '/<style\b[^>]*>([\s\S]*?)<\/style>/i', $code, $m ) ) {
				foreach ( $m[1] as $css ) {
					$css = trim( (string) $css );
					if ( $css !== '' ) {
						$chunks[] = $css;
					}
				}
			}
		}

		return trim( implode( "\n", array_unique( $chunks ) ) );
	}

	private function slug_from_path( string $path ): string {
		return Html_Zip_Pages::slug_from_path( $path );
	}

	private function title_from_code( string $code, string $fallback ): string {
		if ( preg_match( '/head:\s*\(\)\s*=>\s*\(\{[\s\S]{0,800}?title:\s*[\'"]([^\'"]+)[\'"]/', $code, $m ) ) {
			return sanitize_text_field( $m[1] );
		}
		if ( preg_match( '/<title[^>]*>\s*([^<]+)/i', $code, $m ) ) {
			return sanitize_text_field( wp_strip_all_tags( $m[1] ) );
		}

		return $fallback;
	}

	private function root_class( string $html ): string {
		if ( preg_match( '/<(?:section|header|footer|div|nav|article)[^>]*class="([^"]+)"/', $html, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * The type of a top-level element that is not itself a landmark: 'header'
	 * or 'footer' when it is only a wrapper around one, 'section' otherwise.
	 *
	 * Designs wrap their chrome. Semper Dry's is `<div data-stick
	 * style="position:sticky;top:0">` holding a desktop top bar and the
	 * `<header>` with the primary and mobile navigation; typed by its own tag
	 * it was a "section", so the header became part of the page body instead
	 * of the site's header template part — not editable once for every page,
	 * and no menu was read from it. A wrapper counts only when it holds
	 * exactly one landmark of that kind and none of the page's content: no
	 * <main>, no <h1>, no other landmark of the opposite kind.
	 */
	private function chrome_wrapper( \DOMElement $node ): string {
		$count = static function ( string $tag ) use ( $node ): int {
			return $node->getElementsByTagName( $tag )->length;
		};
		if ( $count( 'main' ) > 0 || $count( 'h1' ) > 0 ) {
			return 'section';
		}
		if ( $count( 'header' ) === 1 && $count( 'footer' ) === 0 ) {
			return 'header';
		}
		if ( $count( 'footer' ) === 1 && $count( 'header' ) === 0 ) {
			return 'footer';
		}

		return 'section';
	}

	/**
	 * @return array<int, array{label:string, url:string}>
	 */
	private function menu_items( string $html, string $type ): array {
		if ( ! in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
			return array();
		}

		return Menu_Tree::flatten( Menu_Tree::from_html( $html ) );
	}

	/**
	 * Nested menu for site-from-menu crawl (header/footer only).
	 *
	 * @return array<int, array{label:string, url:string, children:array}>
	 */
	private function menu_tree( string $html, string $type ): array {
		if ( ! in_array( $type, array( 'header', 'footer', 'navigation' ), true ) ) {
			return array();
		}

		return Menu_Tree::from_html( $html );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function form_fields( string $html ): array {
		$fields = array();
		if ( ! preg_match_all( '/<(input|textarea|select)\b([^>]*)>/i', $html, $m, PREG_SET_ORDER ) ) {
			return $fields;
		}
		foreach ( $m as $row ) {
			$attrs = $row[2];
			$name  = '';
			$type  = 'text';
			if ( preg_match( '/\bname="([^"]+)"/i', $attrs, $nm ) ) {
				$name = sanitize_key( $nm[1] );
			}
			if ( preg_match( '/\btype="([^"]+)"/i', $attrs, $tm ) ) {
				$type = sanitize_key( $tm[1] );
			}
			if ( $name === '' || in_array( $type, array( 'hidden', 'submit', 'button' ), true ) ) {
				continue;
			}
			$fields[] = array(
				'name'     => $name,
				'type'     => $type === 'textarea' ? 'textarea' : $type,
				'label'    => ucfirst( str_replace( '_', ' ', $name ) ),
				'required' => (bool) preg_match( '/\brequired\b/i', $attrs ),
			);
		}

		return $fields;
	}

	/**
	 * @param array<int, array<string, mixed>> $structures
	 */
	private function markup_from_structures( array $structures ): string {
		$parts = array();
		foreach ( $structures as $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			$markup = trim( (string) ( $structure['gutenberg_markup'] ?? '' ) );
			if ( $markup !== '' ) {
				$parts[] = $markup;
			}
		}

		return trim( implode( "\n", $parts ) );
	}

	/**
	 * Related-article grids hydrate from source HTML (before/alongside blocks)
	 * so card regexes still match and core/query is spliced as a real sibling.
	 *
	 * @param array<int, array<string, mixed>> $structures
	 * @param array<int, array<string, mixed>> $seeded     Receives the posts the Query Loops list.
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrate_blog_structures( array $structures, string $title, array &$seeded ): array {
		$blog = new Blog_Hydrator();
		foreach ( $structures as $i => $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			$src = (string) ( $structure['source_html'] ?? '' );
			if ( $src === '' || ! preg_match( '/Related articles|Keep Reading|from our legal team|Latest from the blog/i', $src ) ) {
				continue;
			}
			$wrapped  = "<!-- wp:html -->\n{$src}\n<!-- /wp:html -->";
			$hydrated = $blog->hydrate( $wrapped, $title !== '' ? $title : (string) ( $structure['title'] ?? 'Blog' ) );
			if ( $hydrated['markup'] !== $wrapped && str_contains( $hydrated['markup'], '<!-- wp:query' ) ) {
				$structures[ $i ]['gutenberg_markup'] = $this->scrub_artifacts( $hydrated['markup'] );
				$structures[ $i ]['type']             = 'blog';
				$seeded                               = array_merge( $seeded, $hydrated['seeded'] );
			}
		}

		return $structures;
	}

	/**
	 * Drop leaked JSX crumbs and repair `\uXXXX` → stripcslashes damage (`u00b7`).
	 */
	private function scrub_artifacts( string $html ): string {
		$html = preg_replace( '/<!-- wp:paragraph[^>]*-->\s*<p[^>]*>\s*,\s*\)\}\s*<\/p>\s*<!-- \/wp:paragraph -->/i', '', $html ) ?? $html;
		$html = preg_replace( '/<p[^>]*>\s*,\s*\)\}\s*<\/p>/i', '', $html ) ?? $html;
		$html = preg_replace( '/>\s*,\s*\)\}\s*</', '><', $html ) ?? $html;
		$html = Design_Html::repair_unicode_artifacts( $html );

		return $html;
	}
}

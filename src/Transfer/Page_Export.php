<?php
/**
 * Export one converted page with everything its look depends on.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

use DXAI_UI\Chrome\Footer_Template;
use DXAI_UI\Chrome\Footer_Widgets;
use DXAI_UI\Chrome\Header_Menus;
use DXAI_UI\Chrome\Header_Template;
use DXAI_UI\Structures\Page_Scope;
use DXAI_UI\Support\Upload_Paths;

/**
 * Builds a page package (see Package for the format).
 *
 * What a converted page needs, read from where the plugin keeps it:
 *
 * - the page: title, slug, status, content, template, every `_dxai_ui_*`
 *   meta (scope, wrapper class, static-HTML flag, SEO title, tokens, palette,
 *   design names, source archive and route, page key …);
 * - its design stylesheet and runtime script (`_dxai_ui_css_url`,
 *   `_dxai_ui_js_url`, through Upload_Paths) — a page crawled into another
 *   page's scope gets that page's sheet, since that is what styles it;
 * - every template part, synced pattern (with its own stylesheet) and
 *   navigation post its content references, followed through the content of
 *   each, up to Package::MAX_ITEMS;
 * - the header and footer it renders: a template part (above), or the site
 *   header block — its template markup and the items of the menus it draws —
 *   and the site footer block — its frame, its widgets and the footer markup
 *   put back together, which is what Footer_Widgets::install() reads;
 * - every uploads file any of that points at, by URL (absolute, protocol- or
 *   root-relative, JSON-escaped) or by attachment id (media blocks,
 *   `wp-image-N`): an attachment's original with its alt text, caption,
 *   title and description, and the size each URL named; a plain uploads file
 *   (a font stored as a design asset) as it is.
 *
 * Read-only: nothing on the site is written. The package is made in a private
 * temporary folder the caller removes (Package::remove_dir()) once it has
 * been served or copied.
 */
final class Page_Export {

	/** Media blocks and the attributes that hold an attachment id. */
	public const MEDIA_ID_ATTRS = array(
		'core/image'      => array( 'id' ),
		'core/cover'      => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/file'       => array( 'id' ),
		'core/gallery'    => array( 'ids' ),
	);

	/** Meta the target keeps for itself, never carried from a source. */
	private const SKIP_META = array( Package::KEY_META, Package::SOURCE_META );

	/** @var array<int, string> */
	private array $warnings = array();

	/** @var array<string, string> Package path => absolute source path. */
	private array $files = array();

	/** @var array<string, array<string, mixed>> Media key => entry. */
	private array $media = array();

	/** @var array<string, bool> URLs already resolved. */
	private array $seen_urls = array();

	/** @var array<string, array<string, mixed>> Part slug => entry. */
	private array $parts = array();

	/** @var array<int, array<string, mixed>> Pattern id => entry. */
	private array $patterns = array();

	/** @var array<int, array<string, mixed>> Navigation post id => entry. */
	private array $navigations = array();

	/** @var array<string, bool> Template part slugs referenced and not found. */
	private array $missing = array();

	/** @var array<string, bool> Links to the source site that are not the page or a file. */
	private array $foreign = array();

	/** @var array{basedir:string, baseurl:string, host:string, path:string} */
	private array $uploads = array( 'basedir' => '', 'baseurl' => '', 'host' => '', 'path' => '' );

	/** The page's own URLs on this site, which an import points at the new page. */
	private array $page_links = array();

	/**
	 * Whether a post is a page this can export.
	 */
	public static function check( int $page_id ): true|\WP_Error {
		$post = $page_id > 0 ? get_post( $page_id ) : null;
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return new \WP_Error( 'dxai_ui_transfer_not_found', __( 'There is no such page to export.', 'dxai-ui' ), array( 'status' => 404 ) );
		}
		if ( ! get_post_meta( $page_id, '_dxai_ui_generated_page', true ) ) {
			return new \WP_Error( 'dxai_ui_transfer_not_converted', __( 'Only pages converted by DXAI-UI can be exported with their styles.', 'dxai-ui' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Build the package file.
	 *
	 * @return array{path:string, dir:string, filename:string, bytes:int, manifest:array<string, mixed>, listing:array<int, array{path:string, bytes:int}>, warnings:array<int, string>}|\WP_Error
	 */
	public function build( int $page_id ): array|\WP_Error {
		$manifest = $this->manifest( $page_id );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$dir = Package::temp_dir( 'export' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$json = Package::json( $manifest );
		Package::made( $dir . '/' . Package::MANIFEST );
		if ( $json === '' || false === file_put_contents( $dir . '/' . Package::MANIFEST, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			Package::remove_dir( $dir );
			return new \WP_Error( 'dxai_ui_transfer', __( 'The package manifest could not be written.', 'dxai-ui' ), array( 'status' => 500 ) );
		}

		$entries = array( Package::MANIFEST => $dir . '/' . Package::MANIFEST );
		$paths   = $this->files;
		ksort( $paths, SORT_STRING );
		foreach ( $paths as $name => $path ) {
			$entries[ $name ] = $path;
		}

		$slug     = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', (string) ( $manifest['page']['slug'] ?? '' ) ), '-' );
		$filename = ( $slug !== '' ? $slug : 'page-' . $page_id ) . Package::SUFFIX;
		$zip      = $dir . '/' . $filename;
		Package::made( $zip );
		$mtime    = (int) strtotime( (string) get_post_field( 'post_modified_gmt', $page_id ) . ' UTC' );
		$written  = Package::write_zip( $zip, $entries, $mtime > 0 ? $mtime : 315532800 );
		if ( is_wp_error( $written ) ) {
			Package::remove_dir( $dir );
			return $written;
		}

		$listing = array();
		foreach ( $entries as $name => $path ) {
			$listing[] = array(
				'path'  => $name,
				'bytes' => (int) filesize( $path ),
			);
		}
		clearstatcache();

		return array(
			'path'     => $zip,
			'dir'      => $dir,
			'filename' => $filename,
			'bytes'    => (int) filesize( $zip ),
			'manifest' => $manifest,
			'listing'  => $listing,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * The manifest, with the files it names collected in $this->files.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function manifest( int $page_id ): array|\WP_Error {
		$ok = self::check( $page_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$post          = get_post( $page_id );
		$this->uploads = self::uploads();
		$scope         = (int) get_post_meta( $page_id, Page_Scope::META, true );
		$scope         = $scope > 0 ? $scope : $page_id;
		// With the header and footer a body-only page renders around it (Page_Chrome), so the package carries them.
		$content       = \DXAI_UI\Chrome\Page_Chrome::with_chrome( $page_id, (string) $post->post_content );
		$meta          = self::own_meta( $page_id );

		// A page crawled into its home's scope keeps its tokens and palette
		// there; on its own on another site it needs them itself.
		if ( $scope !== $page_id ) {
			foreach ( array( '_dxai_ui_design_tokens', '_dxai_ui_palette', '_dxai_ui_font_urls', '_dxai_ui_static_html', '_dxai_ui_wrapper_class' ) as $key ) {
				if ( ! isset( $meta[ $key ] ) || $meta[ $key ] === '' || $meta[ $key ] === array() ) {
					$from = get_post_meta( $scope, $key, true );
					if ( $from !== '' && $from !== array() && $from !== false ) {
						$meta[ $key ] = $from;
					}
				}
			}
			ksort( $meta );
		}

		$this->page_links = self::page_links( $page_id );

		$design = array(
			'css'         => $this->design_file( $page_id, $scope, '_dxai_ui_css_url', 'files/design/page.css' ),
			'js'          => $this->design_file( $page_id, $scope, '_dxai_ui_js_url', 'files/design/page.js' ),
			'fonts'       => array_values( array_filter( array_map( 'strval', (array) ( $meta['_dxai_ui_font_urls'] ?? array() ) ) ) ),
			'static_html' => ! empty( $meta['_dxai_ui_static_html'] ),
		);
		foreach ( array( 'css', 'js' ) as $kind ) {
			if ( $design[ $kind ] !== '' ) {
				$this->scan_text( (string) file_get_contents( $this->files[ $design[ $kind ] ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}
		foreach ( $design['fonts'] as $font ) {
			$this->scan_text( $font );
		}
		if ( $design['css'] === '' ) {
			$this->warnings[] = __( 'The page has no design stylesheet on disk; the package carries its blocks without their design CSS.', 'dxai-ui' );
		}

		$header = $this->header( $page_id, $content );
		$footer = $this->footer( $content, $scope );

		$this->scan_markup( $content );
		if ( ( $header['mode'] ?? '' ) === 'block' ) {
			$this->scan_markup( (string) $header['markup'] );
			foreach ( (array) $header['menus'] as $menu ) {
				$this->scan_text( (string) wp_json_encode( $menu ) );
			}
		}
		if ( ( $footer['mode'] ?? '' ) === 'block' ) {
			$this->scan_markup( (string) $footer['markup'] );
		}
		// Meta holds URLs too (the design assets record, a harvested image).
		$this->scan_text( (string) wp_json_encode( $meta ) );

		if ( count( $this->parts ) + count( $this->patterns ) + count( $this->navigations ) >= Package::MAX_ITEMS ) {
			/* translators: %d: limit. */
			$this->warnings[] = sprintf( __( 'The page references more than %d template parts, patterns and menus; only the first ones are in the package.', 'dxai-ui' ), Package::MAX_ITEMS );
		}

		$media = $this->media;
		ksort( $media, SORT_NATURAL );
		foreach ( $media as &$item ) {
			usort( $item['urls'], static fn( array $a, array $b ): int => strcmp( (string) $a['url'], (string) $b['url'] ) );
		}
		unset( $item );
		$parts = $this->parts;
		ksort( $parts, SORT_STRING );
		$patterns = $this->patterns;
		ksort( $patterns );
		$navigations = $this->navigations;
		ksort( $navigations );

		$this->find_foreign( array_merge( array( $content ), array_column( $parts, 'content' ), array_column( $patterns, 'content' ), array( (string) ( $header['markup'] ?? '' ), (string) ( $footer['markup'] ?? '' ), (string) wp_json_encode( $header['menus'] ?? array() ) ) ) );
		if ( $this->foreign !== array() ) {
			$list             = array_slice( array_keys( $this->foreign ), 0, 5 );
			$this->warnings[] = sprintf(
				/* translators: 1: number of links, 2: examples. */
				_n( '%1$d link points at another page of this site and keeps pointing here after an import: %2$s', '%1$d links point at other pages of this site and keep pointing here after an import: %2$s', count( $this->foreign ), 'dxai-ui' ),
				count( $this->foreign ),
				implode( ', ', $list )
			);
		}

		$page_key = (string) ( $meta['_dxai_ui_page_key'] ?? '' );
		$files    = array();
		$paths    = $this->files;
		ksort( $paths, SORT_STRING );
		foreach ( $paths as $name => $path ) {
			$files[] = array(
				'path'  => $name,
				'bytes' => (int) filesize( $path ),
				'sha1'  => (string) sha1_file( $path ),
			);
		}

		return array(
			'format'         => Package::FORMAT,
			'format_version' => Package::VERSION,
			'plugin_version' => defined( 'DXAI_UI_VERSION' ) ? (string) DXAI_UI_VERSION : '',
			'source'         => array(
				'site_url'  => home_url( '/' ),
				'uploads'   => $this->uploads['baseurl'],
				'page_id'   => $page_id,
				'scope_id'  => $scope,
				'page_key'  => $page_key !== '' ? $page_key : 'page-' . $page_id,
				'permalink' => (string) get_permalink( $page_id ),
				'links'     => $this->page_links,
				'theme'     => (string) get_stylesheet(),
			),
			'page'           => array(
				'title'        => (string) $post->post_title,
				'slug'         => (string) $post->post_name,
				'status'       => (string) $post->post_status,
				'excerpt'      => (string) $post->post_excerpt,
				'menu_order'   => (int) $post->menu_order,
				'template'     => (string) get_post_meta( $page_id, '_wp_page_template', true ),
				'modified_gmt' => (string) $post->post_modified_gmt,
				'content'      => $content,
				'meta'         => $meta,
			),
			'design'         => $design,
			'brand'          => array(
				'is_brand' => \DXAI_UI\Compiler\Token_Styles::is_brand( $page_id ),
				'source'   => (string) ( $meta['_dxai_ui_source_zip'] ?? '' ),
			),
			'chrome'         => array(
				'header' => $header,
				'footer' => $footer,
			),
			'parts'          => array_values( $parts ),
			'patterns'       => array_values( $patterns ),
			'navigations'    => array_values( $navigations ),
			'media'          => array_values( $media ),
			'files'          => $files,
			'warnings'       => $this->warnings,
		);
	}

	/**
	 * The page's design stylesheet or script as a package file, or '' when
	 * it has none on disk. A crawled page may have none of its own and render
	 * with its scope page's.
	 */
	private function design_file( int $page_id, int $scope, string $key, string $as ): string {
		foreach ( array_unique( array( $page_id, $scope ) ) as $id ) {
			$file = Upload_Paths::for_meta( (int) $id, $key );
			if ( $file['path'] !== '' && is_readable( $file['path'] ) && is_file( $file['path'] ) ) {
				$this->files[ $as ] = \DXAI_UI\Media\Font_Host::portable( $file['path'] );

				return $as;
			}
		}

		return '';
	}

	/**
	 * The header the page renders.
	 *
	 * @return array<string, mixed>
	 */
	private function header( int $page_id, string $content ): array {
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Header_Block::NAME ) ) {
			$spec = Header_Template::for_post( $page_id );
			if ( null === $spec ) {
				$this->warnings[] = __( 'The page shows the site header block, but no header is installed on this site; the package has no header.', 'dxai-ui' );

				return array( 'mode' => 'none' );
			}
			$menus = array();
			foreach ( Header_Menus::trees( $spec ) as $location => $tree ) {
				$id   = Header_Menus::menu_for( (string) $location, $spec );
				$term = $id > 0 ? wp_get_nav_menu_object( $id ) : false;
				if ( ! $term instanceof \WP_Term || ! is_array( $tree ) ) {
					continue;
				}
				$menus[ (string) $location ] = array(
					'name'  => wp_specialchars_decode( (string) $term->name, ENT_QUOTES ),
					'items' => self::menu_rows( $tree ),
				);
			}
			ksort( $menus, SORT_STRING );
			$logo = (int) ( $spec['logo']['attachment_id'] ?? 0 );
			if ( $logo > 0 ) {
				$this->add_attachment( $logo );
			}
			$source = is_array( $spec['source'] ?? null ) ? $spec['source'] : array();

			return array(
				'mode'   => 'block',
				'markup' => (string) $spec['markup'],
				'title'  => (string) ( $source['title'] ?? '' ),
				'scope'  => Header_Template::scope_of( $spec ),
				'logo'   => $logo > 0 ? 'a' . $logo : '',
				'menus'  => $menus,
			);
		}
		$slug = self::part_ref( $content, 'header' );

		return $slug !== '' ? array(
			'mode' => 'part',
			'slug' => $slug,
		) : array( 'mode' => 'none' );
	}

	/**
	 * The footer the page renders.
	 *
	 * @return array<string, mixed>
	 */
	private function footer( string $content, int $scope ): array {
		if ( str_contains( $content, '<!-- wp:' . \DXAI_UI\Chrome\Site_Footer_Block::NAME ) ) {
			$record = Footer_Template::get();
			if ( $record === array() ) {
				$this->warnings[] = __( 'The page shows the site footer block, but no footer is installed on this site; the package has no footer.', 'dxai-ui' );

				return array( 'mode' => 'none' );
			}
			$widgets  = array();
			$sidebars = wp_get_sidebars_widgets();
			$legacy   = 0;
			foreach ( Footer_Template::areas() as $area ) {
				$widgets[ $area ] = array_values( Footer_Widgets::block_contents( $area ) );
				foreach ( (array) ( $sidebars[ $area ] ?? array() ) as $id ) {
					if ( preg_match( '/^block-\d+$/', (string) $id ) !== 1 ) {
						++$legacy;
					}
				}
			}
			if ( $legacy > 0 ) {
				$this->warnings[] = sprintf(
					/* translators: %d: number of widgets. */
					_n( '%d footer widget is not a block widget (a menu or plugin widget) and is not in the package.', '%d footer widgets are not block widgets (menu or plugin widgets) and are not in the package.', $legacy, 'dxai-ui' ),
					$legacy
				);
			}
			if ( (int) ( $record['scope'] ?? 0 ) !== $scope ) {
				$this->warnings[] = sprintf(
					/* translators: %s: design title. */
					__( 'The footer this page shows belongs to another design (%s); it is packaged as it renders here.', 'dxai-ui' ),
					(string) ( $record['title'] ?? '' )
				);
			}
			$markup = (string) preg_replace_callback(
				'/<!--\s*wp:' . preg_quote( Footer_Template::SLOT, '/' ) . '\s+(\{.*?\})\s*\/-->/s',
				static function ( array $m ) use ( $widgets ): string {
					$attrs = json_decode( $m[1], true );
					$area  = is_array( $attrs ) ? (string) ( $attrs['area'] ?? '' ) : '';

					return implode( "\n", $widgets[ $area ] ?? array() );
				},
				(string) $record['template']
			);

			return array(
				'mode'    => 'block',
				'markup'  => $markup,
				'title'   => (string) ( $record['title'] ?? '' ),
				'source'  => (string) ( $record['source'] ?? '' ),
				'scope'   => (int) ( $record['scope'] ?? 0 ),
				'columns' => (int) ( $record['columns'] ?? 0 ),
				'widgets' => $widgets,
			);
		}
		$slug = self::part_ref( $content, 'footer' );

		return $slug !== '' ? array(
			'mode' => 'part',
			'slug' => $slug,
		) : array( 'mode' => 'none' );
	}

	/**
	 * The slug of the first `dxai-` template part of an area the content
	 * references (by slug prefix, else by its area attribute).
	 */
	public static function part_ref( string $content, string $area ): string {
		if ( ! preg_match_all( '/<!--\s*wp:template-part\s+(\{.*?\})\s*\/-->/s', $content, $m ) ) {
			return '';
		}
		$by_area = '';
		foreach ( $m[1] as $json ) {
			$attrs = json_decode( $json, true );
			$slug  = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
			if ( ! str_starts_with( $slug, 'dxai-' ) ) {
				continue;
			}
			if ( str_starts_with( $slug, 'dxai-' . $area . '-' ) || $slug === 'dxai-' . $area ) {
				return $slug;
			}
			if ( $by_area === '' && ( $attrs['area'] ?? '' ) === $area ) {
				$by_area = $slug;
			}
		}

		return $by_area;
	}

	/**
	 * Header_Menus::items_tree() nodes as plain rows.
	 *
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<int, array<string, mixed>>
	 */
	private static function menu_rows( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			$item = $node['item'] ?? null;
			if ( ! is_object( $item ) ) {
				continue;
			}
			$classes = array_values( array_filter( array_map( 'strval', (array) ( $item->classes ?? array() ) ) ) );
			$out[]   = array(
				'label'       => (string) ( $item->title ?? '' ),
				'url'         => (string) ( $item->url ?? '' ),
				'target'      => (string) ( $item->target ?? '' ),
				'xfn'         => (string) ( $item->xfn ?? '' ),
				'attr_title'  => (string) ( $item->attr_title ?? '' ),
				'description' => (string) ( $item->description ?? '' ),
				'classes'     => implode( ' ', $classes ),
				'children'    => self::menu_rows( (array) ( $node['children'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Follow what a piece of block markup references: template parts,
	 * patterns, navigation posts, attachments, uploads URLs.
	 */
	private function scan_markup( string $markup ): void {
		if ( $markup === '' ) {
			return;
		}
		$this->walk( parse_blocks( $markup ) );
		if ( preg_match_all( '/(?<![A-Za-z0-9_-])wp-image-(\d+)(?![A-Za-z0-9_-])/', $markup, $m ) ) {
			foreach ( $m[1] as $id ) {
				$this->add_attachment( (int) $id );
			}
		}
		$this->scan_text( $markup );
	}

	/**
	 * @param array<int, mixed> $blocks
	 */
	private function walk( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			if ( $name === 'core/template-part' ) {
				$slug = (string) ( $attrs['slug'] ?? '' );
				if ( str_starts_with( $slug, 'dxai-' ) ) {
					$this->add_part( $slug, (string) ( $attrs['theme'] ?? '' ), (string) ( $attrs['area'] ?? '' ) );
				}
			} elseif ( $name === 'core/block' ) {
				$this->add_pattern( (int) ( $attrs['ref'] ?? 0 ) );
			} elseif ( $name === 'dxai-ui/pattern' ) {
				$this->add_pattern( (int) ( $attrs['patternId'] ?? 0 ) );
			} elseif ( $name === 'core/navigation' ) {
				$this->add_navigation( (int) ( $attrs['ref'] ?? 0 ) );
			}
			foreach ( self::MEDIA_ID_ATTRS[ $name ] ?? array() as $key ) {
				foreach ( (array) ( $attrs[ $key ] ?? array() ) as $id ) {
					if ( is_numeric( $id ) ) {
						$this->add_attachment( (int) $id );
					}
				}
			}
			$this->walk( (array) ( $block['innerBlocks'] ?? array() ) );
		}
	}

	private function room(): bool {
		return count( $this->parts ) + count( $this->patterns ) + count( $this->navigations ) < Package::MAX_ITEMS;
	}

	private function add_part( string $slug, string $theme, string $area ): void {
		if ( isset( $this->parts[ $slug ] ) || isset( $this->missing[ $slug ] ) || ! $this->room() ) {
			return;
		}
		$post = self::find_part( $slug, $theme );
		if ( ! $post instanceof \WP_Post ) {
			$this->missing[ $slug ] = true;
			/* translators: %s: template part slug. */
			$this->warnings[] = sprintf( __( 'The template part %s the page references does not exist here; the page is packaged without it.', 'dxai-ui' ), $slug );

			return;
		}
		$area = $area !== '' ? $area : (string) get_post_meta( $post->ID, '_dxai_ui_area', true );
		if ( $area === '' ) {
			$terms = wp_get_post_terms( $post->ID, 'wp_template_part_area', array( 'fields' => 'slugs' ) );
			$area  = is_array( $terms ) && isset( $terms[0] ) ? (string) $terms[0] : 'uncategorized';
		}
		$this->parts[ $slug ] = array(
			'slug'      => $slug,
			'area'      => $area,
			'title'     => (string) $post->post_title,
			'theme'     => $theme,
			'source_id' => (int) $post->ID,
			'content'   => (string) $post->post_content,
			'meta'      => self::own_meta( (int) $post->ID ),
		);
		$this->scan_markup( (string) $post->post_content );
	}

	/**
	 * The stored part a reference renders: the active theme's first, then
	 * the one the reference names, then any published part of that slug.
	 */
	private static function find_part( string $slug, string $theme ): ?\WP_Post {
		foreach ( array_unique( array_filter( array( (string) get_stylesheet(), $theme ) ) ) as $stylesheet ) {
			$found = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'name'           => $slug,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'name',
							'terms'    => $stylesheet,
						),
					),
				)
			);
			if ( isset( $found[0] ) && $found[0] instanceof \WP_Post ) {
				return $found[0];
			}
		}
		$found = get_posts(
			array(
				'post_type'      => 'wp_template_part',
				'name'           => $slug,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			)
		);

		return isset( $found[0] ) && $found[0] instanceof \WP_Post ? $found[0] : null;
	}

	private function add_pattern( int $id ): void {
		if ( $id < 1 || isset( $this->patterns[ $id ] ) || ! $this->room() ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wp_block' !== $post->post_type || 'trash' === $post->post_status ) {
			/* translators: %d: pattern id. */
			$this->warnings[] = sprintf( __( 'Synced pattern %d the page references does not exist here; the page is packaged without it.', 'dxai-ui' ), $id );

			return;
		}
		$meta = self::own_meta( $id, array( 'wp_pattern_sync_status' ) );
		$css  = Upload_Paths::for_meta( $id, '_dxai_ui_css_url' );
		$file = '';
		if ( $css['path'] !== '' && is_file( $css['path'] ) && is_readable( $css['path'] ) ) {
			$file                 = 'files/patterns/pattern-' . $id . '.css';
			$this->files[ $file ] = \DXAI_UI\Media\Font_Host::portable( $css['path'] );
		}
		$this->patterns[ $id ] = array(
			'source_id' => $id,
			'title'     => (string) $post->post_title,
			'status'    => (string) $post->post_status,
			'content'   => (string) $post->post_content,
			'css'       => $file,
			'meta'      => $meta,
		);
		$this->scan_markup( (string) $post->post_content );
		if ( $file !== '' ) {
			$this->scan_text( (string) file_get_contents( $css['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	private function add_navigation( int $id ): void {
		if ( $id < 1 || isset( $this->navigations[ $id ] ) || ! $this->room() ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wp_navigation' !== $post->post_type || 'trash' === $post->post_status ) {
			return;
		}
		$this->navigations[ $id ] = array(
			'source_id' => $id,
			'title'     => (string) $post->post_title,
			'slug'      => (string) $post->post_name,
			'content'   => (string) $post->post_content,
			'meta'      => self::own_meta( $id ),
		);
		$this->scan_markup( (string) $post->post_content );
	}

	/**
	 * Every uploads URL in a text: absolute (http, https or
	 * protocol-relative), root-relative, and JSON-escaped (`\/`).
	 */
	private function scan_text( string $text ): void {
		if ( $text === '' || $this->uploads['baseurl'] === '' ) {
			return;
		}
		$plain = str_replace( '\\/', '/', $text );
		$host  = preg_quote( $this->uploads['host'], '~' );
		$path  = preg_quote( $this->uploads['path'], '~' );
		$tail  = '/[^\s"\'<>()\\\\?#,;&*]+';
		$found = array();
		if ( $host !== '' && preg_match_all( '~(?:https?:)?//' . $host . '(?::\d+)?' . $path . $tail . '~i', $plain, $m ) ) {
			$found = array_merge( $found, $m[0] );
		}
		if ( $path !== '' && preg_match_all( '~(?<![A-Za-z0-9_.:/-])' . $path . $tail . '~', $plain, $m ) ) {
			$found = array_merge( $found, $m[0] );
		}
		foreach ( array_unique( $found ) as $url ) {
			$this->add_url( rtrim( (string) $url, '.' ) );
		}
	}

	/**
	 * One uploads URL: the attachment (or plain file) it names, and the size.
	 */
	private function add_url( string $url ): void {
		if ( isset( $this->seen_urls[ $url ] ) ) {
			return;
		}
		$this->seen_urls[ $url ] = true;
		$relative                = self::relative( $url, $this->uploads['path'] );
		if ( $relative === '' ) {
			return;
		}
		// The design files are carried as design files, never as media.
		if ( preg_match( '#^' . preg_quote( Upload_Paths::DIR, '#' ) . '/(?:pattern|site)-\d+\.(?:css|js)$#', $relative ) === 1 ) {
			return;
		}
		$canonical = $this->uploads['baseurl'] . '/' . $relative;
		$id        = (int) attachment_url_to_postid( $canonical );
		if ( $id < 1 ) {
			// A size or the scaled copy: `photo-300x200.jpg`, `photo-scaled.jpg`.
			$base = (string) preg_replace( '/-(?:\d+x\d+|scaled|rotated)(?=\.[A-Za-z0-9]+$)/', '', $canonical );
			if ( $base !== $canonical ) {
				$id = (int) attachment_url_to_postid( $base );
			}
		}
		if ( $id > 0 && $this->add_attachment( $id ) ) {
			$this->media[ 'a' . $id ]['urls'][] = array( 'url' => $url ) + self::size_of( $id, $relative );

			return;
		}
		$path = $this->uploads['basedir'] . '/' . $relative;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			/* translators: %s: URL. */
			$this->warnings[] = sprintf( __( 'The page points at %s, which is not on disk; the URL is packaged unchanged.', 'dxai-ui' ), $url );

			return;
		}
		if ( ! Package::media_allowed( $path ) ) {
			/* translators: %s: URL. */
			$this->warnings[] = sprintf( __( '%s is not an image, video, audio or font file, so it is not in the package; the URL is packaged unchanged.', 'dxai-ui' ), $url );

			return;
		}
		$key = 'f' . substr( sha1( $relative ), 0, 12 );
		if ( ! isset( $this->media[ $key ] ) ) {
			$file                 = 'files/media/' . Package::file_name( $key, $relative );
			$this->files[ $file ] = $path;
			$this->media[ $key ]  = array(
				'key'       => $key,
				'source_id' => 0,
				'file'      => $file,
				'name'      => wp_basename( $relative ),
				'relative'  => $relative,
				'mime'      => (string) ( wp_check_filetype( $path )['type'] ?? '' ),
				'bytes'     => (int) filesize( $path ),
				'sha1'      => (string) sha1_file( $path ),
				'title'     => '',
				'alt'       => '',
				'caption'   => '',
				'description' => '',
				'width'     => 0,
				'height'    => 0,
				'urls'      => array(),
			);
		}
		$this->media[ $key ]['urls'][] = array(
			'url'  => $url,
			'size' => 'full',
		);
	}

	/**
	 * An attachment's original file into the package. False when it cannot
	 * be carried (gone, unreadable, not a media type).
	 */
	private function add_attachment( int $id ): bool {
		$key = 'a' . $id;
		if ( isset( $this->media[ $key ] ) ) {
			return true;
		}
		if ( $id < 1 || get_post_type( $id ) !== 'attachment' ) {
			return false;
		}
		$path = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $id ) : '';
		if ( $path === '' || ! is_file( $path ) ) {
			$path = (string) get_attached_file( $id );
		}
		if ( $path === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			/* translators: %d: attachment id. */
			$this->warnings[] = sprintf( __( 'Attachment %d has no file on disk and is not in the package.', 'dxai-ui' ), $id );

			return false;
		}
		if ( ! Package::media_allowed( $path ) ) {
			/* translators: %s: file name. */
			$this->warnings[] = sprintf( __( '%s is not an image, video, audio or font file, so it is not in the package.', 'dxai-ui' ), wp_basename( $path ) );

			return false;
		}
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		$post = get_post( $id );
		$file = 'files/media/' . Package::file_name( (string) $id, wp_basename( $path ) );

		$this->files[ $file ] = $path;
		$this->media[ $key ]  = array(
			'key'         => $key,
			'source_id'   => $id,
			'file'        => $file,
			'name'        => wp_basename( $path ),
			'relative'    => '',
			'mime'        => (string) get_post_mime_type( $id ),
			'bytes'       => (int) filesize( $path ),
			'sha1'        => (string) sha1_file( $path ),
			'title'       => $post instanceof \WP_Post ? (string) $post->post_title : '',
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
			'description' => $post instanceof \WP_Post ? (string) $post->post_content : '',
			'width'       => (int) ( $meta['width'] ?? 0 ),
			'height'      => (int) ( $meta['height'] ?? 0 ),
			'urls'        => array(),
		);

		return true;
	}

	/**
	 * Which of an attachment's files a URL names: `full` (what
	 * wp_get_attachment_url() gives), a registered size, or `original` (the
	 * file before WordPress scaled it).
	 *
	 * @return array{size:string, width:int, height:int}
	 */
	private static function size_of( int $id, string $relative ): array {
		$name = wp_basename( $relative );
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size => $row ) {
			if ( is_array( $row ) && (string) ( $row['file'] ?? '' ) === $name ) {
				return array(
					'size'   => (string) $size,
					'width'  => (int) ( $row['width'] ?? 0 ),
					'height' => (int) ( $row['height'] ?? 0 ),
				);
			}
		}
		if ( ! empty( $meta['original_image'] ) && (string) $meta['original_image'] === $name && wp_basename( (string) ( $meta['file'] ?? '' ) ) !== $name ) {
			return array(
				'size'   => 'original',
				'width'  => 0,
				'height' => 0,
			);
		}

		return array(
			'size'   => 'full',
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
		);
	}

	/**
	 * Links to this site that are neither the page itself nor an uploads
	 * file: they cannot follow the page to another site.
	 *
	 * @param array<int, string> $texts
	 */
	private function find_foreign( array $texts ): void {
		$home = wp_parse_url( home_url( '/' ) );
		$host = strtolower( (string) ( $home['host'] ?? '' ) );
		if ( $host === '' ) {
			return;
		}
		$own = array();
		foreach ( $this->page_links as $link ) {
			$own[ untrailingslashit( (string) preg_replace( '#^https?:#i', '', $link ) ) ] = true;
		}
		foreach ( $texts as $text ) {
			$plain = str_replace( '\\/', '/', (string) $text );
			if ( ! preg_match_all( '~(?:https?:)?//' . preg_quote( $host, '~' ) . '(?::\d+)?(?:/[^\s"\'<>()\\\\,;&*]*)?~i', $plain, $m ) ) {
				continue;
			}
			foreach ( $m[0] as $url ) {
				$bare = untrailingslashit( (string) preg_replace( '#^https?:#i', '', (string) $url ) );
				if ( isset( $own[ $bare ] ) || ( $this->uploads['path'] !== '' && str_contains( $bare, $this->uploads['path'] . '/' ) ) ) {
					continue;
				}
				// The site root itself is the target's root after an import.
				if ( $bare === '//' . $host || $bare === '//' . $host . '/' ) {
					continue;
				}
				$this->foreign[ (string) $url ] = true;
			}
		}
	}

	/**
	 * The page's own URLs here: its permalink and its query forms.
	 *
	 * @return array<int, string>
	 */
	private static function page_links( int $page_id ): array {
		$links = array(
			(string) get_permalink( $page_id ),
			home_url( '/?page_id=' . $page_id ),
			home_url( '/?p=' . $page_id ),
		);
		$links = array_values( array_unique( array_filter( $links ) ) );
		sort( $links, SORT_STRING );

		return $links;
	}

	/**
	 * A post's `_dxai_ui_*` meta (and the extra keys named), sorted.
	 *
	 * @param array<int, string> $extra
	 * @return array<string, mixed>
	 */
	private static function own_meta( int $post_id, array $extra = array() ): array {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			$key = (string) $key;
			if ( ( ! str_starts_with( $key, '_dxai_ui_' ) && ! in_array( $key, $extra, true ) ) || in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			$value = maybe_unserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values );
			if ( is_object( $value ) ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * This site's uploads: folder, URL, host and URL path.
	 *
	 * @return array{basedir:string, baseurl:string, host:string, path:string}
	 */
	public static function uploads(): array {
		$dir     = wp_get_upload_dir();
		$baseurl = untrailingslashit( (string) ( $dir['baseurl'] ?? '' ) );

		return array(
			'basedir' => untrailingslashit( wp_normalize_path( (string) ( $dir['basedir'] ?? '' ) ) ),
			'baseurl' => $baseurl,
			'host'    => strtolower( (string) wp_parse_url( $baseurl, PHP_URL_HOST ) ),
			'path'    => untrailingslashit( (string) wp_parse_url( $baseurl, PHP_URL_PATH ) ),
		);
	}

	/**
	 * The uploads-relative path of a URL whose path starts with $base_path,
	 * or '' (with `..` refused).
	 */
	public static function relative( string $url, string $base_path ): string {
		$path = (string) wp_parse_url( str_starts_with( $url, '//' ) ? 'http:' . $url : $url, PHP_URL_PATH );
		if ( $path === '' ) {
			$path = $url;
		}
		$path = rawurldecode( $path );
		if ( $base_path !== '' ) {
			if ( ! str_starts_with( $path, $base_path . '/' ) ) {
				return '';
			}
			$path = substr( $path, strlen( $base_path ) + 1 );
		} else {
			$path = ltrim( $path, '/' );
		}
		if ( $path === '' || str_contains( $path, "\0" ) || in_array( '..', explode( '/', $path ), true ) ) {
			return '';
		}

		return $path;
	}
}

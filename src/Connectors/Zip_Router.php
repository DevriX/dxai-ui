<?php
/**
 * Which connector a dropped archive belongs to.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Connectors;

/**
 * One sniffer, two callers.
 *
 * The REST endpoint routed on the card someone clicked in the wizard and
 * `bin/purge-and-import-real-zips.php` routed on its own inline heuristic, so
 * the same ZIP could reach a different connector depending on how it was
 * imported — the admin sent a React source archive to
 * Generated_Design_Connector whenever "Generated ZIP" was the card in front of
 * the person, while the command line read the file list and sent it to
 * Lovable_Connector. Same archive, two compilers, two different pages, and the
 * verification pack only ever measured the command-line one.
 *
 * The archive itself says what it is, so that is what both paths ask now.
 */
final class Zip_Router {

	public const LOVABLE   = 'lovable';
	public const DC        = 'dc';
	public const GENERATED = 'generated';
	/**
	 * Which connector this archive's file list calls for.
	 *
	 * React source wins: a `.tsx`/`.jsx` file that is neither vendored nor
	 * built output means there is a component tree to split into editable
	 * blocks, which is Lovable_Connector's whole job. Failing that, a
	 * `.dc.html` is a Claude Design export. Everything else - plain
	 * HTML/CSS/JS, Vue, a dist-only export - is a generated design.
	 *
	 * The order is the verification pack's: bin/verify-import.cjs picks
	 * between its two oracles with exactly this test (a `.dc.html` and no
	 * TSX), so an archive measured the TSX way there is compiled the TSX way
	 * here. `node_modules/` and `dist/` are skipped because they hold other
	 * people's components and this design's build output; counting either as
	 * "there is source here" sends a dist-only export to the source compiler,
	 * which produces no structures and then fails the import with a message
	 * about supplying a source archive - for an archive that has none.
	 *
	 * @param array<int, string> $entries Archive paths, as Zip_Extractor::entries() returns them (the names of read_entries()).
	 */
	public static function kind( array $entries ): string {
		$react = false;
		$dc    = false;
		foreach ( $entries as $entry ) {
			$norm = strtolower( str_replace( '\\', '/', (string) $entry ) );
			if ( preg_match( '#(?:^|/)(?:node_modules|dist|build|\.next|\.git)/#', $norm ) === 1 ) {
				continue;
			}
			if ( str_ends_with( $norm, '.dc.html' ) ) {
				$dc = true;
				continue;
			}
			if ( preg_match( '/\.(?:tsx|jsx)$/', $norm ) === 1 ) {
				$react = true;
			}
		}

		if ( $react ) {
			return self::LOVABLE;
		}

		return $dc ? self::DC : self::GENERATED;
	}

	/** The connector for a kind. */
	public static function connector( string $kind ): Lovable_Connector|Dc_Connector|Generated_Design_Connector {
		return match ( $kind ) {
			self::LOVABLE => new Lovable_Connector(),
			self::DC      => new Dc_Connector(),
			default       => new Generated_Design_Connector(),
		};
	}

	/**
	 * Read an archive with the connector its contents call for.
	 *
	 * Two answers come before the sniff, because both used to be lost in it.
	 * An upload PHP refused (over upload_max_filesize, cut short) arrives
	 * with an error code and an empty `tmp_name`, and was answered "Upload a
	 * design archive" - to someone who had just uploaded one. And an archive
	 * the server could not list (no zip extension, or not a ZIP at all) gave
	 * kind() an empty list, which it calls GENERATED, so a React export went
	 * to the HTML connector and failed with "No HTML/CSS/JS … source was
	 * found in the ZIP" - a message about the archive, for a fact about the
	 * server. read_entries() says which it was instead.
	 *
	 * @param array<string, mixed> $file A `$_FILES`-shaped array, or `tmp_name` + `name` for a path on disk.
	 */
	public static function import( array $file ): Source_Document|\WP_Error {
		$upload_error = Zip_Extractor::upload_error( $file );
		if ( null !== $upload_error ) {
			return $upload_error;
		}

		$path = (string) ( $file['tmp_name'] ?? '' );
		if ( $path === '' || ! is_readable( $path ) ) {
			return new \WP_Error(
				'dxai_ui_zip',
				__( 'Upload a design archive (.zip).', 'dxai-ui' ),
				array( 'status' => 400 )
			);
		}

		$entries = Zip_Extractor::read_entries( $path );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		return self::connector( self::kind( array_column( $entries, 'name' ) ) )->import_zip( $file );
	}

	/**
	 * What to tell someone the archive turned out to be.
	 *
	 * The wizard no longer makes anyone classify their own export, so this is
	 * how the screen still names what it read.
	 */
	public static function label( string $kind ): string {
		return match ( $kind ) {
			self::LOVABLE => __( 'React source (Lovable, Vite)', 'dxai-ui' ),
			self::DC      => __( 'Claude Design export', 'dxai-ui' ),
			default       => __( 'Generated design (HTML, Vue, Next.js)', 'dxai-ui' ),
		};
	}
}

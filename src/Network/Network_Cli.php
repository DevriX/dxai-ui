<?php
/**
 * WP-CLI: wp dxai-ui network.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Network;

/**
 * The network as a teacher (Network_Model).
 *
 *   wp dxai-ui network learn [--url=<a,b,…>] [--from=<folder>] [--dry-run]
 *   wp dxai-ui network show  [--kind=<kind>] [--format=json]
 *
 * `learn` reads every other site of this multisite in place (nothing is sent anywhere), and the sites named with --url
 * through their public REST API, or a folder crawl.cjs saved with --from; the model is kept for the whole network.
 * `show` says what it holds: the set of pages, each kind of page, the formulas of the headings, the questions.
 */
final class Network_Cli {

	public static function register(): void {
		\WP_CLI::add_command(
			'dxai-ui network',
			array( self::class, 'run' ),
			array(
				'shortdesc' => 'Learn how the team builds the pages after the Home from the sites of the network (learn), and show it (show).',
				'synopsis'  => array(
					array(
						'type'     => 'positional',
						'name'     => 'action',
						'options'  => array( 'learn', 'show' ),
						'optional' => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'url',
						'optional'    => true,
						'description' => 'Sites to read through their public REST API, separated by commas.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'from',
						'optional'    => true,
						'description' => 'A folder .verify/network-research/crawl.cjs saved (one folder per site).',
					),
					array(
						'type'     => 'flag',
						'name'     => 'dry-run',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'kind',
						'optional' => true,
					),
					array(
						'type'     => 'assoc',
						'name'     => 'format',
						'optional' => true,
						'options'  => array( 'text', 'json' ),
						'default'  => 'text',
					),
				),
			)
		);
	}

	/**
	 * @param array<int, string>          $args
	 * @param array<string, string|bool> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		if ( ( $args[0] ?? '' ) === 'learn' ) {
			self::learn( $assoc );
			return;
		}
		self::show( $assoc );
	}

	/**
	 * @param array<string, string|bool> $assoc
	 */
	private static function learn( array $assoc ): void {
		$t0      = microtime( true );
		$sources = array();
		if ( isset( $assoc['from'] ) ) {
			$dir = (string) $assoc['from'];
			if ( ! is_dir( $dir ) ) {
				\WP_CLI::error( "No folder at $dir." );
			}
			$sources = Site_Source::folder( $dir );
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['url'] ?? '' ) ) ) ) as $url ) {
			$s = Site_Source::rest( $url );
			if ( is_wp_error( $s ) ) {
				\WP_CLI::warning( "$url: " . $s->get_error_message() );
				continue;
			}
			$sources[] = $s;
		}
		if ( ! isset( $assoc['from'] ) && ! isset( $assoc['url'] ) ) {
			if ( ! is_multisite() ) {
				\WP_CLI::error( 'This site is not a network: name the sites to learn from with --url (or a crawl folder with --from).' );
			}
			foreach ( Site_Source::network_blogs() as $id ) {
				$s = Site_Source::blog( $id );
				if ( $s !== null ) {
					$sources[] = $s;
				}
			}
		}
		if ( $sources === array() ) {
			\WP_CLI::error( 'No site to learn from.' );
		}
		$sites = array();
		foreach ( $sources as $src ) {
			$site    = Network_Model::read_site( $src );
			$sites[] = $site;
			\WP_CLI::log( sprintf( '  %-56s %3d pages%s', $site['key'], count( $site['pages'] ), $site['design_home'] ? '  Home: a design' : '' ) );
		}
		$model = Network_Model::learn( $sites );
		if ( empty( $assoc['dry-run'] ) ) {
			Network_Model::store( $model );
		}
		\WP_CLI::success( sprintf( 'Learned from %d sites, %d pages, in %.1f s%s.', count( $sites ), array_sum( array_column( $model['sources'], 'pages' ) ), microtime( true ) - $t0, empty( $assoc['dry-run'] ) ? '' : ' (not kept: --dry-run)' ) );
	}

	/**
	 * @param array<string, string|bool> $assoc
	 */
	private static function show( array $assoc ): void {
		$model = Network_Model::get();
		if ( $model === null ) {
			\WP_CLI::error( 'Nothing learned yet: run `wp dxai-ui network learn`.' );
		}
		if ( ( $assoc['format'] ?? 'text' ) === 'json' ) {
			\WP_CLI::line( (string) wp_json_encode( $model, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$pct = static fn( float $v ): string => round( 100 * $v ) . '%';
		\WP_CLI::log( sprintf( 'Learned %s from %d sites (%s).', (string) $model['learned'], isset( $model['sources'] ) ? count( (array) $model['sources'] ) : (int) ( $model['sites'] ?? 0 ), Network_Model::stored() !== null ? 'on this network' : 'the model the plugin ships' ) );
		$set = (array) $model['set'];
		\WP_CLI::log( sprintf( 'A site has: %s; services %d (%d–%d), cities %d (%d–%d).', implode( ', ', array_map( static fn( string $k, float $v ): string => "$k " . $pct( $v ), array_keys( $set['kinds'] ), $set['kinds'] ) ), $set['services']['median'], $set['services']['p25'], $set['services']['p75'], $set['locations']['median'], $set['locations']['p25'], $set['locations']['p75'] ) );
		$kinds = isset( $assoc['kind'] ) ? array( (string) $assoc['kind'] ) : array_keys( (array) $model['kinds'] );
		foreach ( $kinds as $kind ) {
			$k = $model['kinds'][ $kind ] ?? null;
			if ( ! is_array( $k ) ) {
				\WP_CLI::warning( "No page of the kind $kind." );
				continue;
			}
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( '%s: %d pages on %d sites; sections %d (%d–%d); words %d, with a designed Home %d (%d–%d)', $kind, $k['pages'], $k['sites'], $k['sections']['median'], $k['sections']['p25'], $k['sections']['p75'], $k['words']['median'], $k['words_design']['median'], $k['words_design']['p25'], $k['words_design']['p75'] ) );
			\WP_CLI::log( '  roles: ' . implode( ', ', array_map( static fn( string $r, float $v ): string => "$r " . $pct( $v ), array_keys( $k['roles'] ), $k['roles'] ) ) );
			\WP_CLI::log( '  last: ' . implode( ', ', array_map( static fn( string $r, float $v ): string => "$r " . $pct( $v ), array_keys( $k['last'] ), $k['last'] ) ) );
			\WP_CLI::log( sprintf( '  sections on a designed site: the Home\'s words %s, the Home\'s build with new words %s, new %s', $pct( (float) $k['home']['same'] ), $pct( (float) $k['home']['shape'] ), $pct( (float) $k['home']['new'] ) ) );
			if ( $k['faq']['questions']['n'] > 0 ) {
				\WP_CLI::log( sprintf( '  FAQ: %d questions (%d–%d), %d words', $k['faq']['questions']['median'], $k['faq']['questions']['p25'], $k['faq']['questions']['p75'], $k['faq']['words']['median'] ) );
			}
			foreach ( array_slice( (array) ( $model['formulas'][ $kind ] ?? array() ), 0, 10 ) as $f ) {
				\WP_CLI::log( sprintf( '  %3d pages %2d sites  %-8s %s', $f['pages'], $f['sites'], $f['role'], $f['formula'] ) );
			}
		}
		if ( ! isset( $assoc['kind'] ) || $assoc['kind'] === 'service' ) {
			foreach ( (array) $model['questions'] as $family => $qs ) {
				\WP_CLI::log( '' );
				\WP_CLI::log( "Questions, $family:" );
				foreach ( array_slice( (array) $qs, 0, 8 ) as $q ) {
					\WP_CLI::log( sprintf( '  %2d sites  %s', $q['sites'], $q['q'] ) );
				}
			}
		}
	}
}

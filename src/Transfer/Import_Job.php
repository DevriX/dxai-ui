<?php
/**
 * A package import that takes more than one request.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Transfer;

/**
 * An import through the admin screen runs in steps when it would take longer
 * than a request may on the host: WP Engine ends a request after 60 seconds,
 * and a package with 56 images took 61.5 s to import in one (every image
 * resized into its thumbnails). Page_Import::run() adds media until its time
 * budget is spent and then hands back a token; the screen posts the token and
 * the next step goes on from there, until the page itself is written.
 *
 * Between steps the package waits in a folder of its own: the uploaded ZIP and
 * job.json (who started it, the choices it was started with, what each media
 * file became). Nothing goes into the database, so an abandoned job leaves no
 * row behind: its folder is removed after a day (sweep()), and importing the
 * package again starts over, with every file already added reused.
 *
 * The folder is under uploads/dxai-ui first — the one place every web server
 * of a multi-server host shares, where a system temp folder is each server's
 * own — behind deny rules and an index.php, under a 128-bit random name that
 * is the token; else under the system temp folder. Only this user may go on
 * with a job (open()).
 */
final class Import_Job {

	private const PREFIX = 'dxai-ui-job-';

	private const ZIP = 'package.zip';

	private const STATE = 'job.json';

	/** Files a job folder may hold; close() removes exactly these. */
	private const FILES = array( self::ZIP, self::STATE, 'index.php', '.htaccess' );

	/**
	 * Start a job: the package copied into a folder of its own.
	 *
	 * @param array<string, mixed> $options Page_Import::run() options to go on with.
	 * @param array<string, mixed> $state   What the steps so far did.
	 * @return array<string, mixed>|\WP_Error The job (token, dir, zip, name, options, state).
	 */
	public static function create( string $zip, string $name, array $options, array $state ): array|\WP_Error {
		self::sweep();
		$token = bin2hex( random_bytes( 16 ) );
		foreach ( self::roots() as $i => $root ) {
			if ( ! is_dir( $root ) ) {
				wp_mkdir_p( $root );
			}
			if ( ! is_dir( $root ) || ! wp_is_writable( $root ) || ! Package::listable( $root ) ) {
				continue;
			}
			$dir = $root . '/' . self::PREFIX . $token;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged -- private work folder.
			if ( ! @mkdir( $dir, 0700 ) ) {
				continue;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
			if ( 0 === $i ) {
				file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n" );
			}
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( ! @copy( $zip, $dir . '/' . self::ZIP ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy
				self::remove( $dir );
				continue;
			}
			$job = array(
				'token'   => $token,
				'dir'     => $dir,
				'zip'     => $dir . '/' . self::ZIP,
				'name'    => $name,
				'user'    => get_current_user_id(),
				'created' => time(),
				'options' => $options,
				'state'   => $state,
			);
			self::save( $job );

			return $job;
		}

		return new \WP_Error( 'dxai_ui_transfer_job', __( 'The import could not be split into steps: neither wp-content/uploads nor the system temp folder can be written to.', 'dxai-ui' ), array( 'status' => 500 ) );
	}

	/**
	 * The job a token names, when it is this user's and still here.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function open( string $token ): array|\WP_Error {
		$gone = new \WP_Error(
			'dxai_ui_transfer_job_gone',
			__( 'This import is no longer waiting on the server (it finished, or it was left for more than a day). Import the package again: the files already added to the media library are reused, and the page is updated in place.', 'dxai-ui' ),
			array( 'status' => 404 )
		);
		if ( preg_match( '/^[a-f0-9]{32}$/', $token ) !== 1 ) {
			return $gone;
		}
		foreach ( self::roots() as $root ) {
			$dir  = $root . '/' . self::PREFIX . $token;
			$file = $dir . '/' . self::STATE;
			if ( ! is_file( $file ) || is_link( $dir ) ) {
				continue;
			}
			$job = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $job ) || (int) ( $job['created'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				self::remove( $dir );

				return $gone;
			}
			if ( (int) ( $job['user'] ?? 0 ) < 1 || (int) $job['user'] !== get_current_user_id() ) {
				return new \WP_Error( 'dxai_ui_transfer_job_user', __( 'This import was started by another user; only they can go on with it.', 'dxai-ui' ), array( 'status' => 403 ) );
			}
			$job['token'] = $token;
			$job['dir']   = $dir;
			$job['zip']   = $dir . '/' . self::ZIP;
			if ( ! is_file( $job['zip'] ) ) {
				self::remove( $dir );

				return $gone;
			}

			return $job;
		}

		return $gone;
	}

	/**
	 * Write a job's state.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function save( array $job ): void {
		$data = array_intersect_key( $job, array_flip( array( 'name', 'user', 'created', 'options', 'state' ) ) );
		file_put_contents( (string) $job['dir'] . '/' . self::STATE, (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Remove a finished job's folder.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function close( array $job ): void {
		self::remove( (string) ( $job['dir'] ?? '' ) );
	}

	/**
	 * Remove the folders of jobs left for more than a day.
	 */
	public static function sweep(): void {
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( self::roots() as $root ) {
			$handle = is_dir( $root ) ? @opendir( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $handle ) {
				continue;
			}
			$stale = array();
			while ( false !== ( $name = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-f0-9]{32}$/', $name ) === 1 ) {
					$stale[] = $root . '/' . $name;
				}
			}
			closedir( $handle );
			foreach ( $stale as $dir ) {
				$mtime = @filemtime( $dir . '/' . self::STATE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$mtime = false === $mtime ? @filemtime( $dir ) : $mtime; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $mtime && $mtime < $cutoff ) {
					self::remove( $dir );
				}
			}
		}
	}

	/**
	 * uploads/dxai-ui (shared by every web server of a host), then the system
	 * temp folder.
	 *
	 * @return array<int, string>
	 */
	private static function roots(): array {
		$roots   = array();
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$roots[] = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . '/' . \DXAI_UI\Support\Upload_Paths::DIR;
		}
		$temp = get_temp_dir();
		if ( is_string( $temp ) && $temp !== '' ) {
			$roots[] = untrailingslashit( wp_normalize_path( $temp ) );
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * Delete a job folder: the files a job holds, by name, then the folder —
	 * only a folder with this class's name pattern, never through a link.
	 */
	private static function remove( string $dir ): void {
		$dir = untrailingslashit( wp_normalize_path( $dir ) );
		if ( $dir === '' || preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-f0-9]{32}$/', basename( $dir ) ) !== 1 || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- plain unlink: the wp_delete_file filter is for media, and a warning would break a REST answer.
		foreach ( self::FILES as $file ) {
			if ( is_file( $dir . '/' . $file ) ) {
				@unlink( $dir . '/' . $file );
			}
		}
		@rmdir( $dir );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
	}
}

<?php
/**
 * An archive's files only partly added: the round of an import read in rounds is up.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Media;

/**
 * Thrown by Asset_Harvester::from_zip() when the deadline set by until() passes with files left to add. The files added so far are
 * in the media library; reading the archive again adds the rest (the ones already there are found by their content).
 */
final class Harvest_Paused extends \RuntimeException {

	/** Files of the archive gone through, this round and before it. */
	public readonly int $done;

	/** Files of the archive the design names. */
	public readonly int $total;

	public function __construct( int $done, int $total ) {
		parent::__construct( sprintf( 'Added %d of %d files; the rest in the next round.', $done, $total ) );
		$this->done  = $done;
		$this->total = $total;
	}
}

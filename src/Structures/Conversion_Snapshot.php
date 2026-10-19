<?php
/**
 * The text a conversion record keeps: what an import was given and what it made.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Structures;

/**
 * Every save keeps its compile result and its own answer as one conversion
 * record (Structure_Repository::save_conversion()); restore() saves it again
 * and Document_Store::rebuild() builds a design's document from it.
 *
 * The record used to be the JSON itself. For a whole site that is large: a
 * Claude Design package of 13 pages (Gemini Restoration) gave 39 MB of JSON,
 * and writing it took the save from 310 MB to 662 MB of memory, because
 * WordPress holds the text several times on its way into the database (slashed,
 * unslashed, filtered, escaped, the query). On a site with PHP's usual 256 MB
 * the save stopped right there, after every page was written: "Allowed memory
 * size of 268435456 bytes exhausted", which the admin saw as "There has been
 * a critical error on this website".
 *
 * The JSON is now compressed (deflate) and kept as base64: 6 MB for the same
 * site. Base64 has none of the characters KSES or wp_slash() act on, so the
 * text reaches the database as it was made. A host without zlib keeps the JSON
 * as before, with the characters KSES reads escaped. decode() reads both, and
 * every record written before.
 */
final class Conversion_Snapshot {

	/** What a compressed record starts with; the version is for a later format. */
	public const PREFIX = 'dxai-gz1:';

	/**
	 * The record's text for this payload.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function encode( array $payload ): string {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return '{}';
		}
		if ( function_exists( 'gzdeflate' ) ) {
			$packed = gzdeflate( $json, 6 );
			if ( is_string( $packed ) ) {
				return self::PREFIX . base64_encode( $packed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a binary stream kept as text, not hidden code.
			}
		}
		unset( $json );
		/*
		 * With <, >, &, ' and " escaped as \u00XX, the JSON holds no character
		 * KSES acts on — the flags core uses for the global-styles post. Where
		 * the importing user has no unfiltered_html, KSES runs on this post
		 * like any other, and it read the design's HTML inside the JSON strings
		 * as markup: measured, a 290 KB snapshot came back as 67 MB of invalid
		 * JSON after 70 seconds, and the next one exhausted a 1 GB memory
		 * limit — as the last step of an import, after every page was written.
		 */
		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * The payload a record holds, compressed or not; null when it holds none.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $content ): ?array {
		if ( str_starts_with( $content, self::PREFIX ) ) {
			if ( ! function_exists( 'gzinflate' ) ) {
				return null;
			}
			$packed = base64_decode( substr( $content, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encode().
			$json   = is_string( $packed ) ? @gzinflate( $packed ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a damaged record warns; null says it.
			if ( ! is_string( $json ) ) {
				return null;
			}
			$content = $json;
		}
		$payload = json_decode( $content, true );

		return is_array( $payload ) ? $payload : null;
	}
}

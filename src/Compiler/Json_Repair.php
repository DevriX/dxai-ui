<?php
/**
 * Recover JSON objects from messy or truncated LLM output.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Json_Repair {

	/**
	 * @return array<string, mixed>|null
	 */
	public static function decode( string $text ): ?array {
		foreach ( self::candidates( $text ) as $candidate ) {
			$decoded = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$extracted = self::extract_fields( $text );
		return $extracted !== array() ? $extracted : null;
	}

	public static function last_error(): string {
		$message = json_last_error_msg();
		return is_string( $message ) && $message !== '' ? $message : 'Unknown error';
	}

	/**
	 * @return array<int, string>
	 */
	private static function candidates( string $text ): array {
		$out  = array();
		$seen = array();

		$normalized = self::normalize( $text );
		foreach ( array( $normalized, self::extract_object( $normalized ) ) as $raw ) {
			if ( $raw === '' ) {
				continue;
			}
			$variants = array(
				$raw,
				self::repair_trailing_commas( $raw ),
				self::escape_raw_controls_in_strings( $raw ),
				self::repair_trailing_commas( self::escape_raw_controls_in_strings( $raw ) ),
				self::reescape_markup_fields( $raw ),
				self::close_truncated( self::escape_raw_controls_in_strings( $raw ) ),
				self::close_truncated( self::reescape_markup_fields( self::escape_raw_controls_in_strings( $raw ) ) ),
			);
			foreach ( $variants as $variant ) {
				$variant = trim( $variant );
				if ( $variant === '' || isset( $seen[ $variant ] ) ) {
					continue;
				}
				$seen[ $variant ] = true;
				$out[]            = $variant;
			}
		}

		return $out;
	}

	public static function normalize( string $text ): string {
		$text = trim( $text );
		$text = preg_replace( '/<think\b[^>]*>.*?<\/think>/is', '', $text ) ?? $text;
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text ) ?? $text;
		$text = preg_replace( '/\s*```\s*$/', '', $text ) ?? $text;
		return trim( $text );
	}

	public static function extract_object( string $text ): string {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return '';
		}
		$end = strrpos( $text, '}' );
		if ( false === $end || $end <= $start ) {
			return substr( $text, $start );
		}

		return substr( $text, $start, $end - $start + 1 );
	}

	public static function repair_trailing_commas( string $json ): string {
		$repaired = preg_replace( '/,\s*([}\]])/', '$1', $json );
		return is_string( $repaired ) ? $repaired : $json;
	}

	public static function escape_raw_controls_in_strings( string $json ): string {
		$out       = '';
		$in_string = false;
		$escape    = false;
		$length    = strlen( $json );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $json[ $i ];
			if ( $in_string ) {
				if ( $escape ) {
					$out   .= $char;
					$escape = false;
					continue;
				}
				if ( $char === '\\' ) {
					$out   .= $char;
					$escape = true;
					continue;
				}
				if ( $char === '"' ) {
					$in_string = false;
					$out      .= $char;
					continue;
				}
				if ( $char === "\n" ) {
					$out .= '\\n';
					continue;
				}
				if ( $char === "\r" ) {
					$out .= '\\r';
					continue;
				}
				if ( $char === "\t" ) {
					$out .= '\\t';
					continue;
				}
				$ord = ord( $char );
				if ( $ord < 32 ) {
					$out .= sprintf( '\\u%04x', $ord );
					continue;
				}
				$out .= $char;
				continue;
			}

			if ( $char === '"' ) {
				$in_string = true;
			}
			$out .= $char;
		}

		return $out;
	}

	public static function close_truncated( string $json ): string {
		$in_string = false;
		$escape    = false;
		$stack     = array();
		$length    = strlen( $json );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $json[ $i ];
			if ( $in_string ) {
				if ( $escape ) {
					$escape = false;
					continue;
				}
				if ( $char === '\\' ) {
					$escape = true;
					continue;
				}
				if ( $char === '"' ) {
					$in_string = false;
				}
				continue;
			}
			if ( $char === '"' ) {
				$in_string = true;
				continue;
			}
			if ( $char === '{' || $char === '[' ) {
				$stack[] = $char === '{' ? '}' : ']';
				continue;
			}
			if ( ( $char === '}' || $char === ']' ) && $stack !== array() ) {
				array_pop( $stack );
			}
		}

		if ( $in_string ) {
			$json .= '"';
		}

		$json = rtrim( $json, ", \t\n\r" );
		while ( $stack !== array() ) {
			$json .= array_pop( $stack );
		}

		return $json;
	}

	/**
	 * Re-quote gutenberg_markup / CSS / JS when the model left raw block JSON unescaped.
	 */
	public static function reescape_markup_fields( string $json ): string {
		foreach ( array( 'gutenberg_markup', 'custom_css', 'custom_js' ) as $key ) {
			$json = self::reescape_field( $json, $key );
		}

		return $json;
	}

	private static function reescape_field( string $json, string $key ): string {
		$needle = '"' . $key . '"';
		$pos    = strpos( $json, $needle );
		if ( false === $pos ) {
			return $json;
		}
		$colon = strpos( $json, ':', $pos + strlen( $needle ) );
		if ( false === $colon ) {
			return $json;
		}
		$i      = $colon + 1;
		$length = strlen( $json );
		while ( $i < $length && ctype_space( $json[ $i ] ) ) {
			++$i;
		}
		if ( $i >= $length || $json[ $i ] !== '"' ) {
			return $json;
		}
		$start = $i + 1;
		if ( ! preg_match( '/"\s*,\s*"(?:structures|custom_css|custom_js|required_media|block_title|gutenberg_markup|menu_items|form_fields)"|"\s*\}/', $json, $match, PREG_OFFSET_CAPTURE, $start ) ) {
			return $json;
		}
		$end = (int) $match[0][1];
		if ( $end <= $start ) {
			return $json;
		}
		$inner    = substr( $json, $start, $end - $start );
		$encoded  = wp_json_encode( $inner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return $json;
		}

		return substr( $json, 0, $start - 1 ) . $encoded . substr( $json, $end + 1 );
	}

	/**
	 * Last-resort field scrape when the object cannot be decoded.
	 *
	 * @return array<string, mixed>
	 */
	public static function extract_fields( string $text ): array {
		$text = self::normalize( $text );
		$out  = array();

		$title = self::extract_json_string( $text, 'block_title' );
		if ( $title !== null ) {
			$out['block_title'] = $title;
		}

		$markup = self::extract_json_string( $text, 'gutenberg_markup' );
		if ( $markup !== null ) {
			$out['gutenberg_markup'] = $markup;
		}

		$css = self::extract_json_string( $text, 'custom_css' );
		if ( $css !== null ) {
			$out['custom_css'] = $css;
		}

		$js = self::extract_json_string( $text, 'custom_js' );
		if ( $js !== null ) {
			$out['custom_js'] = $js;
		}

		$structures = self::extract_json_array( $text, 'structures' );
		if ( $structures !== null ) {
			$out['structures'] = $structures;
		}

		$media = self::extract_json_array( $text, 'required_media' );
		if ( $media !== null ) {
			$out['required_media'] = $media;
		}

		return $out;
	}

	private static function extract_json_string( string $json, string $key ): ?string {
		$needle = '"' . $key . '"';
		$pos    = strpos( $json, $needle );
		if ( false === $pos ) {
			return null;
		}

		$colon = strpos( $json, ':', $pos + strlen( $needle ) );
		if ( false === $colon ) {
			return null;
		}

		$i      = $colon + 1;
		$length = strlen( $json );
		while ( $i < $length && ctype_space( $json[ $i ] ) ) {
			++$i;
		}
		if ( $i >= $length || $json[ $i ] !== '"' ) {
			return null;
		}

		++$i;
		$raw    = '';
		$escape = false;
		for ( ; $i < $length; $i++ ) {
			$char = $json[ $i ];
			if ( $escape ) {
				$raw   .= $char;
				$escape = false;
				continue;
			}
			if ( $char === '\\' ) {
				$raw   .= $char;
				$escape = true;
				continue;
			}
			if ( $char === '"' ) {
				$decoded = json_decode( '"' . $raw . '"' );
				return is_string( $decoded ) ? $decoded : stripcslashes( $raw );
			}
			$raw .= $char;
		}

		$decoded = json_decode( '"' . $raw . '"' );
		return is_string( $decoded ) ? $decoded : stripcslashes( $raw );
	}

	/**
	 * @return array<int, mixed>|null
	 */
	private static function extract_json_array( string $json, string $key ): ?array {
		$needle = '"' . $key . '"';
		$pos    = strpos( $json, $needle );
		if ( false === $pos ) {
			return null;
		}
		$colon = strpos( $json, ':', $pos + strlen( $needle ) );
		if ( false === $colon ) {
			return null;
		}
		$i      = $colon + 1;
		$length = strlen( $json );
		while ( $i < $length && ctype_space( $json[ $i ] ) ) {
			++$i;
		}
		if ( $i >= $length || $json[ $i ] !== '[' ) {
			return null;
		}

		$slice = self::close_truncated( substr( $json, $i ) );
		$decoded = json_decode( $slice, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}

<?php
/**
 * Score Pass-1 compiler output for fidelity gaps (source-agnostic).
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Fidelity_Confidence {

	public const THRESHOLD_AUTO   = 72;
	public const THRESHOLD_STRICT = 90;

	/**
	 * @param array<string, mixed> $result Source_Compiler / Chunked_Generator payload.
	 * @return array{
	 *   score:int,
	 *   issues:array<int, string>,
	 *   structures:array<int, array{index:int, type:string, title:string, score:int, issues:array<int, string>}>,
	 *   needs_refine:array<int, int>
	 * }
	 */
	public static function assess( array $result, string $mode = 'auto' ): array {
		$structures = is_array( $result['structures'] ?? null ) ? $result['structures'] : array();
		$rows       = array();
		$needs      = array();
		$all_issues = array();
		$sum        = 0;
		$count      = 0;

		$types = array();
		foreach ( $structures as $structure ) {
			if ( is_array( $structure ) ) {
				$types[ (string) ( $structure['type'] ?? '' ) ] = true;
			}
		}

		$full_source = (string) ( $result['source_html'] ?? '' );
		if ( $full_source !== '' && preg_match( '/<header\b/i', $full_source ) && empty( $types['header'] ) ) {
			$all_issues[] = 'missing_header_structure';
		}
		if ( $full_source !== '' && preg_match( '/<footer\b/i', $full_source ) && empty( $types['footer'] ) ) {
			$all_issues[] = 'missing_footer_structure';
		}

		foreach ( $structures as $i => $structure ) {
			if ( ! is_array( $structure ) ) {
				continue;
			}
			$row = self::score_structure( $structure, $i );
			$rows[] = $row;
			$sum   += $row['score'];
			++$count;
			foreach ( $row['issues'] as $issue ) {
				$all_issues[] = $issue;
			}
			$cut = $mode === 'strict' ? self::THRESHOLD_STRICT : self::THRESHOLD_AUTO;
			if ( $row['score'] < $cut || $row['issues'] !== array() ) {
				if ( $mode === 'strict' || $row['score'] < self::THRESHOLD_AUTO || self::is_blocking( $row['issues'] ) ) {
					$needs[] = $i;
				}
			}
		}

		$score = $count > 0 ? (int) round( $sum / $count ) : 0;
		if ( in_array( 'missing_header_structure', $all_issues, true ) || in_array( 'missing_footer_structure', $all_issues, true ) ) {
			$score = max( 0, $score - 8 );
		}

		$markup = (string) ( $result['gutenberg_markup'] ?? '' );
		if ( $markup !== '' && self::is_whole_page_html( $markup ) ) {
			$all_issues[] = 'whole_page_html';
			$score        = min( $score, 40 );
			if ( $needs === array() && $structures !== array() ) {
				$needs = array_keys( $structures );
			}
		}

		$all_issues = array_values( array_unique( $all_issues ) );

		return array(
			'score'        => max( 0, min( 100, $score ) ),
			'issues'       => $all_issues,
			'structures'   => $rows,
			'needs_refine' => array_values( array_unique( array_map( 'intval', $needs ) ) ),
		);
	}

	/**
	 * @param array<string, mixed> $structure
	 * @return array{index:int, type:string, title:string, score:int, issues:array<int, string>}
	 */
	public static function score_structure( array $structure, int $index = 0 ): array {
		$type   = (string) ( $structure['type'] ?? 'section' );
		$title  = (string) ( $structure['title'] ?? $type );
		$markup = (string) ( $structure['gutenberg_markup'] ?? '' );
		$source = (string) ( $structure['source_html'] ?? '' );
		$issues = array();
		$score  = 100;

		if ( $markup === '' || ! str_contains( $markup, '<!-- wp:' ) ) {
			$issues[] = 'empty_markup';

			return array(
				'index'  => $index,
				'type'   => $type,
				'title'  => $title,
				'score'  => 0,
				'issues' => $issues,
			);
		}

		$blob = $markup . "\n" . $source;

		if ( preg_match( '/,\s*\)\s*\}|className\s*=|=>\s*\{|\bundefined\b|\bnull\s*&&/', $markup ) ) {
			$issues[] = 'leftover_jsx';
			$score   -= 25;
		}
		// A leading backslash is a real JSON escape, as in
		// Design_Html::repair_unicode_artifacts(): serialize_block_attributes()
		// writes `--`, `<`, `>` and `&` as -, <, > and &, so
		// every var(--x) in a block attribute read as damage and sent the
		// structure to an LLM refine.
		if ( preg_match( '/(?<![A-Za-z0-9_\\\\])u00[0-9a-fA-F]{2}(?![A-Za-z0-9_])/', $blob ) ) {
			$issues[] = 'unicode_artifact';
			$score   -= 10;
		}
		if ( str_contains( $markup, 'style-hover' ) || str_contains( $markup, 'styleHover' ) ) {
			$issues[] = 'style_hover_raw';
			$score   -= 12;
		}
		if ( $type === 'blog' && ! str_contains( $markup, '<!-- wp:query' ) ) {
			$issues[] = 'blog_missing_query';
			$score   -= 30;
		}
		if ( $type === 'form' && ! str_contains( $markup, 'wp:dxai-ui/form' ) && str_contains( $source, '<form' ) ) {
			$issues[] = 'form_missing_block';
			$score   -= 20;
		}
		if ( $type === 'slider' && ! str_contains( $markup, 'wp:dxai-ui/slider' ) ) {
			$issues[] = 'slider_missing_block';
			$score   -= 15;
		}
		if ( self::is_whole_page_html( $markup ) && in_array( $type, array( 'hero', 'section', 'blog' ), true ) ) {
			// Single html for a whole structure is OK for Tier C chrome; only
			// penalize when source suggests editable leaves (many headings).
			if ( $source !== '' && preg_match_all( '/<h[1-3]\b/i', $source ) >= 3 && substr_count( $markup, '<!-- wp:' ) <= 2 ) {
				$issues[] = 'under_structured';
				$score   -= 10;
			}
		}

		$class_gap = self::class_retention_gap( $source, $markup );
		if ( $class_gap > 0.35 ) {
			$issues[] = 'class_gap';
			$score   -= (int) round( 20 * $class_gap );
		}

		if ( str_contains( $markup, 'is-layout-flow' ) && preg_match( '/(?:^|\s)(?:flex|grid)(?:\s|$)/', $markup ) ) {
			$issues[] = 'layout_flow_fight';
			$score   -= 15;
		}

		$score = max( 0, min( 100, $score ) );

		return array(
			'index'  => $index,
			'type'   => $type,
			'title'  => $title,
			'score'  => $score,
			'issues' => $issues,
		);
	}

	/**
	 * @param array<int, string> $issues
	 */
	private static function is_blocking( array $issues ): bool {
		foreach ( $issues as $issue ) {
			if ( in_array( $issue, array( 'leftover_jsx', 'blog_missing_query', 'empty_markup', 'whole_page_html', 'unicode_artifact' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function is_whole_page_html( string $markup ): bool {
		$opens = preg_match_all( '/<!-- wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)/', $markup, $m );
		if ( $opens === 1 && isset( $m[1][0] ) && $m[1][0] === 'html' ) {
			return strlen( $markup ) > 4000;
		}

		return false;
	}

	/**
	 * Fraction of notable source class tokens missing from markup (0–1).
	 */
	private static function class_retention_gap( string $source, string $markup ): float {
		if ( $source === '' || strlen( $source ) < 80 ) {
			return 0.0;
		}
		if ( ! preg_match_all( '/\bclass(?:Name)?=["\']([^"\']+)["\']/', $source, $matches ) ) {
			return 0.0;
		}
		$tokens = array();
		foreach ( $matches[1] as $list ) {
			foreach ( preg_split( '/\s+/', trim( (string) $list ) ) ?: array() as $token ) {
				$token = trim( $token );
				if ( $token === '' || strlen( $token ) < 3 ) {
					continue;
				}
				// Skip generic / WP / state noise.
				if ( preg_match( '/^(wp-|is-|has-|aria-|data-|dxai-sh-)/', $token ) ) {
					continue;
				}
				$tokens[ $token ] = true;
			}
		}
		$tokens = array_keys( $tokens );
		if ( $tokens === array() ) {
			return 0.0;
		}
		// Cap sample size for speed.
		if ( count( $tokens ) > 60 ) {
			$tokens = array_slice( $tokens, 0, 60 );
		}
		$missing = 0;
		foreach ( $tokens as $token ) {
			if ( ! str_contains( $markup, $token ) ) {
				++$missing;
			}
		}

		return $missing / max( 1, count( $tokens ) );
	}
}

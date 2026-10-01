<?php
/**
 * The prompt for a page's words and the writer that puts an AI's answer on the page, against a page made for the
 * purpose and a stub engine (no provider is asked).
 *
 *   bash bin/wp-php.sh bin/verify-copy.php        (or: wp eval-file bin/verify-copy.php --user=1)
 *
 * Makes one draft page and removes it again. Exit code 1 when a check fails.
 *
 * @package DXAI_UI
 */

use DXAI_UI\Pages\Copy_Prompt;
use DXAI_UI\Pages\Copy_Writer;

$fail   = 0;
$pass   = 0;
$expect = static function ( string $what, bool $ok, string $detail = '' ) use ( &$fail, &$pass ): void {
	if ( $ok ) {
		++$pass;
		echo "  ok    $what\n";
	} else {
		++$fail;
		echo "  FAIL  $what" . ( $detail !== '' ? "  — $detail" : '' ) . "\n";
	}
};

echo "The team's prompt\n";
$t = Copy_Prompt::template();
$expect( 'three blanks: the staging page, the topic, the reference page', substr_count( $t, '{STAGING}' ) === 1 && substr_count( $t, '{TOPIC}' ) === 1 && substr_count( $t, '{REFERENCE}' ) === 1 );
$expect( 'the words are the team\'s', str_starts_with( $t, 'Please inspect this staging page and analyze every piece of visible text:' ) && str_contains( $t, 'Then provide the full replacement copy block by block in a clean, paste-ready format.' ) && str_contains( $t, '* Final CTA' ) );

echo "\nWhat a page is\n";
$kinds = array(
	array( 'Water Damage Restoration in Forest, VA', 'water-damage-restoration-in-forest-va', 'location' ),
	array( 'Water Damage Chesapeake, VA', 'page-245', 'location' ),
	array( 'Mold Remediation', 'mold-remediation', 'service' ),
	array( 'About Us', 'about-us', 'about' ),
	array( 'Contact Semper Dry for Water & Mold Damage Help', 'contact', 'contact' ),
	array( 'Frequently Asked Questions', 'faq', 'faq' ),
	array( 'Customer Reviews', 'testimonials', 'testimonials' ),
	array( 'Services', 'services', 'services' ),
	array( 'Service Areas', 'service-area', 'areas' ),
	array( 'Sewer Backup', 'sewer-backup', 'service' ),
);
foreach ( $kinds as $k ) {
	$got = Copy_Prompt::kind( $k[0], $k[1] );
	$expect( "\"{$k[0]}\" is a {$k[2]}", $got === $k[2], $got );
}

echo "\nA page\n";
$body = '<!-- wp:group {"metadata":{"name":"Hero Section"}} --><div class="wp-block-group"><!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Water damage in Tyler</h1><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>We serve <strong>Tyler</strong> &amp; Dallas.</p><!-- /wp:paragraph -->'
	. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="tel:1">Call now</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
	. '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="x.png" alt="Truck"/></figure><!-- /wp:image -->'
	. '<!-- wp:paragraph --><p><i data-lucide="phone"></i></p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '<!-- wp:group {"metadata":{"name":"FAQ Section"}} --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">Questions</h2><!-- /wp:heading --></div><!-- /wp:group -->';
$id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'draft',
		'post_title'   => 'Water Damage in Tyler, TX',
		'post_name'    => 'water-damage-tyler-tx',
		'post_content' => $body,
	)
);
$expect( 'a page to test on', is_int( $id ) && $id > 0 );
if ( ! is_int( $id ) || $id < 1 ) {
	exit( 1 );
}
$f = Copy_Prompt::fields( $id );
$expect( 'the topic is its title', $f['topic'] === 'Water Damage in Tyler, TX', $f['topic'] );
$expect( 'it is a location', $f['kind'] === 'location', $f['kind'] );
$expect( 'the staging address is its own', str_contains( $f['staging'], (string) $id ) || str_contains( $f['staging'], 'water-damage-tyler-tx' ), $f['staging'] );
$expect( 'no old site: no reference page', $f['reference'] === '' );

$p = Copy_Prompt::prompt( $id );
$expect( 'the staging address and the topic are in the prompt', str_contains( $p, $f['staging'] ) && str_contains( $p, "This page should be about:\nWater Damage in Tyler, TX" ) );
$expect( 'without a reference page its two lines leave', ! str_contains( $p, 'Use this production page' ) && ! str_contains( $p, '{REFERENCE}' ) );
$expect( 'no blank is left', ! str_contains( $p, '{STAGING}' ) && ! str_contains( $p, '{TOPIC}' ) );

Copy_Prompt::save( $id, array( 'topic' => 'Flood cleanup in Tyler, TX', 'reference' => 'https://example.org/flood/' ) );
$p = Copy_Prompt::prompt( $id );
$expect( 'what was typed is saved and used', str_contains( $p, "Flood cleanup in Tyler, TX\nUse this production page as content inspiration where relevant:\nhttps://example.org/flood/" ) );
$expect( 'a reference that is not an address is dropped', ( static function () use ( $id ) {
	Copy_Prompt::save( $id, array( 'topic' => 'X topic', 'reference' => 'javascript:alert(1)' ) );
	return Copy_Prompt::fields( $id )['reference'] === '';
} )() );
Copy_Prompt::save( $id, array() );
$expect( 'empty fields go back to what is worked out', Copy_Prompt::fields( $id )['topic'] === 'Water Damage in Tyler, TX' && ! Copy_Prompt::fields( $id )['saved'] );

echo "\nThe words of a page\n";
$inv = Copy_Writer::inventory( $id );
$kinds_found = array_column( $inv, 'kind' );
$expect( 'heading, paragraph, button and the next heading are listed; the icon and the picture are not', $kinds_found === array( 'heading', 'paragraph', 'button', 'heading' ), implode( ',', $kinds_found ) );
$expect( 'each is placed in the section the team named', array_column( $inv, 'section' ) === array( 'Hero Section', 'Hero Section', 'Hero Section', 'FAQ Section' ), implode( ',', array_column( $inv, 'section' ) ) );
$expect( 'inline markup is kept in the words', str_contains( $inv[1]['html'], '<strong>Tyler</strong>' ) );

$stub = new class() extends DXAI_UI\Engines\Abstract_Engine {
	public ?string $got = null;
	public string $reply = '';
	public function get_id(): string { return 'stub'; }
	public function get_label(): string { return 'Stub'; }
	public function test_connection(): bool|\WP_Error { return true; }
	public function generate( string $system, string $user, array $args = array() ): array|\WP_Error { return new \WP_Error( 'x', 'no' ); }
	public function complete( string $system, string $user, array $args = array() ): string|\WP_Error {
		$this->got = $user;
		return $this->reply;
	}
};
$stub->reply = wp_json_encode(
	array(
		'blocks' => array(
			array( 'id' => 't1', 'text' => 'Water damage repair in Tyler, TX' ),
			array( 'id' => 't2', 'text' => 'Serving <strong>Tyler</strong> <script>bad()</script>and Dallas.' ),
			array( 'id' => 't3', 'text' => 'Call (903) 555-0100' ),
			array( 'id' => 'zz', 'text' => 'not a block' ),
		),
		'flags'  => array( 'The FAQ names another city' ),
	)
);
add_filter( 'dxai_ui_copy_engine', static fn() => $stub );

$before = (string) get_post_field( 'post_content', $id );
$prop   = Copy_Writer::propose( $id );
$expect( 'the AI is asked with the team\'s prompt and the words as numbered blocks', is_string( $stub->got ) && str_starts_with( $stub->got, 'Please inspect this staging page' ) && str_contains( $stub->got, "\nBLOCKS:\n" ) && str_contains( $stub->got, '"id":"t3"' ) );
$expect( 'an answer is a proposal, nothing is saved', ! is_wp_error( $prop ) && (string) get_post_field( 'post_content', $id ) === $before );
$expect( 'three blocks changed, the unknown id is ignored, the note is kept', ! is_wp_error( $prop ) && $prop['changed'] === 3 && $prop['flags'] === array( 'The FAQ names another city' ) );
$expect( 'a tag that is not copy is taken out of the words', ! is_wp_error( $prop ) && ! str_contains( $prop['blocks'][1]['after'], 'script' ) );

$changes = array();
foreach ( $prop['blocks'] as $b ) {
	if ( $b['changed'] ) {
		$changes[ $b['id'] ] = $b['after'];
	}
}
$stale = Copy_Writer::apply( $id, $changes, 'not-the-fingerprint' );
$expect( 'words written for other words are refused', is_wp_error( $stale ) && $stale->get_error_code() === 'dxai_ui_copy_stale' );

$done = Copy_Writer::apply( $id, array( 't1' => $changes['t1'], 't3' => $changes['t3'] ), $prop['fingerprint'] );
$after = (string) get_post_field( 'post_content', $id );
$expect( 'the chosen blocks are applied, the others stay', ! is_wp_error( $done ) && $done['applied'] === 2 && str_contains( $after, 'Water damage repair in Tyler, TX' ) && str_contains( $after, 'Call (903) 555-0100' ) && str_contains( $after, 'We serve <strong>Tyler</strong>' ) );
$names = static function ( string $c ): array {
	$o    = array();
	$walk = static function ( array $bl ) use ( &$walk, &$o ): void {
		foreach ( $bl as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$o[] = $b['blockName'] . '|' . ( $b['attrs']['className'] ?? '' ) . '|' . ( $b['attrs']['metadata']['name'] ?? '' );
				$walk( $b['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $c ) );
	return $o;
};
$expect( 'only words changed: the blocks, classes and names are the same', $names( $before ) === $names( $after ) );
$expect( 'the button keeps its link', str_contains( $after, 'href="tel:1">Call (903) 555-0100</a>' ) );
$expect( 'the page can be put back', Copy_Writer::can_revert( $id ) );

wp_update_post( array( 'ID' => $id, 'post_content' => $after . "\n<!-- wp:paragraph --><p>Edited by a person.</p><!-- /wp:paragraph -->" ) );
$expect( 'a page edited since is left alone', Copy_Writer::revert( $id ) === false );
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => $after ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
clean_post_cache( $id );
$expect( 'an untouched page goes back byte for byte', Copy_Writer::revert( $id ) === true && (string) get_post_field( 'post_content', $id ) === $before );

echo "\nWhat the AI can get wrong\n";
$stub->reply = 'Sure! Here is the copy you asked for.';
$bad = Copy_Writer::propose( $id );
$expect( 'an answer that is not JSON changes nothing', is_wp_error( $bad ) && $bad->get_error_code() === 'dxai_ui_copy_parse' && (string) get_post_field( 'post_content', $id ) === $before );
$stub->reply = wp_json_encode( array( 'blocks' => array( array( 'id' => 't1', 'text' => '   ' ) ), 'flags' => array() ) );
$empty = Copy_Writer::propose( $id );
$expect( 'an empty line is not a change', ! is_wp_error( $empty ) && $empty['changed'] === 0 );
$expect( 'the words can be read from an answer fenced in a code block', ! is_wp_error( Copy_Writer::parse( "```json\n{\"blocks\":[{\"id\":\"t1\",\"text\":\"A\"}],\"flags\":[]}\n```" ) ) );

wp_delete_post( $id, true );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );

<?php
/**
 * The top of every page this theme draws: the document head, the site's header.
 *
 * @package DX_Base
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'dx-base' ); ?>>
<?php wp_body_open(); ?>
<?php dx_base_header(); ?>
<main id="main" class="dx-base-main">

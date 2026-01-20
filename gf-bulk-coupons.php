<?php
/**
 * Plugin Name: GF Bulk Coupons
 * Description: Genera cupones en masa para Gravity Forms Coupons Add-On y permite gestionarlos desde el admin.
 * Version: 1.0.0
 * Author: Comunicación Online 360, SLU
 * Text Domain: gf-bulk-coupons
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFBCU_VERSION', '1.0.0' );

define( 'GFBCU_PATH', plugin_dir_path( __FILE__ ) );

define( 'GFBCU_URL', plugin_dir_url( __FILE__ ) );

define( 'GFBCU_TEXT_DOMAIN', 'gf-bulk-coupons' );

require_once GFBCU_PATH . 'includes/class-bulk-generator.php';
require_once GFBCU_PATH . 'includes/admin-pages.php';
require_once GFBCU_PATH . 'includes/class-coupons-list-table.php';
require_once GFBCU_PATH . 'includes/export.php';
require_once GFBCU_PATH . 'includes/class-redemptions.php';

register_activation_hook( __FILE__, 'gfbcu_activate' );

add_action( 'plugins_loaded', 'gfbcu_init' );

/**
 * Initialize plugin.
 */
function gfbcu_init() {
	load_plugin_textdomain( GFBCU_TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	GFBCU_Bulk_Generator::get_instance();
	GFBCU_Admin_Pages::get_instance();
	GFBCU_Export::get_instance();
	GFBCU_Redemptions::get_instance();
}

/**
 * Check if Gravity Forms is active.
 *
 * @return bool
 */
function gfbcu_has_gravity_forms() {
	return class_exists( 'GFForms' );
}

/**
 * Check if Gravity Forms Coupons Add-On is active.
 *
 * @return bool
 */
function gfbcu_has_coupons_addon() {
	return class_exists( 'GFCoupons' ) || class_exists( 'GFCouponsAddOn' ) || function_exists( 'gf_coupons' );
}

/**
 * Get supported coupon types.
 *
 * @return array<string,string>
 */
function gfbcu_get_coupon_types() {
	return array(
		'percentage' => __( 'Porcentaje', GFBCU_TEXT_DOMAIN ),
		'flat'       => __( 'Importe fijo', GFBCU_TEXT_DOMAIN ),
	);
}

/**
 * Get admin base URL for plugin pages.
 *
 * @param string $page
 * @return string
 */
function gfbcu_admin_url( $page ) {
	return admin_url( 'admin.php?page=' . $page );
}

/**
 * Run activation tasks.
 */
function gfbcu_activate() {
	GFBCU_Bulk_Generator::activate();
	GFBCU_Redemptions::activate();
}

/**
 * Normalize coupon prefix to A-Z and 0-9.
 *
 * @param string $prefix
 * @return array{original:string,normalized:string,changed:bool}
 */
function gfbcu_normalize_coupon_prefix( $prefix ) {
	$original   = sanitize_text_field( $prefix );
	$normalized = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $original ) );

	return array(
		'original'   => $original,
		'normalized' => $normalized,
		'changed'    => $original !== $normalized,
	);
}

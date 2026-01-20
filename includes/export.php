<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBCU_Export {
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_gfbcu_export', array( $this, 'export_recent' ) );
		add_action( 'admin_post_gfbcu_export_filtered', array( $this, 'export_filtered' ) );
	}

	public function export_recent() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', GFBCU_TEXT_DOMAIN ) );
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		check_admin_referer( 'gfbcu_export_' . $token );

		$data = get_transient( 'gfbcu_recent_' . $token );
		if ( ! $data ) {
			wp_die( esc_html__( 'No hay datos para exportar.', GFBCU_TEXT_DOMAIN ) );
		}

		$this->output_csv( $data['codes'], $data['form_id'], $data );
	}

	public function export_filtered() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', GFBCU_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'gfbcu_export_filtered' );

		$generator = GFBCU_Bulk_Generator::get_instance();
		$filters = array(
			'form_id' => isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0,
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'  => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
		);

		$result = $generator->query_coupons(
			array(
				'form_id' => $filters['form_id'],
				'search'  => $filters['search'],
				'status'  => $filters['status'],
			)
		);

		$codes = wp_list_pluck( $result['items'], 'code' );
		$this->output_csv( $codes, $filters['form_id'], array( 'items' => $result['items'] ) );
	}

	private function output_csv( $codes, $form_id, $data ) {
		$generator = GFBCU_Bulk_Generator::get_instance();
		$form_title = $form_id ? $generator->get_form_title( $form_id ) : '';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=gfbcu-coupons.csv' );

		$output = fopen( 'php://output', 'w' );
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv( $output, array( 'code', 'form_id', 'form_title', 'type', 'amount', 'usage_limit', 'expiration', 'created_at', 'campaign', 'uses' ) );

		$campaign_map = $generator->get_campaign_map( $codes, $form_id );
		$items = isset( $data['items'] ) ? $data['items'] : array();
		$items_by_code = array();
		foreach ( $items as $item ) {
			$items_by_code[ $item['code'] ] = $item;
		}

		foreach ( $codes as $code ) {
			$item = isset( $items_by_code[ $code ] ) ? $items_by_code[ $code ] : array();
			$uses = isset( $item['usage_count'] ) ? $item['usage_count'] : $generator->get_usage_count( $form_id, $code );
			fputcsv(
				$output,
				array(
					$code,
					$form_id,
					$form_title,
					isset( $item['type'] ) ? $item['type'] : '',
					isset( $item['amount'] ) ? $item['amount'] : '',
					isset( $item['usage_limit'] ) ? $item['usage_limit'] : '',
					isset( $item['expiration'] ) ? $item['expiration'] : '',
					isset( $item['created_at'] ) ? $item['created_at'] : '',
					isset( $campaign_map[ $code ] ) ? $campaign_map[ $code ] : '',
					is_null( $uses ) ? '' : $uses,
				)
			);
		}

		fclose( $output );
		exit;
	}
}

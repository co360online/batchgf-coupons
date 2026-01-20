<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBCU_Bulk_Generator {
	private static $instance;

	private $meta_table;
	private $addon_slug;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->meta_table = $wpdb->prefix . 'gfbcu_coupon_meta';
	}

	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'gfbcu_coupon_meta';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_code varchar(255) NOT NULL,
			form_id bigint(20) unsigned NOT NULL,
			campaign varchar(255) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY coupon_code (coupon_code),
			KEY form_id (form_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function get_feed_table() {
		global $wpdb;
		return $wpdb->prefix . 'gf_addon_feed';
	}

	public function get_addon_slug() {
		if ( null === $this->addon_slug ) {
			$this->addon_slug = 'gravityformscoupons';
		}
		return $this->addon_slug;
	}

	public function supports_stackable() {
		if ( ! function_exists( 'gf_coupons' ) ) {
			return false;
		}

		$addon = gf_coupons();
		if ( ! $addon || ! method_exists( $addon, 'get_feed_fields' ) ) {
			return false;
		}

		$fields = $addon->get_feed_fields();
		foreach ( $fields as $field ) {
			if ( isset( $field['name'] ) && 'stackable' === $field['name'] ) {
				return true;
			}
		}

		return false;
	}

	public function get_meta_table() {
		return $this->meta_table;
	}

	public function get_coupon_table_ready() {
		return true;
	}

	public function get_forms() {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		$forms = GFAPI::get_forms();
		if ( ! is_array( $forms ) ) {
			return array();
		}

		return $forms;
	}

	public function get_form_title( $form_id ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return '';
		}

		$form = GFAPI::get_form( $form_id );
		return $form && isset( $form['title'] ) ? $form['title'] : '';
	}

	public function generate_coupons( $args ) {
		if ( ! $this->get_addon_slug() ) {
			return new WP_Error( 'gfbcu_missing_slug', __( 'No se pudo detectar el slug del Add-On de cupones.', GFBCU_TEXT_DOMAIN ) );
		}

		$defaults = array(
			'form_id'        => 0,
			'amount'         => 0,
			'type'           => 'flat',
			'count'          => 0,
			'prefix'         => '',
			'length'         => 8,
			'usage_limit'    => 1,
			'unlimited'      => false,
			'expiration'     => '',
			'campaign'       => '',
			'is_stackable'   => false,
			'mode'           => 'random',
			'increment_start'=> 1,
		);

		$args = wp_parse_args( $args, $defaults );
		$codes = array();

		$existing = $this->get_existing_codes( $args['form_id'] );
		$attempts = 0;
		$max_attempts = $args['count'] * 10;

		while ( count( $codes ) < (int) $args['count'] && $attempts < $max_attempts ) {
			$attempts++;
			$code = $this->build_code( $args, $codes );
			if ( isset( $existing[ $code ] ) || isset( $codes[ $code ] ) ) {
				continue;
			}

			$inserted = $this->insert_coupon( $code, $args );
			if ( is_wp_error( $inserted ) ) {
				return $inserted;
			}

			$codes[] = $code;
			$existing[ $code ] = true;
		}

		if ( count( $codes ) < (int) $args['count'] ) {
			return new WP_Error( 'gfbcu_generation_failed', __( 'No se pudieron generar todos los cupones por colisiones de código.', GFBCU_TEXT_DOMAIN ) );
		}

		if ( $args['campaign'] ) {
			$this->store_campaign_meta( $codes, $args['form_id'], $args['campaign'] );
		}

		return $codes;
	}

	public function build_code( $args, $current_codes ) {
		$prefix = $args['prefix'];
		$length = max( 1, (int) $args['length'] );

		if ( 'incremental' === $args['mode'] ) {
			$index = (int) $args['increment_start'] + count( $current_codes );
			$random = str_pad( (string) $index, $length, '0', STR_PAD_LEFT );
			return $this->sanitize_code( $prefix . $random );
		}

		$characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$random = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$random .= $characters[ wp_rand( 0, strlen( $characters ) - 1 ) ];
		}

		return $this->sanitize_code( $prefix . $random );
	}

	public function get_existing_codes( $form_id ) {
		global $wpdb;
		$table = $this->get_feed_table();
		$slug = $this->get_addon_slug();

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta FROM {$table} WHERE addon_slug = %s AND form_id = %d",
				$slug,
				$form_id
			)
		);

		$existing = array();
		foreach ( $results as $meta_json ) {
			$meta = json_decode( $meta_json, true );
			if ( empty( $meta['couponCode'] ) ) {
				continue;
			}
			$existing[ strtoupper( $meta['couponCode'] ) ] = true;
		}

		return $existing;
	}

	public function insert_coupon( $code, $args ) {
		$amount = $this->format_amount( $args['amount'], $args['type'] );
		$usage_limit = $args['unlimited'] ? '' : (string) (int) $args['usage_limit'];
		$meta = array(
			'gravityForm'      => (string) $args['form_id'],
			'couponName'       => $code,
			'couponCode'       => $code,
			'couponAmountType' => $args['type'],
			'couponAmount'     => $amount,
			'startDate'        => '',
			'endDate'          => $args['expiration'],
			'usageLimit'       => $usage_limit,
			'isStackable'      => $args['is_stackable'] ? '1' : '0',
			'usageCount'       => '',
		);

		if ( function_exists( 'gf_coupons' ) ) {
			$addon = gf_coupons();
			if ( $addon && method_exists( $addon, 'insert_feed' ) ) {
				$result = $addon->insert_feed( (int) $args['form_id'], true, $meta );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return true;
			}
		}

		global $wpdb;
		$table = $this->get_feed_table();
		$slug  = $this->get_addon_slug();

		$inserted = $wpdb->insert(
			$table,
			array(
				'form_id'     => (int) $args['form_id'],
				'is_active'   => 1,
				'addon_slug'  => $slug,
				'meta'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
				'date_created'=> current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'gfbcu_insert_failed', __( 'Error al insertar cupón en la base de datos.', GFBCU_TEXT_DOMAIN ) );
		}

		return true;
	}

	public function store_campaign_meta( array $codes, $form_id, $campaign ) {
		global $wpdb;
		$table = $this->get_meta_table();
		$now = current_time( 'mysql' );

		foreach ( $codes as $code ) {
			$wpdb->insert(
				$table,
				array(
					'coupon_code' => $code,
					'form_id'     => (int) $form_id,
					'campaign'    => $campaign,
					'created_at'  => $now,
				),
				array( '%s', '%d', '%s', '%s' )
			);
		}
	}

	public function get_campaign_map( array $codes, $form_id ) {
		if ( empty( $codes ) ) {
			return array();
		}

		global $wpdb;
		$table = $this->get_meta_table();

		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		if ( $form_id ) {
			$query = $wpdb->prepare(
				"SELECT coupon_code, campaign FROM {$table} WHERE form_id = %d AND coupon_code IN ({$placeholders})",
				array_merge( array( $form_id ), $codes )
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT coupon_code, campaign FROM {$table} WHERE coupon_code IN ({$placeholders})",
				$codes
			);
		}

		$results = $wpdb->get_results( $query, ARRAY_A );
		$map = array();
		foreach ( $results as $row ) {
			$map[ $row['coupon_code'] ] = $row['campaign'];
		}

		return $map;
	}

	public function query_coupons( $args ) {
		global $wpdb;
		$table = $this->get_feed_table();
		$slug  = $this->get_addon_slug();
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';

		$where = array( 'addon_slug = %s' );
		$params = array( $slug );

		if ( ! empty( $args['form_id'] ) ) {
			$where[] = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$query = "SELECT id, form_id, is_active, meta, date_created FROM {$table} {$where_sql} ORDER BY id DESC";
		$query = $wpdb->prepare( $query, $params );
		$rows = $wpdb->get_results( $query, ARRAY_A );

		$items = array();
		foreach ( $rows as $row ) {
			$meta = json_decode( $row['meta'], true );
			if ( empty( $meta['couponCode'] ) ) {
				continue;
			}

			$item = array(
				'id'          => (int) $row['id'],
				'form_id'     => (int) $row['form_id'],
				'is_active'   => (int) $row['is_active'],
				'name'        => isset( $meta['couponName'] ) ? $meta['couponName'] : '',
				'code'        => $meta['couponCode'],
				'type'        => isset( $meta['couponAmountType'] ) ? $meta['couponAmountType'] : '',
				'amount'      => isset( $meta['couponAmount'] ) ? $meta['couponAmount'] : '',
				'usage_limit' => isset( $meta['usageLimit'] ) ? $meta['usageLimit'] : '',
				'usage_count' => isset( $meta['usageCount'] ) && '' !== $meta['usageCount'] ? (int) $meta['usageCount'] : null,
				'is_stackable'=> isset( $meta['isStackable'] ) ? $meta['isStackable'] : '0',
				'expiration'  => isset( $meta['endDate'] ) ? $meta['endDate'] : '',
				'created_at'  => $row['date_created'],
			);

			if ( ! empty( $args['search'] ) && false === stripos( $item['code'], $args['search'] ) ) {
				continue;
			}

			if ( $status && ! $this->matches_status( $status, $item ) ) {
				continue;
			}

			$items[] = $item;
		}

		$total = count( $items );

		if ( ! empty( $args['per_page'] ) ) {
			$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['per_page'];
			$items = array_slice( $items, $offset, (int) $args['per_page'] );
		}

		return array( 'items' => $items, 'total' => $total );
	}

	public function get_usage_count( $form_id, $code, $force = false ) {
		$cache_key = 'gfbcu_usage_' . md5( $form_id . '|' . $code );
		$cached = get_transient( $cache_key );
		if ( false !== $cached && ! $force ) {
			return (int) $cached;
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			return null;
		}

		$form = GFAPI::get_form( $form_id );
		if ( ! $form || empty( $form['fields'] ) ) {
			return null;
		}

		$coupon_fields = array();
		foreach ( $form['fields'] as $field ) {
			if ( isset( $field->type ) && 'coupon' === $field->type ) {
				$coupon_fields[] = $field->id;
			}
		}

		if ( empty( $coupon_fields ) ) {
			return null;
		}

		$filters = array();
		foreach ( $coupon_fields as $field_id ) {
			$filters[] = array(
				'key'   => (string) $field_id,
				'value' => $code,
			);
		}

		$criteria = array(
			'status'       => 'active',
			'field_filters'=> array(
				'mode' => 'any',
				'filters' => $filters,
			),
		);

		$count = GFAPI::count_entries( $form_id, $criteria );
		set_transient( $cache_key, (int) $count, 10 * MINUTE_IN_SECONDS );

		return (int) $count;
	}

	public function get_coupon_status( $item ) {
		$expiration = isset( $item['expiration'] ) ? $item['expiration'] : '';
		$usage_limit = isset( $item['usage_limit'] ) ? (int) $item['usage_limit'] : 0;
		$usage_count = isset( $item['usage_count'] ) ? (int) $item['usage_count'] : null;

		if ( $expiration ) {
			$timestamp = strtotime( $expiration );
			if ( $timestamp && $timestamp < current_time( 'timestamp' ) ) {
				return __( 'Expirado', GFBCU_TEXT_DOMAIN );
			}
		}

		if ( $usage_limit > 0 && null !== $usage_count && $usage_count >= $usage_limit ) {
			return __( 'Agotado', GFBCU_TEXT_DOMAIN );
		}

		if ( ! $expiration ) {
			return __( 'Sin expiración', GFBCU_TEXT_DOMAIN );
		}

		return __( 'Activo', GFBCU_TEXT_DOMAIN );
	}

	private function matches_status( $status, $item ) {
		$expiration = isset( $item['expiration'] ) ? $item['expiration'] : '';
		$usage_limit = isset( $item['usage_limit'] ) ? (int) $item['usage_limit'] : 0;
		$usage_count = isset( $item['usage_count'] ) ? (int) $item['usage_count'] : null;

		if ( 'expired' === $status ) {
			if ( ! $expiration ) {
				return false;
			}
			$timestamp = strtotime( $expiration );
			return $timestamp && $timestamp < current_time( 'timestamp' );
		}

		if ( 'no_expiration' === $status ) {
			return empty( $expiration );
		}

		if ( 'exhausted' === $status ) {
			return $usage_limit > 0 && null !== $usage_count && $usage_count >= $usage_limit;
		}

		if ( 'active' === $status ) {
			$expired = false;
			if ( $expiration ) {
				$timestamp = strtotime( $expiration );
				$expired = $timestamp && $timestamp < current_time( 'timestamp' );
			}
			$exhausted = $usage_limit > 0 && null !== $usage_count && $usage_count >= $usage_limit;
			return ! $expired && ! $exhausted;
		}

		return true;
	}

	private function sanitize_code( $code ) {
		$upper = strtoupper( $code );
		return preg_replace( '/[^A-Z0-9]/', '', $upper );
	}

	private function format_amount( $amount, $type ) {
		$formatted = number_format( (float) $amount, 2, ',', '' );
		if ( 'percentage' === $type ) {
			return $formatted . '%';
		}

		return $formatted . ' €';
	}
}

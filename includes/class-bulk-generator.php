<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBCU_Bulk_Generator {
	private static $instance;

	private $coupon_table;
	private $coupon_columns;
	private $meta_table;

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

	public function get_coupon_table() {
		if ( null !== $this->coupon_table ) {
			return $this->coupon_table;
		}

		global $wpdb;
		$table_candidates = array();

		if ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_table_name' ) ) {
			$possible = array( 'coupon', 'coupons' );
			foreach ( $possible as $name ) {
				$table = GFFormsModel::get_table_name( $name );
				if ( $table ) {
					$table_candidates[] = $table;
				}
			}
		}

		$like = $wpdb->esc_like( $wpdb->prefix . 'rg_gf_' ) . '%coupon%';
		$results = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		foreach ( $results as $table ) {
			$table_candidates[] = $table;
		}

		$table_candidates = array_unique( array_filter( $table_candidates ) );

		foreach ( $table_candidates as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$this->coupon_table = $table;
				return $this->coupon_table;
			}
		}

		$this->coupon_table = '';
		return $this->coupon_table;
	}

	public function get_coupon_columns() {
		if ( null !== $this->coupon_columns ) {
			return $this->coupon_columns;
		}

		$table = $this->get_coupon_table();
		if ( ! $table ) {
			$this->coupon_columns = array();
			return $this->coupon_columns;
		}

		global $wpdb;
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$names   = array();
		foreach ( $columns as $column ) {
			$names[] = $column['Field'];
		}

		$this->coupon_columns = $names;
		return $this->coupon_columns;
	}

	public function get_meta_table() {
		return $this->meta_table;
	}

	public function get_code_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'code', 'coupon_code' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_form_id_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'form_id', 'formId' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_usage_count_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'usage_count', 'usage', 'uses' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_usage_limit_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'usage_limit', 'usageLimit' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_expiration_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'expiration', 'expiration_date', 'date_expires' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_created_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'date_created', 'created_at', 'date_created_gmt' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_stackable_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'stackable', 'is_stackable', 'allow_stack' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_active_column() {
		$columns = $this->get_coupon_columns();
		$choices = array( 'is_active', 'active' );
		foreach ( $choices as $choice ) {
			if ( in_array( $choice, $columns, true ) ) {
				return $choice;
			}
		}

		return '';
	}

	public function get_coupon_table_ready() {
		$table = $this->get_coupon_table();
		$code  = $this->get_code_column();
		$form  = $this->get_form_id_column();

		return $table && $code && $form;
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
		if ( ! $this->get_coupon_table_ready() ) {
			return new WP_Error( 'gfbcu_missing_table', __( 'No se pudo detectar la tabla de cupones de Gravity Forms.', GFBCU_TEXT_DOMAIN ) );
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
		$prefix = strtoupper( $args['prefix'] );
		$length = max( 1, (int) $args['length'] );

		if ( 'incremental' === $args['mode'] ) {
			$index = (int) $args['increment_start'] + count( $current_codes );
			$random = str_pad( (string) $index, $length, '0', STR_PAD_LEFT );
			return $prefix . $random;
		}

		$characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
		$random = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$random .= $characters[ wp_rand( 0, strlen( $characters ) - 1 ) ];
		}

		return $prefix . $random;
	}

	public function get_existing_codes( $form_id ) {
		global $wpdb;
		$table   = $this->get_coupon_table();
		$code    = $this->get_code_column();
		$form    = $this->get_form_id_column();

		if ( ! $table || ! $code || ! $form ) {
			return array();
		}

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$code} FROM {$table} WHERE {$form} = %d",
				$form_id
			)
		);

		$existing = array();
		foreach ( $results as $value ) {
			$existing[ strtoupper( $value ) ] = true;
		}

		return $existing;
	}

	public function insert_coupon( $code, $args ) {
		global $wpdb;

		$table = $this->get_coupon_table();
		$columns = $this->get_coupon_columns();

		if ( ! $table ) {
			return new WP_Error( 'gfbcu_missing_table', __( 'No se detectó tabla de cupones.', GFBCU_TEXT_DOMAIN ) );
		}

		$data   = array();
		$format = array();

		$map = array(
			'form_id'        => (int) $args['form_id'],
			'code'           => $code,
			'coupon_code'    => $code,
			'name'           => $code,
			'type'           => $args['type'],
			'amount'         => $args['amount'],
			'usage_limit'    => $args['unlimited'] ? 0 : (int) $args['usage_limit'],
			'usageLimit'     => $args['unlimited'] ? 0 : (int) $args['usage_limit'],
			'expiration'     => $args['expiration'],
			'expiration_date'=> $args['expiration'],
			'date_expires'   => $args['expiration'],
			'is_active'      => 1,
			'active'         => 1,
			'created_at'     => current_time( 'mysql' ),
			'date_created'   => current_time( 'mysql' ),
			'date_created_gmt' => get_gmt_from_date( current_time( 'mysql' ) ),
		);

		$stackable_column = $this->get_stackable_column();
		if ( $stackable_column ) {
			$map[ $stackable_column ] = $args['is_stackable'] ? 1 : 0;
		}

		foreach ( $map as $column => $value ) {
			if ( in_array( $column, $columns, true ) ) {
				$data[ $column ] = $value;
				$format[] = is_numeric( $value ) && ! is_string( $value ) ? '%d' : '%s';
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'gfbcu_missing_columns', __( 'No se pudieron mapear columnas de cupón.', GFBCU_TEXT_DOMAIN ) );
		}

		$inserted = $wpdb->insert( $table, $data, $format );
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
		$table = $this->get_coupon_table();
		if ( ! $table ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$code_column = $this->get_code_column();
		$form_column = $this->get_form_id_column();
		$type_column = in_array( 'type', $this->get_coupon_columns(), true ) ? 'type' : '';
		$amount_column = in_array( 'amount', $this->get_coupon_columns(), true ) ? 'amount' : '';
		$usage_limit_column = $this->get_usage_limit_column();
		$usage_count_column = $this->get_usage_count_column();
		$expiration_column = $this->get_expiration_column();
		$created_column = $this->get_created_column();
		$active_column = $this->get_active_column();
		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';

		$where = array();
		$params = array();

		if ( ! empty( $args['form_id'] ) ) {
			$where[] = "{$form_column} = %d";
			$params[] = (int) $args['form_id'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[] = "{$code_column} LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( $status ) {
			$now = current_time( 'mysql' );
			if ( 'expired' === $status && $expiration_column ) {
				$where[] = "{$expiration_column} < %s";
				$params[] = $now;
			}

			if ( 'no_expiration' === $status && $expiration_column ) {
				$where[] = "( {$expiration_column} IS NULL OR {$expiration_column} = '' )";
			}

			if ( 'exhausted' === $status && $usage_limit_column && $usage_count_column ) {
				$where[] = "{$usage_limit_column} > 0 AND {$usage_count_column} >= {$usage_limit_column}";
			}

			if ( 'active' === $status ) {
				$conditions = array();
				if ( $expiration_column ) {
					$conditions[] = "( {$expiration_column} = '' OR {$expiration_column} IS NULL OR {$expiration_column} >= %s )";
					$params[] = $now;
				}
				if ( $usage_limit_column && $usage_count_column ) {
					$conditions[] = "( {$usage_limit_column} = 0 OR {$usage_count_column} < {$usage_limit_column} )";
				}
				if ( $conditions ) {
					$where[] = implode( ' AND ', $conditions );
				}
			}
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$selects = array(
			"{$code_column} AS code",
			"{$form_column} AS form_id",
		);

		if ( $type_column ) {
			$selects[] = "{$type_column} AS type";
		}
		if ( $amount_column ) {
			$selects[] = "{$amount_column} AS amount";
		}
		if ( $usage_limit_column ) {
			$selects[] = "{$usage_limit_column} AS usage_limit";
		}
		if ( $usage_count_column ) {
			$selects[] = "{$usage_count_column} AS usage_count";
		}
		if ( $expiration_column ) {
			$selects[] = "{$expiration_column} AS expiration";
		}
		if ( $created_column ) {
			$selects[] = "{$created_column} AS created_at";
		}
		if ( $active_column ) {
			$selects[] = "{$active_column} AS is_active";
		}

		$select_sql = implode( ',', $selects );

		$limit = '';
		if ( ! empty( $args['per_page'] ) ) {
			$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * (int) $args['per_page'];
			$limit = $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $args['per_page'], $offset );
		}

		$query = "SELECT {$select_sql} FROM {$table} {$where_sql} ORDER BY {$code_column} ASC {$limit}";
		if ( $params ) {
			$query = $wpdb->prepare( $query, $params );
		}

		$items = $wpdb->get_results( $query, ARRAY_A );

		$count_query = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( $params ) {
			$count_query = $wpdb->prepare( $count_query, $params );
		}
		$total = (int) $wpdb->get_var( $count_query );

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
}

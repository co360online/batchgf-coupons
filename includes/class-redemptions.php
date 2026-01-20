<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBCU_Redemptions {
	private static $instance;
	private $table;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'gfbcu_redemptions';

		add_action( 'gform_after_submission', array( $this, 'capture_redemption' ), 10, 2 );
	}

	public static function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'gfbcu_redemptions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			feed_id bigint(20) unsigned NULL,
			coupon_code varchar(64) NOT NULL,
			entry_id bigint(20) unsigned NOT NULL,
			email varchar(190) NULL,
			user_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_coupon (form_id, coupon_code),
			KEY coupon_code (coupon_code),
			KEY entry_id (entry_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function get_table() {
		return $this->table;
	}

	public function capture_redemption( $entry, $form ) {
		if ( empty( $form['id'] ) || empty( $entry['id'] ) ) {
			return;
		}

		$coupon_field_ids = $this->get_coupon_field_ids( $form );
		if ( empty( $coupon_field_ids ) ) {
			return;
		}

		$coupon_codes = array();
		foreach ( $coupon_field_ids as $field_id ) {
			$value = rgar( $entry, (string) $field_id );
			if ( $value ) {
				$coupon_codes[] = strtoupper( trim( (string) $value ) );
			}
		}

		$coupon_codes = array_unique( array_filter( $coupon_codes ) );
		if ( empty( $coupon_codes ) ) {
			return;
		}

		$email = $this->get_entry_email( $entry, $form );
		$user_id = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
		$form_id = (int) $form['id'];
		$entry_id = (int) $entry['id'];

		foreach ( $coupon_codes as $code ) {
			$this->insert_redemption( $form_id, $entry_id, $code, $email, $user_id );
		}
	}

	private function get_coupon_field_ids( $form ) {
		$ids = array();
		if ( empty( $form['fields'] ) ) {
			return $ids;
		}

		foreach ( $form['fields'] as $field ) {
			if ( isset( $field->type ) && 'coupon' === $field->type ) {
				$ids[] = $field->id;
			}
		}

		return $ids;
	}

	private function get_entry_email( $entry, $form ) {
		if ( empty( $form['fields'] ) ) {
			return '';
		}

		foreach ( $form['fields'] as $field ) {
			if ( isset( $field->type ) && 'email' === $field->type ) {
				$value = rgar( $entry, (string) $field->id );
				if ( $value ) {
					return sanitize_email( $value );
				}
			}
		}

		return '';
	}

	private function insert_redemption( $form_id, $entry_id, $coupon_code, $email, $user_id ) {
		global $wpdb;
		$table = $this->get_table();

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE entry_id = %d AND coupon_code = %s",
				$entry_id,
				$coupon_code
			)
		);
		if ( $exists ) {
			return;
		}

		$feed_id = $this->resolve_feed_id( $form_id, $coupon_code );

		$wpdb->insert(
			$table,
			array(
				'form_id'     => $form_id,
				'feed_id'     => $feed_id ? $feed_id : null,
				'coupon_code' => $coupon_code,
				'entry_id'    => $entry_id,
				'email'       => $email ? $email : null,
				'user_id'     => $user_id ? $user_id : null,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	private function resolve_feed_id( $form_id, $coupon_code ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gf_addon_feed';
		$slug = 'gravityformscoupons';
		$like = '%' . $wpdb->esc_like( '"couponCode":"' . $coupon_code . '"' ) . '%';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE addon_slug = %s AND form_id = %d AND meta LIKE %s LIMIT 1",
				$slug,
				$form_id,
				$like
			)
		);
	}

	public function get_redemption_summaries( $form_id, array $codes ) {
		$summary = array();
		if ( empty( $codes ) ) {
			return $summary;
		}

		global $wpdb;
		$table = $this->get_table();

		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$params = array_merge( array( (int) $form_id ), $codes );

		$query = $wpdb->prepare(
			"SELECT coupon_code, COUNT(*) AS redemption_count, MAX(created_at) AS last_used_at FROM {$table} WHERE form_id = %d AND coupon_code IN ({$placeholders}) GROUP BY coupon_code",
			$params
		);
		$rows = $wpdb->get_results( $query, ARRAY_A );
		foreach ( $rows as $row ) {
			$summary[ $row['coupon_code'] ] = array(
				'count' => (int) $row['redemption_count'],
				'last_used_at' => $row['last_used_at'],
			);
		}

		$latest_query = $wpdb->prepare(
			"SELECT r.coupon_code, r.email, r.entry_id, r.created_at FROM {$table} r INNER JOIN (SELECT coupon_code, MAX(id) AS max_id FROM {$table} WHERE form_id = %d AND coupon_code IN ({$placeholders}) GROUP BY coupon_code) latest ON r.id = latest.max_id",
			$params
		);
		$latest_rows = $wpdb->get_results( $latest_query, ARRAY_A );
		foreach ( $latest_rows as $row ) {
			if ( ! isset( $summary[ $row['coupon_code'] ] ) ) {
				$summary[ $row['coupon_code'] ] = array(
					'count' => 0,
					'last_used_at' => '',
				);
			}
			$summary[ $row['coupon_code'] ]['email'] = $row['email'];
			$summary[ $row['coupon_code'] ]['entry_id'] = (int) $row['entry_id'];
			$summary[ $row['coupon_code'] ]['last_used_at'] = $row['created_at'];
		}

		return $summary;
	}

	public function get_redemptions( $form_id, $coupon_code ) {
		global $wpdb;
		$table = $this->get_table();

		$query = $wpdb->prepare(
			"SELECT entry_id, email, user_id, created_at FROM {$table} WHERE form_id = %d AND coupon_code = %s ORDER BY created_at DESC",
			$form_id,
			$coupon_code
		);

		return $wpdb->get_results( $query, ARRAY_A );
	}
}

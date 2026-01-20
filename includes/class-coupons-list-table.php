<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class GFBCU_Coupons_List_Table extends WP_List_Table {
	private $generator;

	public function __construct( $generator ) {
		parent::__construct(
			array(
				'singular' => 'gfbcu_coupon',
				'plural'   => 'gfbcu_coupons',
				'ajax'     => false,
			)
		);

		$this->generator = $generator;
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'name'       => __( 'Nombre', GFBCU_TEXT_DOMAIN ),
			'code'       => __( 'Código', GFBCU_TEXT_DOMAIN ),
			'form'       => __( 'Formulario', GFBCU_TEXT_DOMAIN ),
			'type'       => __( 'Tipo', GFBCU_TEXT_DOMAIN ),
			'amount'     => __( 'Valor', GFBCU_TEXT_DOMAIN ),
			'usage_limit'=> __( 'Límite', GFBCU_TEXT_DOMAIN ),
			'uses'       => __( 'Usos', GFBCU_TEXT_DOMAIN ),
			'stackable'  => __( 'Combinable', GFBCU_TEXT_DOMAIN ),
			'dates'      => __( 'Vigencia', GFBCU_TEXT_DOMAIN ),
			'status'     => __( 'Estado', GFBCU_TEXT_DOMAIN ),
			'created_at' => __( 'Creación', GFBCU_TEXT_DOMAIN ),
			'campaign'   => __( 'Etiqueta', GFBCU_TEXT_DOMAIN ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'delete' => __( 'Borrar', GFBCU_TEXT_DOMAIN ),
		);
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="coupon_ids[]" value="%d" />', (int) $item['id'] );
	}

	public function column_code( $item ) {
		$actions = array();

		if ( null === $item['usage_count'] ) {
			$nonce = wp_create_nonce( 'gfbcu_calculate_uses_' . $item['code'] . '_' . $item['form_id'] );
			$url = add_query_arg(
				array(
					'action'  => 'gfbcu_calculate_uses',
					'code'    => $item['code'],
					'form_id' => $item['form_id'],
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-post.php' )
			);
			$actions['calculate'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Calcular usos', GFBCU_TEXT_DOMAIN ) );
		}

		return sprintf( '%1$s %2$s', esc_html( $item['code'] ), $this->row_actions( $actions ) );
	}

	public function column_name( $item ) {
		return empty( $item['name'] ) ? '—' : esc_html( $item['name'] );
	}

	public function column_form( $item ) {
		$title = $this->generator->get_form_title( $item['form_id'] );
		if ( ! $title ) {
			return esc_html__( 'Form inválido', GFBCU_TEXT_DOMAIN );
		}
		return esc_html( $item['form_id'] . ' - ' . $title );
	}

	public function column_type( $item ) {
		$types = gfbcu_get_coupon_types();
		return isset( $types[ $item['type'] ] ) ? esc_html( $types[ $item['type'] ] ) : esc_html( $item['type'] );
	}

	public function column_amount( $item ) {
		return esc_html( $item['amount'] );
	}

	public function column_usage_limit( $item ) {
		return empty( $item['usage_limit'] ) || (int) $item['usage_limit'] === 0 ? esc_html__( 'Ilimitado', GFBCU_TEXT_DOMAIN ) : esc_html( $item['usage_limit'] );
	}

	public function column_uses( $item ) {
		if ( ! empty( $item['usage_count'] ) || 0 === (int) $item['usage_count'] ) {
			return esc_html( $item['usage_count'] );
		}

		$count = $this->generator->get_usage_count( $item['form_id'], $item['code'] );
		return null === $count ? '—' : esc_html( $count );
	}

	public function column_status( $item ) {
		return esc_html( $this->generator->get_coupon_status( $item ) );
	}

	public function column_stackable( $item ) {
		return ( isset( $item['is_stackable'] ) && '1' === $item['is_stackable'] ) ? esc_html__( 'Sí', GFBCU_TEXT_DOMAIN ) : esc_html__( 'No', GFBCU_TEXT_DOMAIN );
	}

	public function column_dates( $item ) {
		$start = empty( $item['start_date'] ) ? '—' : $item['start_date'];
		$end = empty( $item['expiration'] ) ? __( 'Never Expires', GFBCU_TEXT_DOMAIN ) : $item['expiration'];
		return esc_html( $start . ' → ' . $end );
	}

	public function column_created_at( $item ) {
		return empty( $item['created_at'] ) ? '—' : esc_html( $item['created_at'] );
	}

	public function column_campaign( $item ) {
		return empty( $item['campaign'] ) ? '—' : esc_html( $item['campaign'] );
	}

	public function prepare_items() {
		$this->process_bulk_action();
		$per_page = (int) $this->get_items_per_page( 'gfbcu_coupons_per_page', 20 );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		$current_page = max( 1, (int) $this->get_pagenum() );
		$offset = ( $current_page - 1 ) * $per_page;

		$filters = $this->get_filters();

		$result = $this->generator->query_coupons(
			array(
				'form_id'  => $filters['form_id'],
				'search'   => $filters['search'],
				'status'   => $filters['status'],
			)
		);

		$all_items = $result['items'];
		$total = $result['total'];
		$max_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $current_page > $max_pages ) {
			$current_page = 1;
			$offset = 0;
		}
		$items = array_slice( $all_items, $offset, $per_page );

		$codes = wp_list_pluck( $items, 'code' );
		$campaign_map = $this->generator->get_campaign_map( $codes, $filters['form_id'] );

		foreach ( $items as &$item ) {
			$item['campaign'] = isset( $campaign_map[ $item['code'] ] ) ? $campaign_map[ $item['code'] ] : '';
		}

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $max_pages,
			)
		);

		if ( $total > 0 && 1 === $current_page && 0 === count( $this->items ) ) {
			$first_id = isset( $all_items[0]['id'] ) ? $all_items[0]['id'] : 'n/a';
			error_log(
				sprintf(
					'[GF Bulk Coupons] Empty list debug: per_page=%d current_page=%d offset=%d first_id=%s',
					$per_page,
					$current_page,
					$offset,
					$first_id
				)
			);
		}
	}

	public function get_filters() {
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

		return array(
			'form_id' => $form_id,
			'search'  => $search,
			'status'  => $status,
		);
	}

	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$forms = $this->generator->get_forms();
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$statuses = array(
			''             => __( 'Todos los estados', GFBCU_TEXT_DOMAIN ),
			'active'       => __( 'Activo', GFBCU_TEXT_DOMAIN ),
			'expired'      => __( 'Expirado', GFBCU_TEXT_DOMAIN ),
			'exhausted'    => __( 'Agotado', GFBCU_TEXT_DOMAIN ),
			'no_expiration'=> __( 'Sin expiración', GFBCU_TEXT_DOMAIN ),
		);
		?>
		<div class="alignleft actions">
			<select name="form_id">
				<option value="0"><?php esc_html_e( 'Todos los formularios', GFBCU_TEXT_DOMAIN ); ?></option>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $form_id, $form['id'] ); ?>><?php echo esc_html( $form['id'] . ' - ' . $form['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filtrar', GFBCU_TEXT_DOMAIN ), 'secondary', false, false ); ?>
			<a class="button" href="<?php echo esc_url( $this->get_export_url() ); ?>"><?php esc_html_e( 'Exportar CSV', GFBCU_TEXT_DOMAIN ); ?></a>
		</div>
		<?php
	}

	private function get_export_url() {
		$args = array(
			'action' => 'gfbcu_export_filtered',
			'form_id' => isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0,
			's' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			'_wpnonce' => wp_create_nonce( 'gfbcu_export_filtered' ),
		);

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		if ( empty( $_POST['coupon_ids'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$ids = array_map( 'absint', wp_unslash( $_POST['coupon_ids'] ) );
		$this->delete_coupons( $ids );
	}

	private function delete_coupons( array $ids ) {
		global $wpdb;
		$table = $this->generator->get_feed_table();
		if ( ! $table ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids );
		$wpdb->query( $query );
	}
}

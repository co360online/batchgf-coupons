<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFBCU_Admin_Pages {
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_gfbcu_generate', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_gfbcu_generate_batch', array( $this, 'handle_generate_batch' ) );
		add_action( 'admin_post_gfbcu_calculate_uses', array( $this, 'handle_calculate_uses' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'GF Bulk Coupons', GFBCU_TEXT_DOMAIN ),
			__( 'GF Bulk Coupons', GFBCU_TEXT_DOMAIN ),
			'manage_options',
			'gfbcu-generate',
			array( $this, 'render_generate_page' ),
			'dashicons-tickets-alt'
		);

		add_submenu_page(
			'gfbcu-generate',
			__( 'Generar', GFBCU_TEXT_DOMAIN ),
			__( 'Generar', GFBCU_TEXT_DOMAIN ),
			'manage_options',
			'gfbcu-generate',
			array( $this, 'render_generate_page' )
		);

		add_submenu_page(
			'gfbcu-generate',
			__( 'Cupones', GFBCU_TEXT_DOMAIN ),
			__( 'Cupones', GFBCU_TEXT_DOMAIN ),
			'manage_options',
			'gfbcu-coupons',
			array( $this, 'render_coupons_page' )
		);

		add_submenu_page(
			null,
			__( 'Usos de cupón', GFBCU_TEXT_DOMAIN ),
			__( 'Usos de cupón', GFBCU_TEXT_DOMAIN ),
			'manage_options',
			'gfbcu-coupon-uses',
			array( $this, 'render_coupon_uses_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'gfbcu-generate' ) ) {
			return;
		}

		wp_enqueue_script(
			'gfbcu-admin',
			GFBCU_URL . 'assets/admin.js',
			array( 'jquery' ),
			GFBCU_VERSION,
			true
		);

		wp_localize_script(
			'gfbcu-admin',
			'GFBCU_Admin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'gfbcu_generate_batch' ),
				'chunk'    => 200,
				'messages' => array(
					'progress' => __( 'Generando cupones...', GFBCU_TEXT_DOMAIN ),
					'complete' => __( 'Generación completada.', GFBCU_TEXT_DOMAIN ),
				),
			)
		);
	}

	public function render_generate_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$generator = GFBCU_Bulk_Generator::get_instance();
		$forms = $generator->get_forms();
		$recent_token = isset( $_GET['gfbcu_token'] ) ? sanitize_text_field( wp_unslash( $_GET['gfbcu_token'] ) ) : '';
		$recent_data  = $recent_token ? get_transient( 'gfbcu_recent_' . $recent_token ) : null;

		$this->render_dependency_notice();
		$this->render_admin_notices();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Generar cupones', GFBCU_TEXT_DOMAIN ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="gfbcu-generate-form">
				<?php wp_nonce_field( 'gfbcu_generate_coupons', 'gfbcu_nonce' ); ?>
				<input type="hidden" name="action" value="gfbcu_generate">

				<table class="form-table">
					<tr>
						<th scope="row"><label for="gfbcu-form-id"><?php esc_html_e( 'Formulario', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select name="form_id" id="gfbcu-form-id" required>
								<option value=""><?php esc_html_e( 'Selecciona un formulario', GFBCU_TEXT_DOMAIN ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( $form['id'] ); ?>"><?php echo esc_html( $form['id'] . ' - ' . $form['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-type"><?php esc_html_e( 'Tipo de descuento', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select name="type" id="gfbcu-type">
								<?php foreach ( gfbcu_get_coupon_types() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-amount"><?php esc_html_e( 'Valor', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="number" step="0.01" min="0" name="amount" id="gfbcu-amount" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-count"><?php esc_html_e( 'Cantidad', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="number" min="1" name="count" id="gfbcu-count" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-prefix"><?php esc_html_e( 'Prefijo', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="text" name="prefix" id="gfbcu-prefix" placeholder="EVENTO-"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-mode"><?php esc_html_e( 'Modo de código', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select name="mode" id="gfbcu-mode">
								<option value="random"><?php esc_html_e( 'Aleatorio', GFBCU_TEXT_DOMAIN ); ?></option>
								<option value="incremental"><?php esc_html_e( 'Incremental', GFBCU_TEXT_DOMAIN ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Incremental genera códigos numéricos con relleno.', GFBCU_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-increment-start"><?php esc_html_e( 'Inicio incremental', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="number" min="1" name="increment_start" id="gfbcu-increment-start" value="1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-length"><?php esc_html_e( 'Longitud parte aleatoria', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="number" min="4" max="20" name="length" id="gfbcu-length" value="8"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-usage-limit"><?php esc_html_e( 'Límite de uso', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="number" min="1" name="usage_limit" id="gfbcu-usage-limit" value="1">
							<label><input type="checkbox" name="unlimited" value="1"> <?php esc_html_e( 'Ilimitado', GFBCU_TEXT_DOMAIN ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfbcu-expiration"><?php esc_html_e( 'Expiración', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="date" name="expiration" id="gfbcu-expiration">
							<label><input type="checkbox" name="no_expiration" value="1"> <?php esc_html_e( 'Sin expiración', GFBCU_TEXT_DOMAIN ); ?></label>
						</td>
					</tr>
					<?php if ( $generator->supports_stackable() ) : ?>
						<tr>
							<th scope="row"><label for="gfbcu-stackable"><?php esc_html_e( 'Combinable', GFBCU_TEXT_DOMAIN ); ?></label></th>
							<td><label><input type="checkbox" name="is_stackable" id="gfbcu-stackable" value="1"> <?php esc_html_e( 'Permitir combinar cupones', GFBCU_TEXT_DOMAIN ); ?></label></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="gfbcu-campaign"><?php esc_html_e( 'Etiqueta / campaña', GFBCU_TEXT_DOMAIN ); ?></label></th>
						<td><input type="text" name="campaign" id="gfbcu-campaign"></td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary" id="gfbcu-generate-button"><?php esc_html_e( 'Generar cupones', GFBCU_TEXT_DOMAIN ); ?></button>
				</p>
			</form>

			<div id="gfbcu-progress" style="display:none;">
				<p><span class="spinner is-active"></span> <span class="gfbcu-progress-text"></span></p>
				<progress max="100" value="0" style="width:100%;"></progress>
			</div>

			<div id="gfbcu-results">
				<?php if ( $recent_data && ! empty( $recent_data['codes'] ) ) : ?>
					<?php $this->render_results( $recent_data ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_results( $data ) {
		$codes = $data['codes'];
		?>
		<h2><?php esc_html_e( 'Resumen de generación', GFBCU_TEXT_DOMAIN ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( 'Cantidad generada: %d', GFBCU_TEXT_DOMAIN ), count( $codes ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Form ID: %d', GFBCU_TEXT_DOMAIN ), $data['form_id'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Tipo: %s', GFBCU_TEXT_DOMAIN ), $data['type'] ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( 'Valor: %s', GFBCU_TEXT_DOMAIN ), $data['amount'] ) ); ?></li>
		</ul>

		<p>
			<a class="button" href="<?php echo esc_url( $data['export_url'] ); ?>"><?php esc_html_e( 'Exportar CSV', GFBCU_TEXT_DOMAIN ); ?></a>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Código', GFBCU_TEXT_DOMAIN ); ?></th>
					<th><?php esc_html_e( 'Copiar', GFBCU_TEXT_DOMAIN ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $codes as $code ) : ?>
					<tr>
						<td><?php echo esc_html( $code ); ?></td>
						<td><button type="button" class="button gfbcu-copy" data-code="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copiar', GFBCU_TEXT_DOMAIN ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_coupons_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$generator = GFBCU_Bulk_Generator::get_instance();
		$list_table = new GFBCU_Coupons_List_Table( $generator );
		$list_table->prepare_items();

		$this->render_dependency_notice();
		$this->render_admin_notices();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cupones', GFBCU_TEXT_DOMAIN ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="gfbcu-coupons">
				<input type="hidden" name="paged" value="1">
				<?php $list_table->search_box( __( 'Buscar', GFBCU_TEXT_DOMAIN ), 'gfbcu-search' ); ?>
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function render_coupon_uses_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$nonce_action = 'gfbcu_view_uses_' . $code . '_' . $form_id;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'Nonce inválido.', GFBCU_TEXT_DOMAIN ) );
		}

		$redemptions = GFBCU_Redemptions::get_instance()->get_redemptions( $form_id, $code );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Usos del cupón', GFBCU_TEXT_DOMAIN ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Cupón: %s', GFBCU_TEXT_DOMAIN ), $code ) ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Entry ID', GFBCU_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Email', GFBCU_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'User ID', GFBCU_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Fecha', GFBCU_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Entry', GFBCU_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $redemptions ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No hay usos registrados.', GFBCU_TEXT_DOMAIN ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $redemptions as $redemption ) : ?>
							<?php
							$entry_url = add_query_arg(
								array(
									'page' => 'gf_entries',
									'view' => 'entry',
									'id'   => $form_id,
									'lid'  => (int) $redemption['entry_id'],
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><?php echo esc_html( $redemption['entry_id'] ); ?></td>
								<td><?php echo $redemption['email'] ? esc_html( $redemption['email'] ) : '—'; ?></td>
								<td><?php echo $redemption['user_id'] ? esc_html( $redemption['user_id'] ) : '—'; ?></td>
								<td><?php echo esc_html( $redemption['created_at'] ); ?></td>
								<td><a href="<?php echo esc_url( $entry_url ); ?>"><?php esc_html_e( 'Ver entry', GFBCU_TEXT_DOMAIN ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_dependency_notice() {
		if ( ! gfbcu_has_gravity_forms() ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'Gravity Forms no está activo. Activa Gravity Forms para usar este plugin.', GFBCU_TEXT_DOMAIN ) );
		}

		if ( ! gfbcu_has_coupons_addon() ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'El Add-On Gravity Forms Coupons no está activo. Las funciones de cupones pueden no estar disponibles.', GFBCU_TEXT_DOMAIN ) );
		}
	}

	private function render_admin_notices() {
		if ( empty( $_GET['gfbcu_notice'] ) ) {
			return;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['gfbcu_notice'] ) );
		$type    = isset( $_GET['gfbcu_type'] ) ? sanitize_key( $_GET['gfbcu_type'] ) : 'info';
		$type    = in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'info';

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	public function handle_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', GFBCU_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'gfbcu_generate_coupons', 'gfbcu_nonce' );

		$generator = GFBCU_Bulk_Generator::get_instance();
		$args = $this->sanitize_generation_args( $_POST );

		if ( is_wp_error( $args ) ) {
			$this->redirect_with_notice( $args->get_error_message(), 'error' );
		}

		if ( $args['count'] > 500 ) {
			$this->redirect_with_notice( __( 'Cantidad alta detectada. Usa la generación por lotes con AJAX.', GFBCU_TEXT_DOMAIN ), 'warning' );
		}

		$codes = $generator->generate_coupons( $args );
		if ( is_wp_error( $codes ) ) {
			$this->redirect_with_notice( $codes->get_error_message(), 'error' );
		}

		$token = $this->store_recent_generation( $codes, $args );
		$redirect_args = array( 'gfbcu_token' => $token );
		if ( ! empty( $args['prefix_notice'] ) ) {
			$redirect_args['gfbcu_notice'] = rawurlencode( $args['prefix_notice'] );
			$redirect_args['gfbcu_type'] = 'warning';
		}
		$redirect = add_query_arg( $redirect_args, gfbcu_admin_url( 'gfbcu-generate' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_generate_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos.', GFBCU_TEXT_DOMAIN ) ) );
		}

		check_ajax_referer( 'gfbcu_generate_batch', 'nonce' );

		$generator = GFBCU_Bulk_Generator::get_instance();
		$args = $this->sanitize_generation_args( $_POST );

		if ( is_wp_error( $args ) ) {
			wp_send_json_error( array( 'message' => $args->get_error_message() ) );
		}

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! $token ) {
			$token = wp_generate_uuid4();
		}

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$chunk  = isset( $_POST['chunk'] ) ? (int) $_POST['chunk'] : 200;
		$remaining = $args['count'] - $offset;
		$current_chunk = min( $chunk, $remaining );

		$args['count'] = $current_chunk;
		$args['increment_start'] = (int) $args['increment_start'] + $offset;

		$codes = $generator->generate_coupons( $args );
		if ( is_wp_error( $codes ) ) {
			wp_send_json_error( array( 'message' => $codes->get_error_message() ) );
		}

		$stored = get_transient( 'gfbcu_recent_' . $token );
		if ( ! is_array( $stored ) ) {
			$stored = array(
				'codes'   => array(),
				'form_id' => $args['form_id'],
				'type'    => $args['type'],
				'amount'  => $args['amount'],
				'args'    => $args,
				'created' => current_time( 'mysql' ),
				'export_url' => add_query_arg(
					array(
						'action' => 'gfbcu_export',
						'token'  => $token,
						'_wpnonce' => wp_create_nonce( 'gfbcu_export_' . $token ),
					),
					admin_url( 'admin-post.php' )
				),
			);
		}

		$stored['codes'] = array_merge( $stored['codes'], $codes );
		set_transient( 'gfbcu_recent_' . $token, $stored, DAY_IN_SECONDS );

		$progress = min( 100, round( ( ( $offset + $current_chunk ) / (int) $args['count_total'] ) * 100 ) );
		$export_url = add_query_arg(
			array(
				'action' => 'gfbcu_export',
				'token'  => $token,
				'_wpnonce' => wp_create_nonce( 'gfbcu_export_' . $token ),
			),
			admin_url( 'admin-post.php' )
		);

		$response = array(
			'token'     => $token,
			'codes'     => $codes,
			'offset'    => $offset + $current_chunk,
			'progress'  => $progress,
			'exportUrl' => $export_url,
		);

		if ( ! empty( $args['prefix_notice'] ) && 0 === $offset ) {
			$response['notice'] = $args['prefix_notice'];
		}

		wp_send_json_success( $response );
	}

	private function sanitize_generation_args( $data ) {
		$form_id = isset( $data['form_id'] ) ? (int) $data['form_id'] : 0;
		$type = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'flat';
		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : 0;
		$count = isset( $data['count'] ) ? (int) $data['count'] : 0;
		$prefix = isset( $data['prefix'] ) ? sanitize_text_field( wp_unslash( $data['prefix'] ) ) : '';
		$length = isset( $data['length'] ) ? (int) $data['length'] : 8;
		$usage_limit = isset( $data['usage_limit'] ) ? (int) $data['usage_limit'] : 1;
		$unlimited = ! empty( $data['unlimited'] );
		$expiration = isset( $data['expiration'] ) ? sanitize_text_field( wp_unslash( $data['expiration'] ) ) : '';
		$no_expiration = ! empty( $data['no_expiration'] );
		$campaign = isset( $data['campaign'] ) ? sanitize_text_field( wp_unslash( $data['campaign'] ) ) : '';
		$is_stackable = ! empty( $data['is_stackable'] );
		$mode = isset( $data['mode'] ) ? sanitize_key( $data['mode'] ) : 'random';
		$increment_start = isset( $data['increment_start'] ) ? (int) $data['increment_start'] : 1;

		if ( ! $form_id ) {
			return new WP_Error( 'gfbcu_invalid_form', __( 'Selecciona un formulario válido.', GFBCU_TEXT_DOMAIN ) );
		}
		if ( ! in_array( $type, array_keys( gfbcu_get_coupon_types() ), true ) ) {
			return new WP_Error( 'gfbcu_invalid_type', __( 'Tipo de descuento inválido.', GFBCU_TEXT_DOMAIN ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'gfbcu_invalid_amount', __( 'El valor del descuento debe ser mayor que 0.', GFBCU_TEXT_DOMAIN ) );
		}
		if ( $count < 1 ) {
			return new WP_Error( 'gfbcu_invalid_count', __( 'Cantidad inválida.', GFBCU_TEXT_DOMAIN ) );
		}
		if ( $length < 4 ) {
			return new WP_Error( 'gfbcu_invalid_length', __( 'La longitud debe ser al menos 4.', GFBCU_TEXT_DOMAIN ) );
		}
		if ( $usage_limit < 1 && ! $unlimited ) {
			return new WP_Error( 'gfbcu_invalid_usage_limit', __( 'Límite de uso inválido.', GFBCU_TEXT_DOMAIN ) );
		}

		if ( $no_expiration ) {
			$expiration = '';
		}

		if ( $expiration && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration ) ) {
			return new WP_Error( 'gfbcu_invalid_expiration', __( 'Formato de fecha inválido.', GFBCU_TEXT_DOMAIN ) );
		}

		if ( ! in_array( $mode, array( 'random', 'incremental' ), true ) ) {
			return new WP_Error( 'gfbcu_invalid_mode', __( 'Modo de generación inválido.', GFBCU_TEXT_DOMAIN ) );
		}

		if ( $increment_start < 1 ) {
			$increment_start = 1;
		}

		$prefix_data = gfbcu_normalize_coupon_prefix( $prefix );
		if ( $prefix_data['changed'] && '' === $prefix_data['normalized'] ) {
			return new WP_Error( 'gfbcu_invalid_prefix', __( 'El prefijo no contiene caracteres válidos (solo A-Z y 0-9).', GFBCU_TEXT_DOMAIN ) );
		}

		$prefix_notice = '';
		if ( $prefix_data['changed'] ) {
			$prefix_notice = sprintf(
				__( 'El prefijo ha sido normalizado a %s', GFBCU_TEXT_DOMAIN ),
				$prefix_data['normalized']
			);
		}

		return array(
			'form_id'         => $form_id,
			'type'            => $type,
			'amount'          => $amount,
			'count'           => $count,
			'count_total'     => $count,
			'prefix'          => $prefix_data['normalized'],
			'length'          => $length,
			'usage_limit'     => $usage_limit,
			'unlimited'       => $unlimited,
			'expiration'      => $expiration,
			'campaign'        => $campaign,
			'is_stackable'    => $is_stackable,
			'mode'            => $mode,
			'increment_start' => $increment_start,
			'prefix_notice'   => $prefix_notice,
		);
	}

	private function store_recent_generation( $codes, $args ) {
		$token = wp_generate_uuid4();
		$data = array(
			'codes'   => $codes,
			'form_id' => $args['form_id'],
			'type'    => $args['type'],
			'amount'  => $args['amount'],
			'args'    => $args,
			'created' => current_time( 'mysql' ),
			'export_url' => add_query_arg(
				array(
					'action' => 'gfbcu_export',
					'token'  => $token,
					'_wpnonce' => wp_create_nonce( 'gfbcu_export_' . $token ),
				),
				admin_url( 'admin-post.php' )
			),
		);

		set_transient( 'gfbcu_recent_' . $token, $data, DAY_IN_SECONDS );
		return $token;
	}

	private function redirect_with_notice( $message, $type ) {
		$redirect = add_query_arg(
			array(
				'gfbcu_notice' => rawurlencode( $message ),
				'gfbcu_type'   => $type,
			),
			gfbcu_admin_url( 'gfbcu-generate' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_calculate_uses() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', GFBCU_TEXT_DOMAIN ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? (int) $_GET['form_id'] : 0;
		check_admin_referer( 'gfbcu_calculate_uses_' . $code . '_' . $form_id );

		$generator = GFBCU_Bulk_Generator::get_instance();
		$generator->get_usage_count( $form_id, $code, true );

		wp_safe_redirect( gfbcu_admin_url( 'gfbcu-coupons' ) );
		exit;
	}
}

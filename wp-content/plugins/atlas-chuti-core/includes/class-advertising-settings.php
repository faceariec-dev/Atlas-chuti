<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 7, item 16: "Reklama" admin settings screen — same admin_post + nonce +
 * `manage_options` + per-user transient flash-message pattern already used 3×
 * in this codebase (class-page-setup.php, class-json-importer.php), nested
 * under the existing "Atlas chutí" top-level menu (slug `atlas-chuti-import`)
 * rather than introducing the Settings API fresh, per the Step 7 audit's own
 * consistency recommendation. One option (`atlas_chuti_ads_settings`, a single
 * serialized array — see Atlas_Chuti_Advertising::settings()), not dozens of
 * one-off options.
 */
class Atlas_Chuti_Advertising_Settings {

	const CAPABILITY = 'manage_options';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_atlas_ads_settings', array( $this, 'handle_save' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'atlas-chuti-import',
			__( 'Reklama', 'atlas-chuti' ),
			__( 'Reklama', 'atlas-chuti' ),
			self::CAPABILITY,
			'atlas-chuti-ads',
			array( $this, 'render_page' )
		);
	}

	private function campaign_options() {
		return get_posts(
			array(
				'post_type'      => Atlas_Chuti_Ad_Campaign::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}
		$saved      = get_transient( 'atlas_chuti_ads_settings_saved_' . get_current_user_id() );
		$settings   = Atlas_Chuti_Advertising::instance()->settings();
		$campaigns  = $this->campaign_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Reklama', 'atlas-chuti' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Nastavení reklamy uloženo.', 'atlas-chuti' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $campaigns ) ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Zatím žádné kampaně. Vytvořte je v sekci „Reklamní kampaně“.', 'atlas-chuti' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'atlas_ads_settings', 'atlas_ads_settings_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_ads_settings">

				<h2><?php esc_html_e( 'Globální nastavení', 'atlas-chuti' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Reklama povolena', 'atlas-chuti' ); ?></th>
						<td><label><input type="checkbox" name="atlas_ads[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Hlavní vypínač — pokud vypnuto, žádný slot se nikdy nevykreslí.', 'atlas-chuti' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'GATE (desktop wallpaper)', 'atlas-chuti' ); ?></th>
						<td><label><input type="checkbox" name="atlas_ads[gate_enabled]" value="1" <?php checked( ! empty( $settings['gate_enabled'] ) ); ?>> <?php esc_html_e( 'Povolit GATE reklamní plochu na širokém desktopu.', 'atlas-chuti' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Externí reklamní síť', 'atlas-chuti' ); ?></th>
						<td><label><input type="checkbox" name="atlas_ads[external_network_enabled]" value="1" <?php checked( ! empty( $settings['external_network_enabled'] ) ); ?>> <?php esc_html_e( 'Povolit sloty se zdrojem „Externí síť“ (vyžaduje provider config i consent — viz report).', 'atlas-chuti' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Debug mód', 'atlas-chuti' ); ?></th>
						<td><label><input type="checkbox" name="atlas_ads[debug_mode]" value="1" <?php checked( ! empty( $settings['debug_mode'] ) ); ?>> <?php esc_html_e( 'Zobrazit obrysy prázdných slotů — jen pro administrátory.', 'atlas-chuti' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sloty', 'atlas-chuti' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Slot', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Zapnuto', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Zdroj', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Kampaň', 'atlas-chuti' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( Atlas_Chuti_Ad_Slots::keys() as $key ) : ?>
						<?php $slot_config = Atlas_Chuti_Advertising::instance()->slot_config( $key ); ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code><br><span class="description"><?php echo esc_html( Atlas_Chuti_Ad_Slots::label( $key ) ); ?></span></td>
							<td><input type="checkbox" name="atlas_ads[slots][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $slot_config['enabled'] ) ); ?>></td>
							<td>
								<select name="atlas_ads[slots][<?php echo esc_attr( $key ); ?>][source]">
									<?php foreach ( array( 'none' => __( 'Žádný', 'atlas-chuti' ), 'direct' => __( 'Přímá kampaň', 'atlas-chuti' ), 'external_network' => __( 'Externí síť', 'atlas-chuti' ) ) as $val => $lbl ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $slot_config['source'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="atlas_ads[slots][<?php echo esc_attr( $key ); ?>][campaign_id]">
									<option value="0"><?php esc_html_e( '— automaticky (první aktivní) —', 'atlas-chuti' ); ?></option>
									<?php foreach ( $campaigns as $c ) : ?>
										<option value="<?php echo esc_attr( $c->ID ); ?>" <?php selected( (int) $slot_config['campaign_id'], $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Uložit nastavení', 'atlas-chuti' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['atlas_ads_settings_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_ads_settings_nonce'], 'atlas_ads_settings' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}

		$posted = isset( $_POST['atlas_ads'] ) ? wp_unslash( $_POST['atlas_ads'] ) : array();

		$clean = array(
			'enabled'                  => ! empty( $posted['enabled'] ),
			'gate_enabled'             => ! empty( $posted['gate_enabled'] ),
			'external_network_enabled' => ! empty( $posted['external_network_enabled'] ),
			'debug_mode'               => ! empty( $posted['debug_mode'] ),
			'per_slot'                 => array(),
		);

		$posted_slots = is_array( $posted['slots'] ?? null ) ? $posted['slots'] : array();
		foreach ( Atlas_Chuti_Ad_Slots::keys() as $key ) {
			$row    = is_array( $posted_slots[ $key ] ?? null ) ? $posted_slots[ $key ] : array();
			$source = sanitize_key( $row['source'] ?? 'none' );
			$clean['per_slot'][ $key ] = array(
				'enabled'     => ! empty( $row['enabled'] ),
				'source'      => in_array( $source, array( 'none', 'direct', 'external_network' ), true ) ? $source : 'none',
				'campaign_id' => (int) ( $row['campaign_id'] ?? 0 ),
			);
		}

		update_option( Atlas_Chuti_Advertising::OPTION, $clean );

		set_transient( 'atlas_chuti_ads_settings_saved_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-ads' ) );
		exit;
	}
}

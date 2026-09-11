<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Atlas chutí → Nastavení stránek" (item 29 of this phase's brief): a checklist that
 * creates the standard set of pages this theme expects — as drafts/concepts, no
 * legal copy written for the editor — and is safe to run repeatedly: it checks by
 * slug first and never creates a duplicate. Recepty/Slovníček aren't real WP Pages
 * (they're CPT archives, already reachable at /recepty/ and /slovnicek/); the
 * checklist just confirms that instead of creating a competing Page at the same slug.
 */
class Atlas_Chuti_Page_Setup {

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
		add_action( 'admin_post_atlas_page_setup', array( $this, 'handle_run' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'atlas-chuti-import',
			__( 'Nastavení stránek', 'atlas-chuti' ),
			__( 'Nastavení stránek', 'atlas-chuti' ),
			self::CAPABILITY,
			'atlas-chuti-pages',
			array( $this, 'render_page' )
		);
	}

	/**
	 * slug => [label, template|null]. `template` null means "just confirm the CPT
	 * archive is reachable", not "create a WordPress Page".
	 */
	private function expected_pages() {
		return array(
			'zeme'                     => array( __( 'Země', 'atlas-chuti' ), 'template-countries.php' ),
			'kulinarsky-pas'           => array( __( 'Kulinářský pas', 'atlas-chuti' ), 'template-passport.php' ),
			'recepty'                  => array( __( 'Recepty', 'atlas-chuti' ), 'archive' ),
			'slovnicek'                => array( __( 'Kuchařský slovníček', 'atlas-chuti' ), 'archive' ),
			'o-projektu'               => array( __( 'O projektu', 'atlas-chuti' ), null ),
			'kontakt'                  => array( __( 'Kontakt', 'atlas-chuti' ), null ),
			'jak-vznika-obsah'         => array( __( 'Jak vzniká obsah', 'atlas-chuti' ), null ),
			'redakcni-zasady'          => array( __( 'Redakční zásady a zdroje', 'atlas-chuti' ), null ),
			'ochrana-osobnich-udaju'   => array( __( 'Ochrana osobních údajů', 'atlas-chuti' ), null ),
			'cookies'                  => array( __( 'Cookies', 'atlas-chuti' ), null ),
			'podminky-pouzivani'       => array( __( 'Podmínky používání', 'atlas-chuti' ), null ),
			'inzerce'                  => array( __( 'Inzerce / Spolupráce', 'atlas-chuti' ), null ),
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}
		$created = get_transient( 'atlas_chuti_page_setup_result_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Nastavení stránek', 'atlas-chuti' ); ?></h1>
			<p><?php esc_html_e( 'Přehled očekávaných stránek webu. Opakované spuštění nevytváří duplicity — každá stránka se vytvoří jen jednou, podle slugu.', 'atlas-chuti' ); ?></p>

			<?php if ( $created ) : ?>
				<div class="notice notice-success"><p>
					<?php
					/* translators: %d: number of pages created */
					printf( esc_html__( 'Vytvořeno nových stránek (jako koncept): %d.', 'atlas-chuti' ), (int) $created );
					?>
				</p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:760px;">
				<thead><tr><th><?php esc_html_e( 'Stránka', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'URL', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'Stav', 'atlas-chuti' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->expected_pages() as $slug => list( $label, $template ) ) : ?>
					<?php $status = $this->status_for( $slug, $template ); ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><code>/<?php echo esc_html( $slug ); ?>/</code></td>
						<td><?php echo $status['found'] ? '✅ ' . esc_html( $status['label'] ) : '⚠️ ' . esc_html__( 'Chybí', 'atlas-chuti' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px;">
				<?php wp_nonce_field( 'atlas_page_setup', 'atlas_page_setup_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_page_setup">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Vytvořit chybějící stránky', 'atlas-chuti' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function status_for( $slug, $template ) {
		if ( 'archive' === $template ) {
			$post_type = 'recepty' === $slug ? 'atlas_recipe' : 'atlas_glossary';
			$link      = get_post_type_archive_link( $post_type );
			return array( 'found' => (bool) $link, 'label' => __( 'archiv obsahového typu', 'atlas-chuti' ) );
		}
		$page = get_page_by_path( $slug );
		if ( ! $page ) {
			return array( 'found' => false, 'label' => '' );
		}
		return array( 'found' => true, 'label' => 'publish' === $page->post_status ? __( 'publikováno', 'atlas-chuti' ) : __( 'koncept', 'atlas-chuti' ) );
	}

	public function handle_run() {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['atlas_page_setup_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_page_setup_nonce'], 'atlas_page_setup' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}

		$created = 0;
		foreach ( $this->expected_pages() as $slug => list( $label, $template ) ) {
			if ( 'archive' === $template ) {
				continue; // Nothing to create — it's a CPT archive, not a Page.
			}
			if ( get_page_by_path( $slug ) ) {
				continue; // Idempotent: already exists, never duplicated.
			}
			$page_id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_title'  => $label,
					'post_name'   => $slug,
					'post_status' => 'draft',
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $page_id ) ) {
				continue;
			}
			if ( $template ) {
				update_post_meta( $page_id, '_wp_page_template', $template );
			}
			++$created;
		}

		set_transient( 'atlas_chuti_page_setup_result_' . get_current_user_id(), $created, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-pages' ) );
		exit;
	}
}

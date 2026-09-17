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
	 * slug => [label, template|null|'archive', publish_status?]. `template` null
	 * means "just confirm the CPT archive is reachable", not "create a WordPress
	 * Page"; 'archive' likewise. The optional 4th... 3rd (index 2) element is a
	 * publish-status override — every existing entry omits it and keeps the
	 * established "always created as draft" behaviour unchanged (KROK 6 does not
	 * touch that convention for legal/info pages, see item 23 of the brief); only
	 * the new `magazin` entry below uses it, because unlike an empty legal
	 * placeholder it is a real, working feature the moment this step ships (item
	 * 27: "po Kroku 6 ho napoj na skutečné standard WP posts" — already done, see
	 * template-magazine.php), not a thin page section 23 is warning against.
	 */
	private function expected_pages() {
		return array(
			'zeme'                     => array( __( 'Země', 'atlas-chuti' ), 'template-countries.php' ),
			'kulinarsky-pas'           => array( __( 'Kulinářský pas', 'atlas-chuti' ), 'template-passport.php' ),
			'muj-atlas'                => array( __( 'Můj Atlas', 'atlas-chuti' ), 'template-my-atlas.php' ),
			'recepty'                  => array( __( 'Recepty', 'atlas-chuti' ), 'archive' ),
			'slovnicek'                => array( __( 'Kuchařský slovníček', 'atlas-chuti' ), 'archive' ),
			'magazin'                  => array( __( 'Magazín', 'atlas-chuti' ), 'template-magazine.php', 'publish' ),
			// KROK 8, item 12/47: real working utility tools from day one, same
			// as 'magazin' above — 'publish', not the default draft.
			'co-dnes-varit'            => array( __( 'Co dnes vařit?', 'atlas-chuti' ), 'template-co-dnes-varit.php', 'publish' ),
			'co-mam-doma'              => array( __( 'Co mám doma?', 'atlas-chuti' ), 'template-co-mam-doma.php', 'publish' ),
			'diskuze'                  => array( __( 'Diskuze', 'atlas-chuti' ), 'archive' ),
			'o-projektu'               => array( __( 'O projektu', 'atlas-chuti' ), null ),
			'kontakt'                  => array( __( 'Kontakt', 'atlas-chuti' ), null ),
			'jak-vznika-obsah'         => array( __( 'Jak vzniká obsah', 'atlas-chuti' ), null ),
			'redakcni-zasady'          => array( __( 'Redakční zásady a zdroje', 'atlas-chuti' ), null ),
			'ochrana-osobnich-udaju'   => array( __( 'Ochrana osobních údajů', 'atlas-chuti' ), null ),
			'cookies'                  => array( __( 'Cookies', 'atlas-chuti' ), null ),
			'podminky-pouzivani'       => array( __( 'Podmínky používání', 'atlas-chuti' ), null ),
			'inzerce'                  => array( __( 'Inzerce / Spolupráce', 'atlas-chuti' ), null ),
			// KROK 6, item 22 — new general/legal page structure. Every one of these
			// is created EMPTY (no post_content) and draft (unless noted otherwise
			// above) — this checklist only ever prepares a slug + template hook, an
			// editor still has to write and publish the real copy (item 22's own
			// "nepiš finální právní texty" instruction). Newsletter and "Staňte se
			// autorem" from the brief's own product/community group are
			// deliberately NOT listed here — the brief itself marks both
			// "(hook only)"/"(budoucí hook)", so no page exists yet to route to; see
			// the Step 6 report, section N, for that decision.
			'jak-atlas-funguje'        => array( __( 'Jak Atlas funguje', 'atlas-chuti' ), null ),
			'redakce-autori'           => array( __( 'Redakce a autoři', 'atlas-chuti' ), null ),
			'nahlasit-chybu'           => array( __( 'Nahlásit chybu', 'atlas-chuti' ), null ),
			'faq'                      => array( __( 'FAQ', 'atlas-chuti' ), null ),
			'pro-media'                => array( __( 'Pro média', 'atlas-chuti' ), null ),
			'pravidla-komunity'        => array( __( 'Pravidla komunity', 'atlas-chuti' ), null ),
			'pravidla-ugc'             => array( __( 'Pravidla uživatelského obsahu', 'atlas-chuti' ), null ),
			'autorska-prava'           => array( __( 'Autorská práva', 'atlas-chuti' ), null ),
			'nastaveni-cookies'        => array( __( 'Nastavení cookies', 'atlas-chuti' ), null ),
		);
	}

	/**
	 * Programmatic, idempotent page bootstrap shared by wp-admin and WP-CLI.
	 *
	 * Safe by default: with $write=false nothing is written. Archive entries are
	 * reported but never created as Pages. Existing Pages are never modified.
	 *
	 * @param bool $write Whether missing Pages should actually be created.
	 * @return array
	 */
	public function bootstrap_pages( $write = false ) {
		$rows = array();

		foreach ( $this->expected_pages() as $slug => $page_def ) {
			list( $label, $template ) = $page_def;
			$status_override = isset( $page_def[2] ) ? $page_def[2] : 'draft';

			if ( 'archive' === $template ) {
				$rows[] = array(
					'slug'    => $slug,
					'title'   => $label,
					'status'  => 'skipped',
					'message' => __( 'CPT archiv — WordPress Page se nevytváří.', 'atlas-chuti' ),
				);
				continue;
			}

			$existing = get_page_by_path( $slug );
			if ( $existing ) {
				$rows[] = array(
					'slug'    => $slug,
					'title'   => $label,
					'status'  => 'existing',
					'message' => sprintf( 'ID %d', (int) $existing->ID ),
				);
				continue;
			}

			if ( ! $write ) {
				$rows[] = array(
					'slug'    => $slug,
					'title'   => $label,
					'status'  => 'skipped',
					'message' => __( 'Dry-run: stránka by byla vytvořena.', 'atlas-chuti' ),
				);
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_title'   => $label,
					'post_name'    => $slug,
					'post_status'  => $status_override,
					'post_content' => '',
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				$rows[] = array(
					'slug'    => $slug,
					'title'   => $label,
					'status'  => 'error',
					'message' => $page_id->get_error_message(),
				);
				continue;
			}

			if ( $template ) {
				update_post_meta( $page_id, '_wp_page_template', $template );
			}

			$rows[] = array(
				'slug'    => $slug,
				'title'   => $label,
				'status'  => 'created',
				'message' => sprintf( 'ID %d', (int) $page_id ),
			);
		}

		return array(
			'write' => (bool) $write,
			'rows'  => $rows,
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
				<?php foreach ( $this->expected_pages() as $slug => $page_def ) : list( $label, $template ) = $page_def; ?>
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
			$archive_post_types = array(
				'recepty'   => 'atlas_recipe',
				'slovnicek' => 'atlas_glossary',
				'diskuze'   => 'atlas_topic',
			);
			$post_type = $archive_post_types[ $slug ] ?? 'atlas_recipe';
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

		$report  = $this->bootstrap_pages( true );
		$created = 0;
		foreach ( $report['rows'] as $row ) {
			if ( 'created' === $row['status'] ) {
				++$created;
			}
		}

		set_transient( 'atlas_chuti_page_setup_result_' . get_current_user_id(), $created, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-pages' ) );
		exit;
	}
}

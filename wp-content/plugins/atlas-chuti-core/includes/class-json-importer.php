<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Atlas chutí → Import obsahu": admin page that turns structured JSON (see
 * /schema in the repo) into real WordPress posts/meta/taxonomy terms — never into
 * hardcoded HTML pages. Supports countries, recipes, glossary and ingredients in one
 * batch file, cross-references entities by slug, validates before writing, offers a
 * dry-run preview, detects duplicates (upsert instead of duplicating), and never lets
 * one bad row abort the whole batch. This exact contract is what a future AI module
 * will also have to produce (item 29 of the brief) — so it must stay stable.
 */
class Atlas_Chuti_JSON_Importer {

	private static $instance = null;

	const CAPABILITY = 'manage_options';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_atlas_import', array( $this, 'handle_submit' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Atlas chutí', 'atlas-chuti' ),
			__( 'Atlas chutí', 'atlas-chuti' ),
			self::CAPABILITY,
			'atlas-chuti-import',
			array( $this, 'render_page' ),
			'dashicons-embed-generic',
			3
		);
		add_submenu_page(
			'atlas-chuti-import',
			__( 'Import obsahu', 'atlas-chuti' ),
			__( 'Import obsahu', 'atlas-chuti' ),
			self::CAPABILITY,
			'atlas-chuti-import',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}

		$report = get_transient( 'atlas_chuti_last_import_report_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Import obsahu', 'atlas-chuti' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %1$s-%2$s: folder names /schema and /sample-data, %3$s-%6$s: JSON top-level keys */
					esc_html__( 'Nahrajte nebo vložte strukturovaný JSON (viz %1$s a %2$s v repozitáři). Podporované typy v jednom souboru: %3$s, %4$s, %5$s, %6$s.', 'atlas-chuti' ),
					'<code>/schema</code>',
					'<code>/sample-data</code>',
					'<code>countries</code>',
					'<code>recipes</code>',
					'<code>glossary</code>',
					'<code>ingredients</code>'
				); // phpcs:ignore -- static <code> tags, nothing user-supplied.
				?>
			</p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'atlas_import', 'atlas_import_nonce' ); ?>
				<input type="hidden" name="action" value="atlas_import">

				<table class="form-table">
					<tr>
						<th><label for="atlas_import_file"><?php esc_html_e( 'JSON soubor', 'atlas-chuti' ); ?></label></th>
						<td><input type="file" name="atlas_import_file" id="atlas_import_file" accept="application/json,.json"></td>
					</tr>
					<tr>
						<th><label for="atlas_import_json"><?php esc_html_e( '…nebo vložte JSON přímo', 'atlas-chuti' ); ?></label></th>
						<td><textarea name="atlas_import_json" id="atlas_import_json" rows="10" style="width:100%;font-family:monospace;"></textarea></td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-secondary" name="atlas_import_mode" value="dry_run"><?php esc_html_e( 'Zkontrolovat (dry-run)', 'atlas-chuti' ); ?></button>
					<button type="submit" class="button button-primary" name="atlas_import_mode" value="live"><?php esc_html_e( 'Spustit import', 'atlas-chuti' ); ?></button>
				</p>
			</form>

			<?php if ( $report ) : ?>
				<div class="atlas-import-report">
					<h2><?php echo $report['dry_run'] ? esc_html__( 'Náhled importu (dry-run) – nic nebylo uloženo', 'atlas-chuti' ) : esc_html__( 'Výsledek importu', 'atlas-chuti' ); ?></h2>
					<?php if ( ! empty( $report['fatal'] ) ) : ?>
						<p class="atlas-import-status-error"><?php echo esc_html( $report['fatal'] ); ?></p>
					<?php else : ?>
						<?php foreach ( $report['groups'] as $type => $rows ) : ?>
							<?php if ( empty( $rows ) ) { continue; } ?>
							<h3><?php echo esc_html( $type ); ?> (<?php echo count( $rows ); ?>)</h3>
							<table>
								<thead><tr><th><?php esc_html_e( 'Název', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'Stav', 'atlas-chuti' ); ?></th><th><?php esc_html_e( 'Poznámka', 'atlas-chuti' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['title'] ); ?></td>
										<td class="atlas-import-status-<?php echo esc_attr( $row['css'] ); ?>"><?php echo esc_html( $row['status'] ); ?></td>
										<td><?php echo esc_html( $row['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_submit() {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['atlas_import_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_import_nonce'], 'atlas_import' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}

		$dry_run = ( 'dry_run' === ( $_POST['atlas_import_mode'] ?? '' ) );
		$json    = '';

		if ( ! empty( $_FILES['atlas_import_file']['tmp_name'] ) && is_uploaded_file( $_FILES['atlas_import_file']['tmp_name'] ) ) {
			$ext = strtolower( pathinfo( $_FILES['atlas_import_file']['name'], PATHINFO_EXTENSION ) );
			if ( 'json' !== $ext ) {
				$this->store_report( array( 'dry_run' => $dry_run, 'fatal' => __( 'Soubor musí mít příponu .json.', 'atlas-chuti' ) ) );
				$this->redirect_back();
			}
			$json = file_get_contents( $_FILES['atlas_import_file']['tmp_name'] );
		} elseif ( ! empty( $_POST['atlas_import_json'] ) ) {
			$json = wp_unslash( $_POST['atlas_import_json'] );
		}

		if ( '' === trim( (string) $json ) ) {
			$this->store_report( array( 'dry_run' => $dry_run, 'fatal' => __( 'Nebyl poskytnut žádný JSON.', 'atlas-chuti' ) ) );
			$this->redirect_back();
		}

		$data = json_decode( $json, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			$this->store_report( array( 'dry_run' => $dry_run, 'fatal' => sprintf( __( 'JSON se nepodařilo zpracovat: %s', 'atlas-chuti' ), json_last_error_msg() ) ) );
			$this->redirect_back();
		}
		if ( ! is_array( $data ) ) {
			$this->store_report( array( 'dry_run' => $dry_run, 'fatal' => __( 'Očekáván JSON objekt s klíči countries/recipes/glossary/ingredients.', 'atlas-chuti' ) ) );
			$this->redirect_back();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$report = $this->run_import( $data, $dry_run );
		$this->store_report( $report );
		$this->redirect_back();
	}

	private function store_report( $report ) {
		set_transient( 'atlas_chuti_last_import_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-import' ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Import engine
	// ---------------------------------------------------------------------

	private function run_import( $data, $dry_run ) {
		$groups = array(
			'ingredients' => array(),
			'countries'   => array(),
			'glossary'    => array(),
			'recipes'     => array(),
		);

		// Pass 1: create/update every entity's own fields (not cross-references yet).
		$created_map = array( 'atlas_ingredient' => array(), 'atlas_country' => array(), 'atlas_glossary' => array(), 'atlas_recipe' => array() );

		// Stable, language-independent identity per entity (item 17 of the brief):
		// ISO code for countries, translation_group for recipes/glossary, canonical
		// key for ingredients. Cross-references (pass 2) resolve against this first —
		// the slug-based $created_map above is only a convenience fallback for as long
		// as a single locale exists.
		$stable_map = array( 'atlas_ingredient' => array(), 'atlas_country' => array(), 'atlas_glossary' => array(), 'atlas_recipe' => array() );

		if ( ! empty( $data['ingredients'] ) && is_array( $data['ingredients'] ) ) {
			foreach ( $data['ingredients'] as $item ) {
				$groups['ingredients'][] = $this->import_ingredient( $item, $dry_run, $created_map, $stable_map );
			}
		}
		if ( ! empty( $data['countries'] ) && is_array( $data['countries'] ) ) {
			foreach ( $data['countries'] as $item ) {
				$groups['countries'][] = $this->import_country( $item, $dry_run, $created_map, $stable_map );
			}
		}
		if ( ! empty( $data['glossary'] ) && is_array( $data['glossary'] ) ) {
			foreach ( $data['glossary'] as $item ) {
				$groups['glossary'][] = $this->import_glossary( $item, $dry_run, $created_map, $stable_map );
			}
		}
		if ( ! empty( $data['recipes'] ) && is_array( $data['recipes'] ) ) {
			foreach ( $data['recipes'] as $item ) {
				$groups['recipes'][] = $this->import_recipe( $item, $dry_run, $created_map, $stable_map );
			}
		}

		// Pass 2: resolve cross-references (by stable key, slug as fallback) now that
		// everything in this batch exists.
		if ( ! $dry_run ) {
			$this->resolve_references( $data, $created_map, $stable_map );
		}

		return array( 'dry_run' => $dry_run, 'groups' => $groups );
	}

	private function find_existing( $post_type, $slug, $extra_meta_match = array() ) {
		$args = array(
			'post_type'      => $post_type,
			'name'           => $slug,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 1,
		);
		$posts = get_posts( $args );
		if ( $posts ) {
			return $posts[0];
		}

		if ( $extra_meta_match ) {
			$meta_query = array();
			foreach ( $extra_meta_match as $key => $value ) {
				$meta_query[] = array( 'key' => $key, 'value' => $value );
			}
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => 1,
					'meta_query'     => $meta_query,
				)
			);
			if ( $posts ) {
				return $posts[0];
			}
		}
		return null;
	}

	private function row( $title, $status, $css, $message = '' ) {
		return array( 'title' => $title, 'status' => $status, 'css' => $css, 'message' => $message );
	}

	private function label_untitled() {
		return __( '(bez názvu)', 'atlas-chuti' );
	}

	private function label_error() {
		return __( 'chyba', 'atlas-chuti' );
	}

	private function label_missing_fields( $missing ) {
		/* translators: %s: comma-separated list of missing field names */
		return sprintf( __( 'Chybí povinná pole: %s', 'atlas-chuti' ), implode( ', ', $missing ) );
	}

	private function status_would( $existing ) {
		return $existing ? __( 'bude aktualizováno', 'atlas-chuti' ) : __( 'bude vytvořeno', 'atlas-chuti' );
	}

	private function status_done( $existing ) {
		return $existing ? __( 'aktualizováno', 'atlas-chuti' ) : __( 'vytvořeno', 'atlas-chuti' );
	}

	/**
	 * Writes locale/translation_group/translation_status when the JSON provides them.
	 * When it doesn't, class-i18n.php's save_post hook already backfilled sane
	 * defaults (cs-CZ, slug-as-group, "published") during wp_insert_post above, so
	 * there's nothing to do — the contract holds either way.
	 */
	private function apply_i18n_meta( $post_id, $item ) {
		if ( ! empty( $item['locale'] ) ) {
			update_post_meta( $post_id, 'atlas_locale', sanitize_text_field( $item['locale'] ) );
		}
		if ( ! empty( $item['translation_group'] ) ) {
			update_post_meta( $post_id, 'atlas_translation_group', sanitize_title( $item['translation_group'] ) );
		}
		if ( ! empty( $item['translation_status'] ) && in_array( $item['translation_status'], array( 'none', 'draft', 'reviewed', 'published' ), true ) ) {
			update_post_meta( $post_id, 'atlas_translation_status', $item['translation_status'] );
		}
	}

	/**
	 * The language-independent key this item should be found by in pass 2: ISO code
	 * for countries, translation_group for recipes/glossary, canonical key for
	 * ingredients — falling back to the slug only while a single locale exists.
	 */
	private function stable_key_for( $post_type, $item, $slug ) {
		if ( 'atlas_country' === $post_type ) {
			return ! empty( $item['iso_code'] ) ? strtoupper( $item['iso_code'] ) : '';
		}
		if ( 'atlas_ingredient' === $post_type ) {
			return sanitize_title( ! empty( $item['key'] ) ? $item['key'] : $slug );
		}
		return sanitize_title( ! empty( $item['translation_group'] ) ? $item['translation_group'] : $slug );
	}

	private function validate_required( $item, $required_keys ) {
		$missing = array();
		foreach ( $required_keys as $key ) {
			if ( ! isset( $item[ $key ] ) || '' === trim( (string) ( is_array( $item[ $key ] ) ? wp_json_encode( $item[ $key ] ) : $item[ $key ] ) ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	// -- Ingredients ---------------------------------------------------

	private function import_ingredient( $item, $dry_run, &$created_map, &$stable_map ) {
		$title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$missing = $this->validate_required( $item, array( 'title' ) );
		if ( $missing ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $missing ) );
		}

		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_ingredient', $item, $slug );
		// A canonical key match wins over a slug match — the whole point of the key is
		// that "rajce" (cs) and "tomato" (en) can point at the same normalized entity.
		$existing   = ( $stable_key ? Atlas_Chuti_I18N::find_ingredient_by_key( $stable_key ) : null ) ?: $this->find_existing( 'atlas_ingredient', $slug );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_ingredient',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'atlas_aliases', Atlas_Chuti_Meta_Fields::sanitize( 'string_list', $item['aliases'] ?? array() ) );
		update_post_meta( $post_id, 'atlas_default_unit', sanitize_text_field( $item['default_unit'] ?? '' ) );
		update_post_meta( $post_id, 'atlas_key', $stable_key ?: $slug );
		$this->apply_i18n_meta( $post_id, $item );

		$created_map['atlas_ingredient'][ $slug ] = $post_id;
		if ( $stable_key ) {
			$stable_map['atlas_ingredient'][ $stable_key ] = $post_id;
		}
		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Countries -------------------------------------------------------

	private function import_country( $item, $dry_run, &$created_map, &$stable_map ) {
		$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : ( $item['name_cs'] ?? '' );
		$missing = $this->validate_required( $item, array( 'title', 'iso_code', 'flag_emoji', 'intro', 'continent' ) );
		if ( $missing ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $missing ) );
		}

		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_country', $item, $slug );
		// ISO code is this entity's real identity (item 17 of the brief) — it's what
		// a re-import, or a future .com instance, recognizes "this is the same country" by.
		$existing   = Atlas_Chuti_I18N::find_country_by_iso( $stable_key ) ?: $this->find_existing( 'atlas_country', $slug );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_country',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		$fields = Atlas_Chuti_Meta_Fields::country_fields();
		foreach ( $fields as $key => $field ) {
			if ( in_array( $field['type'], array( 'post_ref', 'post_ref_list' ), true ) ) {
				continue; // resolved in pass 2
			}
			if ( array_key_exists( $key, $item ) ) {
				$shape = $field['shape'] ?? array();
				update_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( $key ), Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $item[ $key ], $shape ) );
			}
		}

		if ( isset( $item['continent'] ) ) {
			wp_set_post_terms( $post_id, array( sanitize_text_field( $item['continent'] ) ), 'atlas_continent', false );
		}
		if ( isset( $item['featured_image_alt'] ) && has_post_thumbnail( $post_id ) ) {
			update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', sanitize_text_field( $item['featured_image_alt'] ) );
		}

		update_post_meta( $post_id, 'atlas_iso_code', strtoupper( $item['iso_code'] ) );
		$this->apply_i18n_meta( $post_id, $item );

		// Country CPT save action (class-country-sync.php) runs on wp_insert_post's save_post hook automatically.
		$created_map['atlas_country'][ $slug ] = $post_id;
		if ( $stable_key ) {
			$stable_map['atlas_country'][ $stable_key ] = $post_id;
		}
		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Glossary ----------------------------------------------------------

	private function import_glossary( $item, $dry_run, &$created_map, &$stable_map ) {
		$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$missing = $this->validate_required( $item, array( 'title', 'category', 'short_definition' ) );
		if ( $missing ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $missing ) );
		}

		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_glossary', $item, $slug );
		$existing   = ( $stable_key ? Atlas_Chuti_I18N::find_by_translation_group( 'atlas_glossary', $stable_key ) : null ) ?: $this->find_existing( 'atlas_glossary', $slug );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_glossary',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		$fields = Atlas_Chuti_Meta_Fields::glossary_fields();
		foreach ( $fields as $key => $field ) {
			if ( in_array( $field['type'], array( 'post_ref', 'post_ref_list' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $key, $item ) ) {
				update_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( $key ), Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $item[ $key ] ) );
			}
		}
		if ( isset( $item['category'] ) ) {
			wp_set_post_terms( $post_id, array( sanitize_text_field( $item['category'] ) ), 'atlas_glossary_category', false );
		}
		$this->apply_i18n_meta( $post_id, $item );

		$created_map['atlas_glossary'][ $slug ] = $post_id;
		if ( $stable_key ) {
			$stable_map['atlas_glossary'][ $stable_key ] = $post_id;
		}
		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Recipes -------------------------------------------------------------

	private function import_recipe( $item, $dry_run, &$created_map, &$stable_map ) {
		$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$missing = $this->validate_required( $item, array( 'title', 'country', 'excerpt', 'servings_default', 'prep_minutes', 'ingredients', 'steps' ) );
		if ( $missing ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $missing ) );
		}

		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_recipe', $item, $slug );
		$original   = sanitize_text_field( $item['original_title'] ?? '' );
		$existing   = ( $stable_key ? Atlas_Chuti_I18N::find_by_translation_group( 'atlas_recipe', $stable_key ) : null )
			?: $this->find_existing( 'atlas_recipe', $slug, $original ? array( 'atlas_original_title' => $original ) : array() );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_recipe',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		$fields = Atlas_Chuti_Meta_Fields::recipe_fields();
		foreach ( $fields as $key => $field ) {
			if ( in_array( $field['type'], array( 'post_ref', 'post_ref_list' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $key, $item ) ) {
				$shape = $field['shape'] ?? array();
				update_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( $key ), Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $item[ $key ], $shape ) );
			}
		}

		if ( ! empty( $item['meal_type'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', (array) $item['meal_type'] ), 'atlas_meal_type', false );
		}
		if ( ! empty( $item['difficulty'] ) ) {
			wp_set_post_terms( $post_id, array( sanitize_text_field( $item['difficulty'] ) ), 'atlas_difficulty', false );
		}
		if ( ! empty( $item['diet'] ) ) {
			wp_set_post_terms( $post_id, array_map( 'sanitize_text_field', (array) $item['diet'] ), 'atlas_diet', false );
		}
		$this->apply_i18n_meta( $post_id, $item );

		$created_map['atlas_recipe'][ $slug ] = $post_id;
		if ( $stable_key ) {
			$stable_map['atlas_recipe'][ $stable_key ] = $post_id;
		}
		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Pass 2: reference resolution ------------------------------------------

	/**
	 * Resolves a cross-reference to a post ID. Tries the stable, language-independent
	 * identity first (ISO code / translation_group / ingredient key — matching this
	 * batch's in-memory $stable_map, then the database via Atlas_Chuti_I18N), and only
	 * falls back to treating the reference as a slug for convenience while a single
	 * locale exists. Never requires the caller to know a WordPress post ID.
	 */
	private function resolve_reference( $post_type, $ref, $created_map, $stable_map ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return 0;
		}

		$stable_key = 'atlas_country' === $post_type ? strtoupper( $ref ) : sanitize_title( $ref );
		if ( isset( $stable_map[ $post_type ][ $stable_key ] ) ) {
			return $stable_map[ $post_type ][ $stable_key ];
		}

		if ( 'atlas_country' === $post_type ) {
			$post = Atlas_Chuti_I18N::find_country_by_iso( $ref );
		} elseif ( 'atlas_ingredient' === $post_type ) {
			$post = Atlas_Chuti_I18N::find_ingredient_by_key( $stable_key );
		} else {
			$post = Atlas_Chuti_I18N::find_by_translation_group( $post_type, $stable_key );
		}
		if ( $post ) {
			return $post->ID;
		}

		// Fallback: the reference might just be a slug.
		$slug = sanitize_title( $ref );
		if ( isset( $created_map[ $post_type ][ $slug ] ) ) {
			return $created_map[ $post_type ][ $slug ];
		}
		$post = get_page_by_path( $slug, OBJECT, $post_type );
		return $post ? $post->ID : 0;
	}

	private function resolve_references( $data, $created_map, $stable_map ) {
		if ( ! empty( $data['countries'] ) ) {
			foreach ( $data['countries'] as $item ) {
				$slug = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
				$post_id = $this->resolve_reference( 'atlas_country', $slug, $created_map, $stable_map );
				if ( ! $post_id ) {
					continue;
				}
				if ( ! empty( $item['related_countries'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $created_map, $stable_map ), (array) $item['related_countries'] ) );
					update_post_meta( $post_id, 'atlas_related_countries', array_values( $ids ) );
				}
				if ( ! empty( $item['related_glossary'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_glossary', $s, $created_map, $stable_map ), (array) $item['related_glossary'] ) );
					update_post_meta( $post_id, 'atlas_related_glossary', array_values( $ids ) );
				}
				if ( ! empty( $item['traditional_dishes'] ) ) {
					$dishes = get_post_meta( $post_id, 'atlas_traditional_dishes', true );
					if ( is_array( $dishes ) ) {
						foreach ( $dishes as $i => $dish ) {
							if ( ! empty( $dish['recipe_id'] ) ) {
								$dishes[ $i ]['recipe_id'] = (string) $this->resolve_reference( 'atlas_recipe', $dish['recipe_id'], $created_map, $stable_map );
							}
						}
						update_post_meta( $post_id, 'atlas_traditional_dishes', $dishes );
					}
				}
			}
		}

		if ( ! empty( $data['glossary'] ) ) {
			foreach ( $data['glossary'] as $item ) {
				$slug    = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
				$post_id = $this->resolve_reference( 'atlas_glossary', $slug, $created_map, $stable_map );
				if ( ! $post_id ) {
					continue;
				}
				if ( ! empty( $item['origin_country'] ) ) {
					update_post_meta( $post_id, 'atlas_origin_country_id', $this->resolve_reference( 'atlas_country', $item['origin_country'], $created_map, $stable_map ) );
				}
				if ( ! empty( $item['related_recipes'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_recipe', $s, $created_map, $stable_map ), (array) $item['related_recipes'] ) );
					update_post_meta( $post_id, 'atlas_related_recipes', array_values( $ids ) );
				}
				if ( ! empty( $item['related_countries'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $created_map, $stable_map ), (array) $item['related_countries'] ) );
					update_post_meta( $post_id, 'atlas_related_countries', array_values( $ids ) );
					$terms = array_filter( array_map( fn( $id ) => Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $id ), $ids ) );
					if ( $terms ) {
						wp_set_post_terms( $post_id, $terms, 'atlas_country_tax', true );
					}
				}
			}
		}

		if ( ! empty( $data['recipes'] ) ) {
			foreach ( $data['recipes'] as $item ) {
				$slug    = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
				$post_id = $this->resolve_reference( 'atlas_recipe', $slug, $created_map, $stable_map );
				if ( ! $post_id ) {
					continue;
				}

				$primary_country_post = $this->resolve_reference( 'atlas_country', $item['country'], $created_map, $stable_map );
				$related_country_posts = ! empty( $item['related_countries'] ) ? array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $created_map, $stable_map ), (array) $item['related_countries'] ) : array();

				$primary_term = $primary_country_post ? Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $primary_country_post ) : 0;
				$related_terms = array_filter( array_map( fn( $id ) => Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $id ), array_filter( $related_country_posts ) ) );

				$all_terms = $primary_term ? array_unique( array_merge( array( $primary_term ), $related_terms ) ) : $related_terms;
				if ( $all_terms ) {
					wp_set_post_terms( $post_id, $all_terms, 'atlas_country_tax', false );
				}
				if ( $primary_term ) {
					update_post_meta( $post_id, '_atlas_recipe_primary_country_term_id', $primary_term );
				}

				if ( ! empty( $item['related_recipes'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_recipe', $s, $created_map, $stable_map ), (array) $item['related_recipes'] ) );
					update_post_meta( $post_id, 'atlas_related_recipes', array_values( $ids ) );
				}
				if ( ! empty( $item['related_glossary'] ) ) {
					$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_glossary', $s, $created_map, $stable_map ), (array) $item['related_glossary'] ) );
					update_post_meta( $post_id, 'atlas_related_glossary', array_values( $ids ) );
				}

				$total = get_post_meta( $post_id, 'atlas_total_minutes', true );
				if ( '' === $total || 0 === (int) $total ) {
					$prep = (int) get_post_meta( $post_id, 'atlas_prep_minutes', true );
					$cook = (int) get_post_meta( $post_id, 'atlas_cook_minutes', true );
					update_post_meta( $post_id, 'atlas_total_minutes', $prep + $cook );
				}
			}
		}
	}
}

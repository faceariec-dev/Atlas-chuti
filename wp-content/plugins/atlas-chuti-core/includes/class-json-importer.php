<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Atlas chutí → Import obsahu": admin page that turns structured JSON (see
 * /schema in the repo) into real WordPress posts/meta/taxonomy terms — never into
 * hardcoded HTML pages. Supports countries, recipes, glossary and ingredients in one
 * batch file, validates thoroughly before writing (item 10 of this phase's brief),
 * offers a dry-run preview, detects duplicates (upsert instead of duplicating), and
 * never lets one bad row abort the whole batch. This exact contract is what a future
 * AI module will also have to produce (item 27) — so it must stay stable.
 *
 * All cross-references resolve purely from the database (by stable key — ISO code,
 * translation_group, ingredient_key — falling back to slug), never from in-memory
 * state built up during this request. That's not just simpler, it's what makes the
 * live import safely resumable across multiple HTTP requests (item 11): each batch
 * step commits its writes immediately, so the very next request already sees them.
 */
class Atlas_Chuti_JSON_Importer {

	private static $instance = null;

	const CAPABILITY = 'manage_options';
	const BATCH_SIZE = 8; // items processed per request — keeps a 50-recipe batch far under any shared-hosting execution time limit.
	const BATCH_TRANSIENT_PREFIX = 'atlas_chuti_import_batch_';
	const REPORT_TRANSIENT_PREFIX = 'atlas_chuti_last_import_report_';

	// Stable-key closed vocabularies (item 14/15/18 of this phase's brief) — the
	// import contract now speaks the same language-neutral keys the taxonomies are
	// seeded with (see class-taxonomy-labels.php), never Czech display text. meal_type
	// and diet stay open vocabularies and are NOT validated against a closed list.
	const VALID_CONTINENTS  = array( 'europe', 'asia', 'africa', 'north-america', 'south-america', 'oceania' );
	const VALID_DIFFICULTY  = array( 'easy', 'medium', 'hard' );
	const VALID_GLOSSARY_CATEGORY = array( 'technique', 'ingredient', 'gastronomy', 'equipment' );
	const VALID_STATUS      = array( 'publish', 'draft' );
	const VALID_TRANSLATION_STATUS = array( 'none', 'draft', 'reviewed', 'published' );

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_atlas_import', array( $this, 'handle_submit' ) );
		add_action( 'admin_post_atlas_import_cancel', array( $this, 'handle_cancel' ) );
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

	// ---------------------------------------------------------------------
	// Admin page
	// ---------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}

		$session = get_transient( self::BATCH_TRANSIENT_PREFIX . get_current_user_id() );
		if ( $session && isset( $_GET['batch'] ) ) {
			$session = $this->process_batch_step( $session );
			if ( $session['cursor'] >= count( $session['tasks'] ) ) {
				$this->finalize_batch( $session );
				$session = null;
			} else {
				set_transient( self::BATCH_TRANSIENT_PREFIX . get_current_user_id(), $session, HOUR_IN_SECONDS );
			}
		}

		if ( $session ) {
			$this->render_batch_progress( $session );
			return;
		}

		$report = get_transient( self::REPORT_TRANSIENT_PREFIX . get_current_user_id() );
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

			<?php $this->render_report( $report ); ?>
		</div>
		<?php
	}

	private function render_batch_progress( $session ) {
		$total     = count( $session['tasks'] );
		$done      = min( $session['cursor'], $total );
		$percent   = $total ? round( ( $done / $total ) * 100 ) : 100;
		$next_url  = add_query_arg( array( 'page' => 'atlas-chuti-import', 'batch' => 1 ), admin_url( 'admin.php' ) );
		$cancel_url = wp_nonce_url( admin_url( 'admin-post.php?action=atlas_import_cancel' ), 'atlas_import_cancel' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Import obsahu', 'atlas-chuti' ); ?></h1>
			<meta http-equiv="refresh" content="1;url=<?php echo esc_url( $next_url ); ?>">
			<p>
				<?php
				/* translators: %1$d: items processed so far, %2$d: total items */
				printf( esc_html__( 'Zpracováno %1$d z %2$d položek…', 'atlas-chuti' ), $done, $total );
				?>
			</p>
			<div style="background:#e0e0e0;border-radius:4px;overflow:hidden;max-width:480px;height:18px;">
				<div style="background:#2271b1;height:100%;width:<?php echo esc_attr( $percent ); ?>%;"></div>
			</div>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Pokračovat', 'atlas-chuti' ); ?></a>
				<a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Zrušit import', 'atlas-chuti' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Tato stránka se sama obnoví. Import proběhne po menších dávkách, aby na běžném sdíleném hostingu nedošlo k timeoutu — klidně stránku zavřete a vraťte se později, import bude pokračovat tam, kde skončil.', 'atlas-chuti' ); ?></p>
		</div>
		<?php
	}

	private function render_report( $report ) {
		if ( ! $report ) {
			return;
		}
		?>
		<div class="atlas-import-report">
			<h2><?php echo $report['dry_run'] ? esc_html__( 'Náhled importu (dry-run) – nic nebylo uloženo', 'atlas-chuti' ) : esc_html__( 'Výsledek importu', 'atlas-chuti' ); ?></h2>
			<?php if ( ! empty( $report['fatal'] ) ) : ?>
				<p class="atlas-import-status-error"><?php echo esc_html( $report['fatal'] ); ?></p>
			<?php else : ?>
				<?php
				$counts = array( 'ok' => 0, 'skip' => 0, 'error' => 0 );
				foreach ( $report['groups'] as $rows ) {
					foreach ( $rows as $row ) {
						if ( isset( $counts[ $row['css'] ] ) ) {
							++$counts[ $row['css'] ];
						}
					}
				}
				?>
				<p>
					<?php
					printf(
						/* translators: 1: created/updated count, 2: skipped/duplicate count, 3: error count */
						esc_html__( 'V pořádku: %1$d · Přeskočeno: %2$d · Chyby: %3$d', 'atlas-chuti' ),
						(int) $counts['ok'],
						(int) $counts['skip'],
						(int) $counts['error']
					);
					?>
				</p>
				<?php foreach ( $report['groups'] as $type => $rows ) : ?>
					<?php if ( empty( $rows ) ) { continue; } ?>
					<h3><?php echo esc_html( $type ); ?> (<?php echo count( $rows ); ?>)</h3>
					<table class="widefat striped">
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

		if ( $dry_run ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 );
			}
			$this->store_report( $this->run_import_sync( $data, true ) );
			$this->redirect_back();
		}

		// Live import: build the task queue and hand off to the batch runner so a
		// 50-recipe payload never runs as one long, uncontrolled PHP request (item 11).
		$tasks = $this->build_task_queue( $data );
		set_transient(
			self::BATCH_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'data'    => $data,
				'tasks'   => $tasks,
				'cursor'  => 0,
				'results' => array( 'ingredients' => array(), 'countries' => array(), 'glossary' => array(), 'recipes' => array() ),
			),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( array( 'page' => 'atlas-chuti-import', 'batch' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_cancel() {
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'atlas_import_cancel' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		delete_transient( self::BATCH_TRANSIENT_PREFIX . get_current_user_id() );
		$this->redirect_back();
	}

	/**
	 * Runs a full import (all create + resolve tasks) synchronously in one call —
	 * used by `wp atlas import` where there's no PHP execution time limit to work
	 * around, so the batching in build_task_queue()/process_batch_step() (built for
	 * the web admin on shared hosting) isn't needed. Same underlying create/resolve
	 * methods as the batched path, so behavior is identical either way.
	 */
	public function run_import_sync( $data, $dry_run ) {
		$groups = array( 'ingredients' => array(), 'countries' => array(), 'glossary' => array(), 'recipes' => array() );
		foreach ( array( 'ingredients', 'countries', 'glossary', 'recipes' ) as $group ) {
			if ( ! empty( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
				foreach ( $data[ $group ] as $item ) {
					$groups[ $group ][] = $this->import_one( $this->singular( $group ), $item, $dry_run );
				}
			}
		}
		if ( ! $dry_run ) {
			foreach ( array( 'countries', 'glossary', 'recipes' ) as $group ) {
				if ( ! empty( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
					foreach ( $data[ $group ] as $item ) {
						$this->resolve_one( $this->singular( $group ), $item );
					}
				}
			}
		}
		return array( 'dry_run' => $dry_run, 'groups' => $groups );
	}

	private function singular( $entity_group ) {
		$map = array( 'ingredients' => 'ingredient', 'countries' => 'country', 'glossary' => 'glossary', 'recipes' => 'recipe' );
		return $map[ $entity_group ] ?? $entity_group;
	}

	private function store_report( $report ) {
		set_transient( self::REPORT_TRANSIENT_PREFIX . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-import' ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Batch runner (item 11)
	// ---------------------------------------------------------------------

	/**
	 * Flat, resumable task queue: create every ingredient/country/glossary/recipe
	 * first (in that order, so recipes can find countries/glossary created earlier
	 * in the SAME payload), then resolve cross-references for country/glossary/
	 * recipe. Every task commits its own writes immediately, so resolution can
	 * safely rely on the database alone — see the class docblock.
	 */
	private function build_task_queue( $data ) {
		$tasks = array();
		foreach ( array( 'ingredients', 'countries', 'glossary', 'recipes' ) as $group ) {
			if ( ! empty( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
				foreach ( array_keys( $data[ $group ] ) as $i ) {
					$tasks[] = array( 'op' => 'create', 'entity' => $this->singular( $group ), 'group' => $group, 'index' => $i );
				}
			}
		}
		foreach ( array( 'countries', 'glossary', 'recipes' ) as $group ) {
			if ( ! empty( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
				foreach ( array_keys( $data[ $group ] ) as $i ) {
					$tasks[] = array( 'op' => 'resolve', 'entity' => $this->singular( $group ), 'group' => $group, 'index' => $i );
				}
			}
		}
		return $tasks;
	}

	private function process_batch_step( $session ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$end = min( $session['cursor'] + self::BATCH_SIZE, count( $session['tasks'] ) );
		for ( $i = $session['cursor']; $i < $end; $i++ ) {
			$task = $session['tasks'][ $i ];
			$item = $session['data'][ $task['group'] ][ $task['index'] ] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( 'create' === $task['op'] ) {
				$session['results'][ $task['group'] ][] = $this->import_one( $task['entity'], $item, false );
			} else {
				$this->resolve_one( $task['entity'], $item );
			}
		}
		$session['cursor'] = $end;
		return $session;
	}

	private function finalize_batch( $session ) {
		$this->store_report( array( 'dry_run' => false, 'groups' => $session['results'] ) );
		delete_transient( self::BATCH_TRANSIENT_PREFIX . get_current_user_id() );
	}

	private function import_one( $entity, $item, $dry_run ) {
		switch ( $entity ) {
			case 'ingredient':
				return $this->import_ingredient( $item, $dry_run );
			case 'country':
				return $this->import_country( $item, $dry_run );
			case 'glossary':
				return $this->import_glossary( $item, $dry_run );
			case 'recipe':
				return $this->import_recipe( $item, $dry_run );
		}
		return $this->row( $this->label_untitled(), $this->label_error(), 'error', 'Unknown entity type.' );
	}

	private function resolve_one( $entity, $item ) {
		switch ( $entity ) {
			case 'country':
				$this->resolve_country_refs( $item );
				break;
			case 'glossary':
				$this->resolve_glossary_refs( $item );
				break;
			case 'recipe':
				$this->resolve_recipe_refs( $item );
				break;
		}
	}

	// ---------------------------------------------------------------------
	// Shared helpers
	// ---------------------------------------------------------------------

	/**
	 * Slug-based duplicate fallback, scoped to one locale (item 2 of this phase's
	 * brief): a post with a given slug is looked up only among posts already in that
	 * locale, so an English import can never accidentally match/overwrite a Czech
	 * post just because a slug happened to coincide.
	 */
	private function find_existing( $post_type, $slug, $locale, $extra_meta_match = array() ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				'meta_query'     => array( array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ) ),
			)
		);
		if ( $posts ) {
			return $posts[0];
		}
		if ( $extra_meta_match ) {
			$meta_query = array( array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ) );
			foreach ( $extra_meta_match as $key => $value ) {
				$meta_query[] = array( 'key' => $key, 'value' => $value );
			}
			$posts = get_posts( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 1, 'meta_query' => $meta_query ) );
			if ( $posts ) {
				return $posts[0];
			}
		}
		return null;
	}

	/**
	 * The locale to use for THIS import item (item 2/3 of this phase's brief):
	 * whatever the JSON explicitly says, else cs-CZ. Computed once per item and
	 * threaded into every lookup/resolution call for that item — never resolved
	 * implicitly from the current request (an admin import can create English
	 * content while the site itself is still cs-CZ-only).
	 */
	private function resolve_item_locale( $item ) {
		if ( ! empty( $item['locale'] ) && $this->is_valid_locale( $item['locale'] ) ) {
			return $item['locale'];
		}
		return Atlas_Chuti_I18N::DEFAULT_LOCALE;
	}

	/**
	 * Resolves a taxonomy term by its stable-key slug, creating it (with the correct
	 * locale-appropriate label from Atlas_Chuti_Taxonomy_Labels) if it doesn't exist
	 * yet — used instead of wp_set_post_terms()'s name-based matching, which would
	 * otherwise create a NEW term for every distinct locale's text (item 14-18).
	 */
	private function resolve_or_create_term_by_key( $taxonomy, $key, $locale ) {
		$key = sanitize_title( $key );
		if ( '' === $key ) {
			return 0;
		}
		$term = get_term_by( 'slug', $key, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->term_id;
		}
		$label    = Atlas_Chuti_Taxonomy_Labels::label( $taxonomy, $key, $locale );
		$inserted = wp_insert_term( $label ?: $key, $taxonomy, array( 'slug' => $key ) );
		return is_wp_error( $inserted ) ? 0 : $inserted['term_id'];
	}

	/**
	 * Auto-creates a dictionary entry for an ingredient the recipe references but that
	 * doesn't exist yet — "variant A" of item 12 of this phase's brief: whenever a
	 * recipe row supplies BOTH ingredient_key and display_name, that's treated as
	 * enough to safely create the canonical entry, so a future AI-produced recipe can
	 * introduce a new ingredient without a separate manual step. Never creates a
	 * second canonical entry for a key that already exists in this locale — the
	 * lookup right before creating is the guard. Returns null (and writes nothing) in
	 * dry-run mode; the caller reports the "will be created" note separately.
	 */
	private function ensure_ingredient_exists( $ingredient_key, $display_name, $locale, $dry_run ) {
		$key  = sanitize_title( $ingredient_key );
		$name = trim( (string) $display_name );
		if ( '' === $key || '' === $name ) {
			return null;
		}
		$existing = Atlas_Chuti_I18N::find_ingredient_by_key( $key, $locale );
		if ( $existing ) {
			return $existing;
		}
		if ( $dry_run ) {
			return null;
		}
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'atlas_ingredient',
				'post_title'  => sanitize_text_field( $name ),
				'post_name'   => $key,
				'post_status' => 'publish',
				'meta_input'  => array(
					'atlas_ingredient_key' => $key,
					'atlas_locale'         => $locale,
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return null;
		}
		update_post_meta( $post_id, 'atlas_aliases', array() );
		update_post_meta( $post_id, 'atlas_default_unit', '' );
		return get_post( $post_id );
	}

	/**
	 * Lowercase, accent-stripped, whitespace-collapsed comparison key — used so
	 * "Spaghetti Carbonara" and "spaghetti   carbonara" are recognized as the same
	 * title when checking for duplicates (item 12).
	 */
	private function normalize_title( $title ) {
		$title = remove_accents( (string) $title );
		$title = mb_strtolower( trim( $title ) );
		return preg_replace( '/\s+/', ' ', $title );
	}

	/**
	 * Recipe duplicate detection, in the order item 12 asks for: (1) stable content
	 * key, (2) normalized title + main country, (3) original title + main country,
	 * (4) slug — every step scoped to $locale (item 2 of this phase's brief), so an
	 * English import can never match/overwrite the Czech version of the same recipe.
	 * Scoping (2)/(3) to the same country is the fix for "same dish name in a
	 * different country must not overwrite the wrong recipe".
	 */
	private function find_existing_recipe( $item, $slug, $stable_key, $primary_country_post_id, $locale ) {
		if ( $stable_key ) {
			$post = Atlas_Chuti_I18N::find_by_translation_group( 'atlas_recipe', $stable_key, $locale );
			if ( $post ) {
				return $post;
			}
		}

		if ( $primary_country_post_id ) {
			$country_term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $primary_country_post_id );
			if ( $country_term_id ) {
				$candidates = get_posts(
					array(
						'post_type'      => 'atlas_recipe',
						'posts_per_page' => -1,
						'post_status'    => array( 'publish', 'draft' ),
						'tax_query'      => array( array( 'taxonomy' => 'atlas_country_tax', 'field' => 'term_id', 'terms' => $country_term_id ) ),
						'meta_query'     => array( array( 'key' => 'atlas_locale', 'value' => $locale, 'compare' => '=' ) ),
					)
				);
				$normalized_title = $this->normalize_title( $item['title'] ?? '' );
				foreach ( $candidates as $candidate ) {
					if ( $normalized_title && $this->normalize_title( $candidate->post_title ) === $normalized_title ) {
						return $candidate;
					}
				}
				if ( ! empty( $item['original_title'] ) ) {
					$normalized_original = $this->normalize_title( $item['original_title'] );
					foreach ( $candidates as $candidate ) {
						$candidate_original = $this->normalize_title( get_post_meta( $candidate->ID, 'atlas_original_title', true ) );
						if ( $candidate_original && $candidate_original === $normalized_original ) {
							return $candidate;
						}
					}
				}
			}
		}

		return $this->find_existing( 'atlas_recipe', $slug, $locale );
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

	private function apply_i18n_meta( $post_id, $item ) {
		if ( ! empty( $item['locale'] ) ) {
			update_post_meta( $post_id, 'atlas_locale', sanitize_text_field( $item['locale'] ) );
		}
		if ( ! empty( $item['translation_group'] ) ) {
			update_post_meta( $post_id, 'atlas_translation_group', sanitize_title( $item['translation_group'] ) );
		}
		if ( ! empty( $item['translation_status'] ) && in_array( $item['translation_status'], self::VALID_TRANSLATION_STATUS, true ) ) {
			update_post_meta( $post_id, 'atlas_translation_status', $item['translation_status'] );
		}
	}

	private function stable_key_for( $post_type, $item, $slug ) {
		if ( 'atlas_country' === $post_type ) {
			return ! empty( $item['iso_code'] ) ? strtoupper( $item['iso_code'] ) : '';
		}
		if ( 'atlas_ingredient' === $post_type ) {
			return sanitize_title( ! empty( $item['ingredient_key'] ) ? $item['ingredient_key'] : $slug );
		}
		return sanitize_title( ! empty( $item['translation_group'] ) ? $item['translation_group'] : $slug );
	}

	/**
	 * Resolves a cross-reference purely from the database: stable key first (ISO
	 * code / translation_group / ingredient_key), slug as a convenience fallback —
	 * both scoped to $locale (item 2/3 of this phase's brief), so a reference inside
	 * a cs-CZ item always resolves to the cs-CZ variant of the target, and an en item
	 * to the en variant. Stateless on purpose — see the class docblock.
	 */
	private function resolve_reference( $post_type, $ref, $locale ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return 0;
		}

		if ( 'atlas_country' === $post_type ) {
			$post = Atlas_Chuti_I18N::find_country_by_iso( $ref, $locale );
		} elseif ( 'atlas_ingredient' === $post_type ) {
			$post = Atlas_Chuti_I18N::find_ingredient_by_key( sanitize_title( $ref ), $locale );
		} else {
			$post = Atlas_Chuti_I18N::find_by_translation_group( $post_type, sanitize_title( $ref ), $locale );
		}
		if ( $post ) {
			return $post->ID;
		}

		$post = get_page_by_path( sanitize_title( $ref ), OBJECT, $post_type );
		if ( $post && $locale === Atlas_Chuti_I18N::get_locale( $post->ID ) ) {
			return $post->ID;
		}
		return 0;
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

	private function import_ingredient( $item, $dry_run ) {
		$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$errors  = $this->validate_required( $item, array( 'title' ) );
		if ( $errors ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $errors ) );
		}

		$locale     = $this->resolve_item_locale( $item );
		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_ingredient', $item, $slug );
		$existing   = ( $stable_key ? Atlas_Chuti_I18N::find_ingredient_by_key( $stable_key, $locale ) : null ) ?: $this->find_existing( 'atlas_ingredient', $slug, $locale );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		// atlas_ingredient_key/atlas_locale go through `meta_input` so they're already
		// visible to class-ingredient-sync.php's save_post hook (priority 20, fires
		// before this method's own update_post_meta() calls below would otherwise
		// reach it) — the canonical atlas_ingredient_tax term is matched/created by
		// the correct key from the very first save, not a later re-save.
		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_ingredient',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => 'publish',
				'meta_input'  => array(
					'atlas_ingredient_key' => $stable_key ?: $slug,
					'atlas_locale'         => $locale,
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'atlas_aliases', Atlas_Chuti_Meta_Fields::sanitize( 'string_list', $item['aliases'] ?? array() ) );
		update_post_meta( $post_id, 'atlas_default_unit', sanitize_text_field( $item['default_unit'] ?? '' ) );
		$this->apply_i18n_meta( $post_id, $item );

		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Countries -------------------------------------------------------

	private function validate_country( $item ) {
		// item 15: a master-data-only country record (facts, no finished gastro
		// profile) must be importable, so `intro` is intentionally NOT required here.
		$errors = $this->validate_required( $item, array( 'title', 'iso_code', 'flag_emoji', 'continent' ) );

		if ( ! empty( $item['iso_code'] ) && ! preg_match( '/^[A-Za-z]{2,3}$/', $item['iso_code'] ) ) {
			$errors[] = __( 'iso_code (očekáván ISO 3166-1 kód, např. IT)', 'atlas-chuti' );
		}
		if ( ! empty( $item['continent'] ) && ! in_array( $item['continent'], self::VALID_CONTINENTS, true ) ) {
			// Stable keys now (item 14 of this phase's brief), not Czech names — e.g.
			// "europe", not "Evropa".
			$errors[] = sprintf( __( 'continent (%s)', 'atlas-chuti' ), implode( '/', self::VALID_CONTINENTS ) );
		}
		if ( isset( $item['status'] ) && ! in_array( $item['status'], self::VALID_STATUS, true ) ) {
			$errors[] = __( 'status (publish/draft)', 'atlas-chuti' );
		}
		if ( ! empty( $item['locale'] ) && ! $this->is_valid_locale( $item['locale'] ) ) {
			$errors[] = __( 'locale (očekáván BCP 47 tag, např. cs-CZ)', 'atlas-chuti' );
		}
		if ( isset( $item['population'] ) && is_numeric( $item['population'] ) && (int) $item['population'] < 0 ) {
			$errors[] = __( 'population (nesmí být záporné)', 'atlas-chuti' );
		}
		if ( isset( $item['area_km2'] ) && is_numeric( $item['area_km2'] ) && (int) $item['area_km2'] < 0 ) {
			$errors[] = __( 'area_km2 (nesmí být záporné)', 'atlas-chuti' );
		}
		return $errors;
	}

	private function is_valid_locale( $locale ) {
		return (bool) preg_match( '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', (string) $locale );
	}

	private function import_country( $item, $dry_run ) {
		$title  = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : ( $item['name_cs'] ?? '' );
		$errors = $this->validate_country( $item );
		if ( $errors ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $errors ) );
		}

		$locale     = $this->resolve_item_locale( $item );
		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_country', $item, $slug );
		// ISO code is this entity's real identity (item 14), scoped to $locale (item 2)
		// — what a re-import recognizes "this is the same country, same language" by.
		$existing = Atlas_Chuti_I18N::find_country_by_iso( $stable_key, $locale ) ?: $this->find_existing( 'atlas_country', $slug, $locale );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok' );
		}

		// Step 1: create/update the profile itself (title/slug/status), with iso_code
		// and locale set via `meta_input` — applied BEFORE save_post fires, so
		// class-country-sync.php's hook sees the real ISO code (and can find/create the
		// right canonical atlas_country_tax term by it) on this very first save,
		// instead of a stale/empty value from a hook-ordering race.
		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_country',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
				'meta_input'  => array(
					'atlas_iso_code' => strtoupper( $item['iso_code'] ),
					'atlas_locale'   => $locale,
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $this->row( $title, $this->label_error(), 'error', $post_id->get_error_message() );
		}

		// Step 2: assign the continent BEFORE anything reads it (this used to happen
		// after other saves and after the taxonomy sync hook had already fired once,
		// which is exactly the bug this fixes). Resolved by stable key (item 14), not
		// by Czech name — the SAME "europe" term serves every locale's Italy.
		$continent_term_id = $this->resolve_or_create_term_by_key( 'atlas_continent', $item['continent'], $locale );
		if ( $continent_term_id ) {
			wp_set_post_terms( $post_id, array( $continent_term_id ), 'atlas_continent', false );
		}

		// Step 3: save the rest of the metadata.
		$fields = Atlas_Chuti_Meta_Fields::country_fields();
		foreach ( $fields as $key => $field ) {
			if ( in_array( $field['type'], array( 'post_ref', 'post_ref_list' ), true ) ) {
				continue; // resolved in the second pass
			}
			if ( array_key_exists( $key, $item ) ) {
				$shape = $field['shape'] ?? array();
				$types = $field['types'] ?? array();
				update_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( $key ), Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $item[ $key ], $shape, $types ) );
			}
		}
		if ( isset( $item['featured_image_alt'] ) && has_post_thumbnail( $post_id ) ) {
			update_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', sanitize_text_field( $item['featured_image_alt'] ) );
		}
		$this->apply_i18n_meta( $post_id, $item );

		// Step 4: only now, with metadata final, (re-)run the country↔taxonomy sync and
		// propagate the continent to any recipe already tagged with this country. The
		// save_post hook (class-country-sync.php) already created/updated the
		// atlas_country_tax term as a side effect of step 1's wp_insert_post — this call
		// is what makes the explicit "sync happens last" sequencing real, and it's safe
		// to call any number of times.
		$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $post_id );
		if ( $term_id ) {
			Atlas_Chuti_Country_Sync::resync_recipes_for_country_term( $term_id );
		}

		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	// -- Glossary ----------------------------------------------------------

	private function import_glossary( $item, $dry_run ) {
		$title  = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$errors = $this->validate_required( $item, array( 'title', 'category', 'short_definition' ) );
		if ( isset( $item['status'] ) && ! in_array( $item['status'], self::VALID_STATUS, true ) ) {
			$errors[] = __( 'status (publish/draft)', 'atlas-chuti' );
		}
		if ( ! empty( $item['locale'] ) && ! $this->is_valid_locale( $item['locale'] ) ) {
			$errors[] = __( 'locale (očekáván BCP 47 tag, např. cs-CZ)', 'atlas-chuti' );
		}
		if ( ! empty( $item['category'] ) && ! in_array( $item['category'], self::VALID_GLOSSARY_CATEGORY, true ) ) {
			// Stable keys now (item 18) — e.g. "technique", not "Kuchařské techniky".
			$errors[] = sprintf( __( 'category (%s)', 'atlas-chuti' ), implode( '/', self::VALID_GLOSSARY_CATEGORY ) );
		}
		if ( $errors ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $errors ) );
		}

		$locale     = $this->resolve_item_locale( $item );
		$slug       = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key = $this->stable_key_for( 'atlas_glossary', $item, $slug );
		$existing   = ( $stable_key ? Atlas_Chuti_I18N::find_by_translation_group( 'atlas_glossary', $stable_key, $locale ) : null ) ?: $this->find_existing( 'atlas_glossary', $slug, $locale );

		if ( $dry_run ) {
			$warnings = $this->warnings_for_glossary_refs( $item, $locale );
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok', $warnings );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_glossary',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
				'meta_input'  => array(
					'atlas_locale'            => $locale,
					'atlas_translation_group' => ! empty( $item['translation_group'] ) ? sanitize_title( $item['translation_group'] ) : $slug,
				),
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
			$category_term_id = $this->resolve_or_create_term_by_key( 'atlas_glossary_category', $item['category'], $locale );
			if ( $category_term_id ) {
				wp_set_post_terms( $post_id, array( $category_term_id ), 'atlas_glossary_category', false );
			}
		}
		$this->apply_i18n_meta( $post_id, $item );

		return $this->row( $title, $this->status_done( $existing ), 'ok' );
	}

	private function warnings_for_glossary_refs( $item, $locale ) {
		$notes = array();
		if ( ! empty( $item['origin_country'] ) && ! $this->resolve_reference( 'atlas_country', $item['origin_country'], $locale ) ) {
			/* translators: %s: the unresolved origin_country reference from the JSON */
			$notes[] = sprintf( __( 'origin_country "%s" nenalezena (bude uložena bez vazby)', 'atlas-chuti' ), $item['origin_country'] );
		}
		return implode( '; ', $notes );
	}

	// -- Recipes -------------------------------------------------------------

	/**
	 * Item 10's checklist: required fields, value types, positive servings,
	 * non-negative times, at least one ingredient/step with a valid shape, and —
	 * critically — that the main country actually resolves, so a recipe can never
	 * silently finish pointing at nothing (item 10, last paragraph).
	 */
	private function validate_recipe( $item, $locale ) {
		$errors = $this->validate_required( $item, array( 'title', 'country', 'excerpt', 'servings_default', 'prep_minutes', 'ingredients', 'steps' ) );

		if ( isset( $item['servings_default'] ) && ( ! is_numeric( $item['servings_default'] ) || (int) $item['servings_default'] <= 0 ) ) {
			$errors[] = __( 'servings_default (musí být kladné číslo)', 'atlas-chuti' );
		}
		foreach ( array( 'prep_minutes', 'cook_minutes', 'total_minutes' ) as $time_field ) {
			if ( isset( $item[ $time_field ] ) && ( ! is_numeric( $item[ $time_field ] ) || (int) $item[ $time_field ] < 0 ) ) {
				/* translators: %s: field name, e.g. prep_minutes */
				$errors[] = sprintf( __( '%s (nesmí být záporné)', 'atlas-chuti' ), $time_field );
			}
		}
		if ( isset( $item['status'] ) && ! in_array( $item['status'], self::VALID_STATUS, true ) ) {
			$errors[] = __( 'status (publish/draft)', 'atlas-chuti' );
		}
		if ( ! empty( $item['difficulty'] ) && ! in_array( $item['difficulty'], self::VALID_DIFFICULTY, true ) ) {
			// Stable keys now (item 15) — e.g. "easy", not "Snadné".
			$errors[] = sprintf( __( 'difficulty (%s)', 'atlas-chuti' ), implode( '/', self::VALID_DIFFICULTY ) );
		}
		if ( ! empty( $item['locale'] ) && ! $this->is_valid_locale( $item['locale'] ) ) {
			$errors[] = __( 'locale (očekáván BCP 47 tag, např. cs-CZ)', 'atlas-chuti' );
		}

		if ( isset( $item['ingredients'] ) ) {
			if ( ! is_array( $item['ingredients'] ) || ! $item['ingredients'] ) {
				$errors[] = __( 'ingredients (alespoň jedna položka)', 'atlas-chuti' );
			} else {
				foreach ( $item['ingredients'] as $i => $row ) {
					if ( ! is_array( $row ) || empty( $row['display_name'] ) || ! isset( $row['quantity'] ) || '' === trim( (string) $row['quantity'] ) ) {
						/* translators: %d: zero-based row index in the ingredients array */
						$errors[] = sprintf( __( 'ingredients[%d] (chybí display_name nebo quantity)', 'atlas-chuti' ), $i );
					}
				}
			}
		}
		if ( isset( $item['steps'] ) ) {
			if ( ! is_array( $item['steps'] ) || ! $item['steps'] ) {
				$errors[] = __( 'steps (alespoň jeden krok)', 'atlas-chuti' );
			} else {
				foreach ( $item['steps'] as $i => $row ) {
					if ( ! is_array( $row ) || empty( $row['text'] ) || ! isset( $row['order'] ) || ! is_numeric( $row['order'] ) ) {
						/* translators: %d: zero-based row index in the steps array */
						$errors[] = sprintf( __( 'steps[%d] (chybí order nebo text)', 'atlas-chuti' ), $i );
					}
				}
			}
		}

		// The check item 10 calls out explicitly: an unresolvable main country must
		// never silently pass through — the recipe simply isn't created (see
		// import_recipe()) and this shows up as a hard error in both dry-run and the
		// live report, never as a quietly orphaned recipe.
		if ( ! empty( $item['country'] ) && ! $this->resolve_reference( 'atlas_country', $item['country'], $locale ) ) {
			/* translators: %s: the unresolved country reference from the JSON */
			$errors[] = sprintf( __( 'country "%s" neexistuje (nejdřív naimportujte danou zemi v tomto jazyce)', 'atlas-chuti' ), $item['country'] );
		}

		return $errors;
	}

	private function warnings_for_recipe_refs( $item, $locale ) {
		$notes = array();
		foreach ( array( 'related_recipes' => 'atlas_recipe', 'related_glossary' => 'atlas_glossary', 'related_countries' => 'atlas_country' ) as $field => $post_type ) {
			foreach ( (array) ( $item[ $field ] ?? array() ) as $ref ) {
				if ( ! $this->resolve_reference( $post_type, $ref, $locale ) ) {
					/* translators: %1$s: field name (e.g. related_recipes), %2$s: the unresolved reference value */
					$notes[] = sprintf( __( '%1$s "%2$s" nenalezen(a)', 'atlas-chuti' ), $field, $ref );
				}
			}
		}
		foreach ( (array) ( $item['ingredients'] ?? array() ) as $row ) {
			$unit = trim( (string) ( $row['unit'] ?? '' ) );
			if ( '' !== $unit && null === Atlas_Chuti_Units::normalize( $unit ) ) {
				/* translators: %s: the unrecognized unit text */
				$notes[] = sprintf( __( 'neznámá jednotka "%s" (bude uložena jako text)', 'atlas-chuti' ), $unit );
			}

			// item 11/12 of this phase's brief: ingredient_key is practically mandatory
			// for production content (search/multilingual linking depend on it); a
			// missing key is a warning, not a hard error, so hand-entered content can
			// still be saved. A key + display_name pair that isn't in the dictionary
			// yet will be auto-created (variant A) — flagged here so a dry-run shows it.
			$key  = trim( (string) ( $row['ingredient_key'] ?? '' ) );
			$name = trim( (string) ( $row['display_name'] ?? '' ) );
			if ( '' === $key ) {
				/* translators: %s: the ingredient's display_name */
				$notes[] = sprintf( __( 'ingredience "%s" nemá ingredient_key (vyhledávání podle ingredience a vícejazyčné propojení bude omezené)', 'atlas-chuti' ), $name ?: '?' );
			} elseif ( ! Atlas_Chuti_I18N::find_ingredient_by_key( sanitize_title( $key ), $locale ) ) {
				if ( $name ) {
					/* translators: %1$s: display_name, %2$s: ingredient_key */
					$notes[] = sprintf( __( 'ingredience "%1$s" (%2$s) ve slovníčku zatím neexistuje – bude automaticky vytvořena', 'atlas-chuti' ), $name, $key );
				} else {
					/* translators: %s: ingredient_key */
					$notes[] = sprintf( __( 'ingredient_key "%s" nemá display_name, nelze automaticky vytvořit slovníkový záznam', 'atlas-chuti' ), $key );
				}
			}
		}
		return implode( '; ', $notes );
	}

	private function import_recipe( $item, $dry_run ) {
		$title  = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$locale = $this->resolve_item_locale( $item );
		$errors = $this->validate_recipe( $item, $locale );
		if ( $errors ) {
			return $this->row( $title ?: $this->label_untitled(), $this->label_error(), 'error', $this->label_missing_fields( $errors ) );
		}

		$slug                  = isset( $item['slug'] ) ? sanitize_title( $item['slug'] ) : sanitize_title( $title );
		$stable_key            = $this->stable_key_for( 'atlas_recipe', $item, $slug );
		$primary_country_post  = $this->resolve_reference( 'atlas_country', $item['country'], $locale );
		$existing              = $this->find_existing_recipe( $item, $slug, $stable_key, $primary_country_post, $locale );
		$warnings              = $this->warnings_for_recipe_refs( $item, $locale );

		if ( $dry_run ) {
			return $this->row( $title, $this->status_would( $existing ), $existing ? 'skip' : 'ok', $warnings );
		}

		$post_id = wp_insert_post(
			array(
				'ID'          => $existing ? $existing->ID : 0,
				'post_type'   => 'atlas_recipe',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_status' => $item['status'] ?? 'publish',
				'meta_input'  => array(
					'atlas_locale'            => $locale,
					'atlas_translation_group' => $stable_key ?: $slug,
				),
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
				$types = $field['types'] ?? array();
				update_post_meta( $post_id, Atlas_Chuti_Meta_Fields::meta_key( $key ), Atlas_Chuti_Meta_Fields::sanitize( $field['type'], $item[ $key ], $shape, $types ) );
			}
		}

		// Taxonomy values resolve by stable key (item 15-17), not by Czech name — the
		// SAME "easy"/"main-course" term serves every locale's recipes. meal_type/diet
		// stay open vocabularies: any new key is simply created.
		if ( ! empty( $item['meal_type'] ) ) {
			$term_ids = array_filter( array_map( fn( $k ) => $this->resolve_or_create_term_by_key( 'atlas_meal_type', $k, $locale ), (array) $item['meal_type'] ) );
			wp_set_post_terms( $post_id, array_values( $term_ids ), 'atlas_meal_type', false );
		}
		if ( ! empty( $item['difficulty'] ) ) {
			$term_id = $this->resolve_or_create_term_by_key( 'atlas_difficulty', $item['difficulty'], $locale );
			if ( $term_id ) {
				wp_set_post_terms( $post_id, array( $term_id ), 'atlas_difficulty', false );
			}
		}
		if ( ! empty( $item['diet'] ) ) {
			$term_ids = array_filter( array_map( fn( $k ) => $this->resolve_or_create_term_by_key( 'atlas_diet', $k, $locale ), (array) $item['diet'] ) );
			wp_set_post_terms( $post_id, array_values( $term_ids ), 'atlas_diet', false );
		}
		$this->apply_i18n_meta( $post_id, $item );

		// Country tagging happens here (not only in the resolve pass) so a recipe is
		// never left without its country tag even for a single-item, non-batched save.
		if ( $primary_country_post ) {
			$term_id = Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $primary_country_post );
			if ( $term_id ) {
				wp_set_post_terms( $post_id, array( $term_id ), 'atlas_country_tax', false );
				update_post_meta( $post_id, '_atlas_recipe_primary_country_term_id', $term_id );
			}
		}

		// Auto-create any ingredient dictionary entries this recipe references but
		// that don't exist yet (item 12, "variant A") — before tagging, so the entry
		// just created is immediately resolvable by the tag_recipe() call below.
		foreach ( (array) $item['ingredients'] as $row ) {
			$key  = trim( (string) ( $row['ingredient_key'] ?? '' ) );
			$name = trim( (string) ( $row['display_name'] ?? '' ) );
			if ( $key && $name ) {
				$this->ensure_ingredient_exists( $key, $name, $locale, false );
			}
		}

		// Ingredient search index (item 17/21): tag this recipe with every ingredient
		// it contains that resolves to the normalized dictionary, in THIS recipe's locale.
		$sanitized_ingredients = Atlas_Chuti_Meta_Fields::sanitize( 'repeater', $item['ingredients'], $fields['ingredients']['shape'], $fields['ingredients']['types'] ?? array() );
		Atlas_Chuti_Ingredient_Sync::tag_recipe( $post_id, $sanitized_ingredients, $locale );

		return $this->row( $title, $this->status_done( $existing ), 'ok', $warnings );
	}

	// -- Pass 2: reference resolution ------------------------------------------

	private function resolve_country_refs( $item ) {
		$locale  = $this->resolve_item_locale( $item );
		$post    = Atlas_Chuti_I18N::find_country_by_iso( $item['iso_code'] ?? '', $locale ) ?: $this->find_existing( 'atlas_country', sanitize_title( $item['slug'] ?? $item['title'] ?? '' ), $locale );
		if ( ! $post ) {
			return;
		}
		$post_id = $post->ID;
		if ( ! empty( $item['related_countries'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $locale ), (array) $item['related_countries'] ) );
			update_post_meta( $post_id, 'atlas_related_countries', array_values( $ids ) );
		}
		if ( ! empty( $item['related_glossary'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_glossary', $s, $locale ), (array) $item['related_glossary'] ) );
			update_post_meta( $post_id, 'atlas_related_glossary', array_values( $ids ) );
		}
		if ( ! empty( $item['traditional_dishes'] ) ) {
			$dishes = get_post_meta( $post_id, 'atlas_traditional_dishes', true );
			if ( is_array( $dishes ) ) {
				foreach ( $dishes as $i => $dish ) {
					if ( ! empty( $dish['recipe_id'] ) ) {
						$dishes[ $i ]['recipe_id'] = (string) $this->resolve_reference( 'atlas_recipe', $dish['recipe_id'], $locale );
					}
				}
				update_post_meta( $post_id, 'atlas_traditional_dishes', $dishes );
			}
		}
	}

	private function resolve_glossary_refs( $item ) {
		$locale     = $this->resolve_item_locale( $item );
		$stable_key = $this->stable_key_for( 'atlas_glossary', $item, sanitize_title( $item['slug'] ?? $item['title'] ?? '' ) );
		$post       = ( $stable_key ? Atlas_Chuti_I18N::find_by_translation_group( 'atlas_glossary', $stable_key, $locale ) : null )
			?: $this->find_existing( 'atlas_glossary', sanitize_title( $item['slug'] ?? $item['title'] ?? '' ), $locale );
		if ( ! $post ) {
			return;
		}
		$post_id = $post->ID;

		if ( ! empty( $item['origin_country'] ) ) {
			update_post_meta( $post_id, 'atlas_origin_country_id', $this->resolve_reference( 'atlas_country', $item['origin_country'], $locale ) );
		}
		if ( ! empty( $item['related_recipes'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_recipe', $s, $locale ), (array) $item['related_recipes'] ) );
			update_post_meta( $post_id, 'atlas_related_recipes', array_values( $ids ) );
		}
		if ( ! empty( $item['related_countries'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $locale ), (array) $item['related_countries'] ) );
			update_post_meta( $post_id, 'atlas_related_countries', array_values( $ids ) );
			$terms = array_filter( array_map( fn( $id ) => Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $id ), $ids ) );
			if ( $terms ) {
				wp_set_post_terms( $post_id, $terms, 'atlas_country_tax', true );
			}
		}
	}

	private function resolve_recipe_refs( $item ) {
		$locale     = $this->resolve_item_locale( $item );
		$slug       = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
		$stable_key = $this->stable_key_for( 'atlas_recipe', $item, $slug );
		$post       = ( $stable_key ? Atlas_Chuti_I18N::find_by_translation_group( 'atlas_recipe', $stable_key, $locale ) : null ) ?: $this->find_existing( 'atlas_recipe', $slug, $locale );
		if ( ! $post ) {
			return;
		}
		$post_id = $post->ID;

		$related_country_posts = ! empty( $item['related_countries'] ) ? array_map( fn( $s ) => $this->resolve_reference( 'atlas_country', $s, $locale ), (array) $item['related_countries'] ) : array();
		$related_terms         = array_filter( array_map( fn( $id ) => Atlas_Chuti_Country_Sync::get_term_id_for_country_post( $id ), array_filter( $related_country_posts ) ) );
		if ( $related_terms ) {
			wp_set_post_terms( $post_id, $related_terms, 'atlas_country_tax', true );
		}

		if ( ! empty( $item['related_recipes'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_recipe', $s, $locale ), (array) $item['related_recipes'] ) );
			update_post_meta( $post_id, 'atlas_related_recipes', array_values( $ids ) );
		}
		if ( ! empty( $item['related_glossary'] ) ) {
			$ids = array_filter( array_map( fn( $s ) => $this->resolve_reference( 'atlas_glossary', $s, $locale ), (array) $item['related_glossary'] ) );
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

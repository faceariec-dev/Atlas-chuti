<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 9, item 37: "Atlas chutí → Obsahový audit" — a lightweight, read-only
 * heuristic for potentially ORPHAN public content (published posts nothing
 * else structurally links to). This never auto-links anything (item 37's own
 * explicit prohibition) — it only reports, an editor decides what (if
 * anything) to do about a flagged item.
 *
 * Heuristic, deliberately simple: a recipe/country/glossary entry counts as
 * "referenced" when it appears in another post's real relation field
 * (`atlas_related_recipes`/`atlas_related_glossary`/`atlas_related_countries`,
 * or a Magazine article's `atlas_related_recipe_keys`/
 * `atlas_related_country_iso`/`atlas_related_glossary_keys`), OR — for a
 * country — it has at least one published recipe tagged with its country
 * term (the taxonomy link every recipe already carries, not a separate
 * relation field). This is a SIGNAL, not a definitive orphan verdict: a
 * recipe only reachable via the archive/taxonomy grid is still perfectly
 * discoverable and may legitimately flag here — the point is to surface
 * candidates for an editor to look at, never to auto-fix.
 */
class Atlas_Chuti_Content_Audit {

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
	}

	public function add_menu() {
		add_submenu_page(
			'atlas-chuti-import',
			__( 'Obsahový audit', 'atlas-chuti' ),
			__( 'Obsahový audit', 'atlas-chuti' ),
			self::CAPABILITY,
			'atlas-chuti-content-audit',
			array( $this, 'render_page' )
		);
	}

	private function referenced_recipe_ids() {
		$ids = array();
		foreach ( get_posts( array( 'post_type' => 'atlas_recipe', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
			foreach ( (array) get_post_meta( $id, 'atlas_related_recipes', true ) as $rel_id ) {
				$ids[ (int) $rel_id ] = true;
			}
		}
		$recipe_key_to_id = array();
		foreach ( get_posts( array( 'post_type' => 'atlas_recipe', 'posts_per_page' => -1, 'post_status' => 'publish' ) ) as $post ) {
			$key = get_post_meta( $post->ID, 'atlas_recipe_key', true );
			if ( $key ) {
				$recipe_key_to_id[ $key ][] = $post->ID;
			}
		}
		foreach ( get_posts( array( 'post_type' => 'post', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
			foreach ( (array) get_post_meta( $id, 'atlas_related_recipe_keys', true ) as $key ) {
				foreach ( $recipe_key_to_id[ $key ] ?? array() as $rel_id ) {
					$ids[ $rel_id ] = true;
				}
			}
		}
		return $ids;
	}

	private function referenced_glossary_ids() {
		$ids = array();
		foreach ( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) as $post_type ) {
			foreach ( get_posts( array( 'post_type' => $post_type, 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
				foreach ( (array) get_post_meta( $id, 'atlas_related_glossary', true ) as $rel_id ) {
					$ids[ (int) $rel_id ] = true;
				}
			}
		}
		$group_to_id = array();
		foreach ( get_posts( array( 'post_type' => 'atlas_glossary', 'posts_per_page' => -1, 'post_status' => 'publish' ) ) as $post ) {
			$group = get_post_meta( $post->ID, 'atlas_translation_group', true );
			if ( $group ) {
				$group_to_id[ $group ][] = $post->ID;
			}
		}
		foreach ( get_posts( array( 'post_type' => 'post', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
			foreach ( (array) get_post_meta( $id, 'atlas_related_glossary_keys', true ) as $group ) {
				foreach ( $group_to_id[ $group ] ?? array() as $rel_id ) {
					$ids[ $rel_id ] = true;
				}
			}
		}
		return $ids;
	}

	private function referenced_country_ids() {
		$ids = array();
		foreach ( get_posts( array( 'post_type' => 'atlas_country', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
			foreach ( (array) get_post_meta( $id, 'atlas_related_countries', true ) as $rel_id ) {
				$ids[ (int) $rel_id ] = true;
			}
		}
		// A country with at least one published recipe is reachable via that
		// recipe's own country breadcrumb/link — a real pathway, not a relation
		// field, but just as valid a "not orphan" signal.
		foreach ( get_posts( array( 'post_type' => 'atlas_recipe', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $recipe_id ) {
			$country = function_exists( 'atlas_chuti_get_recipe_primary_country' ) ? atlas_chuti_get_recipe_primary_country( $recipe_id ) : null;
			if ( $country ) {
				$ids[ (int) $country->ID ] = true;
			}
		}
		$iso_to_id = array();
		foreach ( get_posts( array( 'post_type' => 'atlas_country', 'posts_per_page' => -1, 'post_status' => 'publish' ) ) as $post ) {
			$iso = get_post_meta( $post->ID, 'atlas_iso_code', true );
			if ( $iso ) {
				$iso_to_id[ $iso ][] = $post->ID;
			}
		}
		foreach ( get_posts( array( 'post_type' => 'post', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) ) as $id ) {
			foreach ( (array) get_post_meta( $id, 'atlas_related_country_iso', true ) as $iso ) {
				foreach ( $iso_to_id[ $iso ] ?? array() as $rel_id ) {
					$ids[ $rel_id ] = true;
				}
			}
		}
		return $ids;
	}

	private function flagged( $post_type, array $referenced_ids ) {
		$flagged = array();
		foreach ( get_posts( array( 'post_type' => $post_type, 'posts_per_page' => -1, 'post_status' => 'publish' ) ) as $post ) {
			if ( ! isset( $referenced_ids[ $post->ID ] ) ) {
				$flagged[] = $post;
			}
		}
		return $flagged;
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}

		$groups = array(
			'atlas_recipe'   => array( __( 'Recepty', 'atlas-chuti' ), $this->flagged( 'atlas_recipe', $this->referenced_recipe_ids() ) ),
			'atlas_country'  => array( __( 'Země', 'atlas-chuti' ), $this->flagged( 'atlas_country', $this->referenced_country_ids() ) ),
			'atlas_glossary' => array( __( 'Slovníček', 'atlas-chuti' ), $this->flagged( 'atlas_glossary', $this->referenced_glossary_ids() ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Obsahový audit', 'atlas-chuti' ); ?></h1>
			<p><?php esc_html_e( 'Heuristický přehled publikovaného obsahu, na který zatím neodkazuje žádný jiný recept/země/slovníček/magazínový článek přes strukturované relation pole. Toto NENÍ definitivní seznam „mrtvého“ obsahu — recept dostupný jen přes archiv/filtr je stále nalezitelný. Žádné automatické prolinkování se zde neprovádí.', 'atlas-chuti' ); ?></p>

			<?php foreach ( $groups as $label_group ) : list( $label, $items ) = $label_group; ?>
				<h2><?php echo esc_html( $label ); ?> (<?php echo count( $items ); ?>)</h2>
				<?php if ( ! $items ) : ?>
					<p><?php esc_html_e( 'Žádné položky.', 'atlas-chuti' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:760px;">
						<tbody>
						<?php foreach ( $items as $post ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
								<td><a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Zobrazit', 'atlas-chuti' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

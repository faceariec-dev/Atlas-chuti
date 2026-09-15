<?php
/**
 * Template Name: Co mám doma?
 * Description: Přiřaďte této šabloně stránku se slugem „co-mam-doma“ (viz Atlas chutí → Nastavení stránek).
 *
 * KROK 8, item 16-18/47: ingredient-KEY based recipe finder (never free text).
 * Base interface is a plain, fully accessible native `<select multiple>` —
 * works with no JS, full keyboard/screen-reader support for free (item 18/47's
 * own "safely simpler accessible select/search" allowance) — progressively
 * enhanced by assets/js/ingredient-finder.js into a searchable chip picker
 * when JS is available, always staying in sync with the same underlying
 * `<select>` so a form submit works identically either way.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$selected_raw = isset( $_GET['ingredience'] ) ? (array) wp_unslash( $_GET['ingredience'] ) : array();
$selected_keys = Atlas_Chuti_Ingredient_Finder::instance()->validate_keys( $selected_raw );
$all_ingredients = Atlas_Chuti_Ingredient_Finder::instance()->list_ingredients();
$matches = $selected_keys ? Atlas_Chuti_Ingredient_Finder::instance()->find_matches( $selected_keys ) : array();
?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker"><?php esc_html_e( 'Nástroj', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Co mám doma?', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;"><?php esc_html_e( 'Vyberte ingredience, které máte po ruce, a najdeme recepty, které je využijí.', 'atlas-chuti' ); ?></p>
</section>

<section class="container section" style="padding-top:var(--space-4);">
	<form method="get" action="<?php echo esc_url( atlas_chuti_system_url( 'what_do_i_have' ) ); ?>" data-ingredient-finder-form>
		<div class="ingredient-combobox" data-ingredient-combobox>
			<label for="ingredient-select"><?php esc_html_e( 'Ingredience, které mám', 'atlas-chuti' ); ?></label>
			<select id="ingredient-select" name="ingredience[]" multiple size="8" data-ingredient-select>
				<?php foreach ( $all_ingredients as $ing ) : ?>
					<option value="<?php echo esc_attr( $ing['key'] ); ?>" <?php selected( in_array( $ing['key'], $selected_keys, true ) ); ?>><?php echo esc_html( $ing['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<button type="submit" class="btn btn-accent" style="margin-top:var(--space-4);"><?php esc_html_e( 'Najít recepty', 'atlas-chuti' ); ?></button>
	</form>

	<div style="margin-top:var(--space-10);">
		<?php if ( ! $selected_keys ) : ?>
			<p class="atlas-tool-empty"><?php esc_html_e( 'Vyberte alespoň jednu ingredienci.', 'atlas-chuti' ); ?></p>
		<?php elseif ( ! $matches ) : ?>
			<p class="atlas-tool-empty"><?php esc_html_e( 'Pro vybrané ingredience jsme nenašli žádný recept.', 'atlas-chuti' ); ?></p>
		<?php else : ?>
			<div class="card-grid card-grid-3">
				<?php foreach ( $matches as $match ) : ?>
					<div>
						<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $match['recipe']->ID ) ); ?>
						<p class="ingredient-match-missing">
							<?php
							printf(
								/* translators: 1: number matched, 2: total ingredients */
								esc_html__( 'Máte %1$d z %2$d ingrediencí', 'atlas-chuti' ),
								(int) $match['matched_count'],
								(int) $match['total_count']
							);
							?>
							<?php if ( $match['missing_labels'] ) : ?>
								<br><?php esc_html_e( 'Chybí:', 'atlas-chuti' ); ?> <?php echo esc_html( implode( ', ', $match['missing_labels'] ) ); ?>
							<?php endif; ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>

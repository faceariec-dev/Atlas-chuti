<?php
/**
 * Template Name: Co dnes vařit?
 * Description: Přiřaďte této šabloně stránku se slugem „co-dnes-varit“ (viz Atlas chutí → Nastavení stránek).
 *
 * KROK 8, item 8-9/47: a real recommendation tool over PUBLISHED recipes only,
 * using the SAME filter query vars/options as the recipe archive
 * (atlas_chuti_get_recipe_filter_options()/atlas_chuti_radio_group(), see
 * archive-atlas_recipe.php) so this never invents a second filter vocabulary.
 * Works with a plain GET form + full server-side render first (no JS
 * required for the base experience, same philosophy as every other form in
 * this project) — "Překvapit mě" is just the same GET request with a
 * `prekvapit=1` flag, which re-seeds the deterministic pick so a re-submit
 * gives a different (but still real, still filtered) result.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$options  = atlas_chuti_get_recipe_filter_options();
$active   = atlas_chuti_active_filters();
$surprise = isset( $_GET['prekvapit'] );
$has_query = $active || $surprise;

$result = null;
if ( $has_query ) {
	$seed_salt = $surprise ? (string) wp_rand() : '';
	$result    = Atlas_Chuti_Recommendations::instance()->find( $active, null, $seed_salt );
}
?>

<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<span class="kicker"><?php esc_html_e( 'Nástroj', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Co dnes vařit?', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;"><?php esc_html_e( 'Vyberte, co máte na mysli, a najdeme vám skutečný recept z Atlasu.', 'atlas-chuti' ); ?></p>
</section>

<section class="container section" style="padding-top:var(--space-4);">
	<div class="archive-layout">
		<aside class="filter-panel">
			<form data-filter-form action="<?php echo esc_url( atlas_chuti_system_url( 'what_to_cook' ) ); ?>" method="get">
				<div class="filter-group">
					<h4><?php esc_html_e( 'Typ jídla', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'typ', $options['typ'], $active['typ'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Obtížnost', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'obtiznost', $options['obtiznost'], $active['obtiznost'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Vhodné pro', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'dieta', $options['dieta'], $active['dieta'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Země', 'atlas-chuti' ); ?></h4>
					<?php atlas_chuti_radio_group( 'zeme', $options['zeme'], $active['zeme'] ?? '' ); ?>
				</div>
				<div class="filter-group">
					<h4><?php esc_html_e( 'Čas přípravy', 'atlas-chuti' ); ?></h4>
					<?php
					$cas          = $active['cas'] ?? '';
					$time_buckets = array(
						''      => __( 'Vše', 'atlas-chuti' ),
						'do-30' => __( 'Do 30 min', 'atlas-chuti' ),
						'do-60' => __( 'Do 60 min', 'atlas-chuti' ),
						'do-90' => __( 'Do 90 min', 'atlas-chuti' ),
					);
					foreach ( $time_buckets as $val => $label ) {
						printf( '<label><input type="radio" name="cas" value="%1$s" %2$s> %3$s</label>', esc_attr( $val ), checked( $val, $cas, false ), esc_html( $label ) );
					}
					?>
				</div>
				<div class="filter-actions">
					<button type="submit" class="btn btn-accent"><?php esc_html_e( 'Najít recept', 'atlas-chuti' ); ?></button>
					<button type="submit" name="prekvapit" value="1" class="btn btn-outline"><?php esc_html_e( 'Překvapte mě', 'atlas-chuti' ); ?></button>
					<?php if ( $active ) : ?>
						<a class="btn btn-outline" href="<?php echo esc_url( atlas_chuti_system_url( 'what_to_cook' ) ); ?>"><?php esc_html_e( 'Zrušit filtry', 'atlas-chuti' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</aside>

		<div>
			<?php if ( ! $has_query ) : ?>
				<p class="atlas-tool-empty"><?php esc_html_e( 'Nastavte si filtry vlevo, nebo zkuste „Překvapte mě“.', 'atlas-chuti' ); ?></p>
			<?php elseif ( ! $result['primary'] ) : ?>
				<p class="atlas-tool-empty"><?php esc_html_e( 'Pro zvolené filtry jsme nenašli žádný recept. Zkuste je zjednodušit.', 'atlas-chuti' ); ?></p>
			<?php else : ?>
				<div class="card-grid card-grid-3">
					<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $result['primary']->ID ) ); ?>
				</div>
				<?php if ( $result['reason'] ) : ?>
					<p class="atlas-tool-result-reason"><?php echo esc_html( implode( ' · ', $result['reason'] ) ); ?></p>
				<?php endif; ?>

				<?php if ( $result['alternatives'] ) : ?>
					<h2 style="margin-top:var(--space-10);font-size:var(--fs-h3);"><?php esc_html_e( 'Nebo zkuste', 'atlas-chuti' ); ?></h2>
					<div class="card-grid card-grid-3">
						<?php foreach ( $result['alternatives'] as $alt ) : ?>
							<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $alt->ID ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php get_footer(); ?>

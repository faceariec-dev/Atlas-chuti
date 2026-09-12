<?php
/**
 * Template Name: Kulinářský pas
 * Description: Přiřaďte této šabloně stránku se slugem „kulinarsky-pas“.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="container" style="padding:var(--space-14) var(--gutter) 0;" data-passport-page>
	<span class="kicker" style="color:var(--color-ink);"><?php esc_html_e( 'Kulinářský pas', 'atlas-chuti' ); ?></span>
	<h1><?php esc_html_e( 'Můj kulinářský pas', 'atlas-chuti' ); ?></h1>
	<p class="lede" style="max-width:none;margin-bottom:var(--space-8);"><?php esc_html_e( 'Elegantní přehled vašich gastronomických objevů.', 'atlas-chuti' ); ?></p>

	<div class="passport-hero">
		<div style="flex-shrink:0;">
			<div class="passport-count" data-total-count>0<span> / 0</span></div>
			<div style="font-size:14px;color:var(--dark-text-soft);margin-top:4px;"><?php esc_html_e( 'zemí ochutnáno', 'atlas-chuti' ); ?></div>
		</div>
		<div class="passport-progress-track">
			<div class="passport-progress-fill" data-progress-fill style="width:0%;"></div>
		</div>
	</div>
</section>

<section class="container section">
	<h2><?php esc_html_e( 'Podle světadílů', 'atlas-chuti' ); ?></h2>
	<div style="display:flex;flex-direction:column;gap:var(--space-4);margin-top:var(--space-6);" data-continent-list></div>
</section>

<section class="container section" style="padding-top:0;">
	<h2><?php esc_html_e( 'Uvařené recepty', 'atlas-chuti' ); ?></h2>
	<div style="margin-top:var(--space-6);" data-cooked-list></div>
</section>

<section class="container passport-clear" style="padding-bottom:var(--space-24);">
	<button type="button" class="btn btn-outline" data-passport-clear><?php esc_html_e( 'Vymazat můj Kulinářský pas', 'atlas-chuti' ); ?></button>
</section>

<?php get_footer(); ?>

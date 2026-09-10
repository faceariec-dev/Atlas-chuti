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

<section class="container" style="padding:64px var(--gutter) 0;" data-passport-page>
	<h1><?php esc_html_e( 'Můj kulinářský pas', 'atlas-chuti' ); ?></h1>
	<p style="font-size:16px;color:var(--text-body);margin-bottom:32px;"><?php esc_html_e( 'Elegantní přehled vašich gastronomických objevů.', 'atlas-chuti' ); ?></p>

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

<section class="container section" style="padding-top:48px;">
	<h2><?php esc_html_e( 'Podle světadílů', 'atlas-chuti' ); ?></h2>
	<div style="display:flex;flex-direction:column;gap:14px;margin-top:24px;" data-continent-list></div>
</section>

<section class="container section">
	<h2><?php esc_html_e( 'Uvařené recepty', 'atlas-chuti' ); ?></h2>
	<div style="margin-top:24px;" data-cooked-list></div>
</section>

<section class="container passport-clear" style="padding-bottom:80px;">
	<button type="button" class="btn btn-outline" data-passport-clear><?php esc_html_e( 'Vymazat můj Kulinářský pas', 'atlas-chuti' ); ?></button>
</section>

<?php get_footer(); ?>

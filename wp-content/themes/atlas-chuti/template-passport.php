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
	<h1>Můj kulinářský pas</h1>
	<p style="font-size:16px;color:var(--text-body);margin-bottom:32px;">Elegantní přehled vašich gastronomických objevů.</p>

	<div class="passport-hero">
		<div style="flex-shrink:0;">
			<div class="passport-count" data-total-count>0<span> / 0</span></div>
			<div style="font-size:14px;color:var(--dark-text-soft);margin-top:4px;">zemí ochutnáno</div>
		</div>
		<div class="passport-progress-track">
			<div class="passport-progress-fill" data-progress-fill style="width:0%;"></div>
		</div>
	</div>
</section>

<section class="container section" style="padding-top:48px;">
	<h2>Podle světadílů</h2>
	<div style="display:flex;flex-direction:column;gap:14px;margin-top:24px;" data-continent-list></div>
</section>

<section class="container section">
	<h2>Uvařené recepty</h2>
	<div style="margin-top:24px;" data-cooked-list></div>
</section>

<section class="container passport-clear" style="padding-bottom:80px;">
	<button type="button" class="btn btn-outline" data-passport-clear>Vymazat můj Kulinářský pas</button>
</section>

<?php get_footer(); ?>

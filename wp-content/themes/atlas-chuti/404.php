<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="error-404">
	<p class="code">404</p>
	<h1>Tuto stránku jsme nenašli</h1>
	<p style="color:var(--text-body);max-width:480px;margin:0 auto 28px;">Možná byla přesunuta, nebo jste zadali adresu s překlepem. Zkuste vyhledávání, nebo se vraťte na hlavní stránku.</p>
	<form class="search-box" style="max-width:480px;margin:0 auto 24px;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="Hledat recept, zemi, jídlo nebo surovinu…">
	</form>
	<a class="btn btn-accent" href="<?php echo esc_url( home_url( '/' ) ); ?>">Zpět na hlavní stránku</a>
</section>

<?php get_footer(); ?>

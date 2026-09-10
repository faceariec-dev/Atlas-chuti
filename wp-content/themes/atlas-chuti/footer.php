<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
</main>

<footer class="site-footer">
	<div class="footer-inner">
		<div class="footer-brand">
			<div class="site-logo">
				<span class="dot"></span>
				<span class="name"><?php bloginfo( 'name' ); ?></span>
			</div>
			<p><?php esc_html_e( 'Ochutnejte svět. Objevujte tradiční jídla, recepty a kuchyně ze všech koutů planety.', 'atlas-chuti' ); ?></p>
		</div>

		<div class="footer-columns">
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Objevujte', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-discover',
					array(
						__( 'Země světa', 'atlas-chuti' )          => home_url( '/zeme/' ),
						__( 'Recepty', 'atlas-chuti' )             => get_post_type_archive_link( 'atlas_recipe' ),
						__( 'Kuchařský slovníček', 'atlas-chuti' ) => get_post_type_archive_link( 'atlas_glossary' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Nástroje', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-tools',
					array(
						__( 'Kulinářský pas', 'atlas-chuti' )   => home_url( '/kulinarsky-pas/' ),
						__( 'Kulinářské cesty', 'atlas-chuti' ) => null,
						__( 'Co mám doma?', 'atlas-chuti' )     => null,
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'O webu', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-about',
					array(
						__( 'O projektu', 'atlas-chuti' )              => home_url( '/o-projektu/' ),
						__( 'Jak vzniká obsah', 'atlas-chuti' )        => home_url( '/jak-vznika-obsah/' ),
						__( 'Redakční zásady a zdroje', 'atlas-chuti' ) => home_url( '/redakcni-zasady/' ),
						__( 'Kontakt', 'atlas-chuti' )                 => home_url( '/kontakt/' ),
						__( 'Inzerce / Spolupráce', 'atlas-chuti' )    => home_url( '/inzerce/' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Informace', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-legal',
					array(
						__( 'Ochrana osobních údajů', 'atlas-chuti' ) => home_url( '/ochrana-osobnich-udaju/' ),
						__( 'Cookies', 'atlas-chuti' )                 => home_url( '/cookies/' ),
						__( 'Podmínky používání', 'atlas-chuti' )      => home_url( '/podminky-pouzivani/' ),
					)
				);
				?>
			</div>
		</div>
	</div>

	<div class="footer-bottom">
		<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
		<span><?php esc_html_e( 'Ochutnejte svět.', 'atlas-chuti' ); ?></span>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

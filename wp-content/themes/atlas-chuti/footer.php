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
			<p>Ochutnejte svět. Objevujte tradiční jídla, recepty a kuchyně ze všech koutů planety.</p>
		</div>

		<div class="footer-columns">
			<div class="footer-col">
				<span class="footer-col-title">Objevujte</span>
				<?php
				atlas_chuti_footer_nav(
					'footer-discover',
					array(
						'Země světa'          => home_url( '/zeme/' ),
						'Recepty'             => get_post_type_archive_link( 'atlas_recipe' ),
						'Kuchařský slovníček' => get_post_type_archive_link( 'atlas_glossary' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title">Nástroje</span>
				<?php
				atlas_chuti_footer_nav(
					'footer-tools',
					array(
						'Kulinářský pas'   => home_url( '/kulinarsky-pas/' ),
						'Kulinářské cesty' => null,
						'Co mám doma?'     => null,
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title">O webu</span>
				<?php
				atlas_chuti_footer_nav(
					'footer-about',
					array(
						'O projektu'                  => home_url( '/o-projektu/' ),
						'Jak vzniká obsah'             => home_url( '/jak-vznika-obsah/' ),
						'Redakční zásady a zdroje'     => home_url( '/redakcni-zasady/' ),
						'Kontakt'                      => home_url( '/kontakt/' ),
						'Inzerce / Spolupráce'         => home_url( '/inzerce/' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title">Informace</span>
				<?php
				atlas_chuti_footer_nav(
					'footer-legal',
					array(
						'Ochrana osobních údajů' => home_url( '/ochrana-osobnich-udaju/' ),
						'Cookies'                 => home_url( '/cookies/' ),
						'Podmínky používání'      => home_url( '/podminky-pouzivani/' ),
					)
				);
				?>
			</div>
		</div>
	</div>

	<div class="footer-bottom">
		<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
		<span>Ochutnejte svět.</span>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

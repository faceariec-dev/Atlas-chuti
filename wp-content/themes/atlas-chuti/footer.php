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
						__( 'Země světa', 'atlas-chuti' )          => atlas_chuti_system_url( 'countries' ),
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
						__( 'Kulinářský pas', 'atlas-chuti' )   => atlas_chuti_system_url( 'passport' ),
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
						__( 'O projektu', 'atlas-chuti' )              => atlas_chuti_system_url( 'about' ),
						__( 'Jak vzniká obsah', 'atlas-chuti' )        => atlas_chuti_system_url( 'editorial_process' ),
						__( 'Redakční zásady a zdroje', 'atlas-chuti' ) => atlas_chuti_system_url( 'editorial_policy' ),
						__( 'Kontakt', 'atlas-chuti' )                 => atlas_chuti_system_url( 'contact' ),
						__( 'Inzerce / Spolupráce', 'atlas-chuti' )    => atlas_chuti_system_url( 'advertising' ),
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
						__( 'Ochrana osobních údajů', 'atlas-chuti' ) => atlas_chuti_system_url( 'privacy' ),
						__( 'Cookies', 'atlas-chuti' )                 => atlas_chuti_system_url( 'cookies' ),
						__( 'Podmínky používání', 'atlas-chuti' )      => atlas_chuti_system_url( 'terms' ),
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

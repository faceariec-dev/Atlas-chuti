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
				// KROK 6, item 24/29: real, always-reachable destinations (CPT
				// archives + the now-published /magazin/ page) — no if_ready() gate
				// needed here, these are never drafts.
				$tips_url = atlas_chuti_magazine_tips_tricks_url();
				atlas_chuti_footer_nav(
					'footer-discover',
					array(
						__( 'Recepty', 'atlas-chuti' )             => get_post_type_archive_link( 'atlas_recipe' ),
						__( 'Země světa', 'atlas-chuti' )          => atlas_chuti_system_url( 'countries' ),
						__( 'Magazín', 'atlas-chuti' )             => atlas_chuti_system_url( 'magazine' ),
						__( 'Tipy a triky', 'atlas-chuti' )        => $tips_url ?: null,
						__( 'Kuchařský slovníček', 'atlas-chuti' ) => get_post_type_archive_link( 'atlas_glossary' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Komunita', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-community',
					array(
						__( 'Můj Atlas', 'atlas-chuti' )        => atlas_chuti_system_url( 'account' ),
						__( 'Kulinářský pas', 'atlas-chuti' )   => atlas_chuti_system_url( 'passport' ),
						__( 'Diskuze', 'atlas-chuti' )          => atlas_chuti_discussion_url(),
						__( 'FAQ', 'atlas-chuti' )              => atlas_chuti_system_url_if_ready( 'faq' ),
						__( 'Nahlásit chybu', 'atlas-chuti' )   => atlas_chuti_system_url_if_ready( 'nahlasit_chybu' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'O Atlasu', 'atlas-chuti' ); ?></span>
				<?php
				// KROK 6, item 23/26: these ARE Pages, so they only become real links
				// once an editor actually publishes them — atlas_chuti_system_url_if_ready()
				// returns null until then, which atlas_chuti_footer_nav() already
				// renders as the existing "(brzy)" placeholder, never a draft/broken URL.
				atlas_chuti_footer_nav(
					'footer-about',
					array(
						__( 'O Atlasu chutí', 'atlas-chuti' )      => atlas_chuti_system_url_if_ready( 'about' ),
						__( 'Jak Atlas funguje', 'atlas-chuti' )   => atlas_chuti_system_url_if_ready( 'jak_atlas_funguje' ),
						__( 'Redakce a autoři', 'atlas-chuti' )    => atlas_chuti_system_url_if_ready( 'redakce_autori' ),
						__( 'Jak tvoříme recepty', 'atlas-chuti' ) => atlas_chuti_system_url_if_ready( 'editorial_process' ),
						__( 'Kontakt', 'atlas-chuti' )             => atlas_chuti_system_url_if_ready( 'contact' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Pro partnery', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-partners',
					array(
						__( 'Reklama a spolupráce', 'atlas-chuti' ) => atlas_chuti_system_url_if_ready( 'advertising' ),
						__( 'Pro média', 'atlas-chuti' )            => atlas_chuti_system_url_if_ready( 'pro_media' ),
					)
				);
				?>
			</div>
			<div class="footer-col">
				<span class="footer-col-title"><?php esc_html_e( 'Právní', 'atlas-chuti' ); ?></span>
				<?php
				atlas_chuti_footer_nav(
					'footer-legal',
					array(
						__( 'Podmínky používání', 'atlas-chuti' )     => atlas_chuti_system_url_if_ready( 'terms' ),
						__( 'Ochrana osobních údajů', 'atlas-chuti' ) => atlas_chuti_system_url_if_ready( 'privacy' ),
						__( 'Cookies', 'atlas-chuti' )                => atlas_chuti_system_url_if_ready( 'cookies' ),
						__( 'Pravidla komunity', 'atlas-chuti' )      => atlas_chuti_system_url_if_ready( 'pravidla_komunity' ),
						__( 'Autorská práva', 'atlas-chuti' )         => atlas_chuti_system_url_if_ready( 'autorska_prava' ),
						__( 'Nastavení cookies', 'atlas-chuti' )      => atlas_chuti_system_url_if_ready( 'nastaveni_cookies' ),
					)
				);
				?>
			</div>
		</div>
	</div>

	<?php if ( function_exists( 'atlas_chuti_render_ad_slot' ) ) : ?>
		<div class="container"><?php atlas_chuti_render_ad_slot( 'footer_leaderboard' ); ?></div>
	<?php endif; ?>

	<div class="footer-bottom">
		<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
		<span><?php esc_html_e( 'Ochutnejte svět.', 'atlas-chuti' ); ?></span>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

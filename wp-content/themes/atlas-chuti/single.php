<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * KROK 6, item 10: Magazín article detail — standard WordPress `post`, so this is
 * just `single.php` in WordPress's own template hierarchy (no custom rewrite, no
 * dedicated CPT template needed). Comments stay OFF here (item 33 — see
 * class-comments.php's own forced comments_open() filter for `post`); Diskuze is
 * the site's general community area instead.
 */
get_header();

while ( have_posts() ) :
	the_post();
	$post_id    = get_the_ID();
	$categories = get_the_category();
	$author_id  = (int) get_post_field( 'post_author', $post_id );

	$related_recipes  = atlas_chuti_magazine_related_recipes( $post_id );
	$related_countries = atlas_chuti_magazine_related_countries( $post_id );
	$related_glossary  = atlas_chuti_magazine_related_glossary( $post_id );
	?>

	<article <?php post_class( 'container-narrow' ); ?> style="padding:var(--space-14) var(--gutter) var(--space-5);">
		<?php if ( $categories ) : ?>
			<span class="kicker is-blue">
				<a href="<?php echo esc_url( get_category_link( $categories[0] ) ); ?>" style="color:inherit;"><?php echo esc_html( $categories[0]->name ); ?></a>
			</span>
		<?php else : ?>
			<span class="kicker is-blue"><?php esc_html_e( 'Magazín', 'atlas-chuti' ); ?></span>
		<?php endif; ?>

		<h1><?php the_title(); ?></h1>

		<?php $excerpt = get_the_excerpt(); ?>
		<?php if ( $excerpt ) : ?><p class="lede" style="max-width:none;"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>

		<div class="card-eyebrow" style="gap:14px;margin-top:var(--space-3);color:var(--color-muted);font-size:14px;">
			<?php if ( $author_id ) : ?>
				<span><?php echo esc_html( get_the_author_meta( 'display_name', $author_id ) ); ?></span>
			<?php endif; ?>
			<span><?php echo esc_html( get_the_date() ); ?></span>
			<?php if ( get_the_modified_date() !== get_the_date() ) : ?>
				<span><?php echo esc_html( sprintf( __( 'Aktualizováno %s', 'atlas-chuti' ), get_the_modified_date() ) ); ?></span>
			<?php endif; ?>
		</div>
	</article>

	<?php if ( has_post_thumbnail() ) : ?>
		<section class="container-narrow" style="padding:0 var(--gutter);">
			<div style="border-radius:var(--radius-lg);overflow:hidden;aspect-ratio:16/9;">
				<?php echo atlas_chuti_media( $post_id, 'atlas-hero', '', 'magazine' ); ?>
			</div>
		</section>
	<?php endif; ?>

	<section class="container-narrow entry-content" style="padding:var(--space-8) var(--gutter) 0;font-size:16px;line-height:1.7;color:var(--color-text);">
		<?php the_content(); ?>
	</section>

	<?php
	// KROK 7, item 13: after the article body, never before H1/perex/first
	// lines (item: "nevkládej do prvních pár řádků článku, nevkládej před
	// H1/perex") — respects heading/content flow, doesn't dominate over the
	// editorial content (Discover principle, item 27).
	?>
	<div class="container-narrow" style="padding:0 var(--gutter);">
		<?php atlas_chuti_render_ad_slot( 'magazine_in_content' ); ?>
	</div>

	<?php if ( $related_recipes || $related_countries || $related_glossary ) : ?>
		<section class="section bg-blue-tint" style="margin-top:var(--space-10);">
			<div class="container-narrow">
				<h2><?php esc_html_e( 'Související obsah', 'atlas-chuti' ); ?></h2>

				<?php if ( $related_recipes ) : ?>
					<h3 style="font-size:var(--fs-h3);margin-top:var(--space-6);"><?php esc_html_e( 'Související recepty', 'atlas-chuti' ); ?></h3>
					<div class="card-grid card-grid-3">
						<?php foreach ( $related_recipes as $r ) : ?>
							<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $related_countries ) : ?>
					<h3 style="font-size:var(--fs-h3);margin-top:var(--space-6);"><?php esc_html_e( 'Související země', 'atlas-chuti' ); ?></h3>
					<div class="flex-wrap-gap">
						<?php foreach ( $related_countries as $c ) : ?>
							<a class="chip" href="<?php echo esc_url( get_permalink( $c ) ); ?>"><span><?php echo esc_html( atlas_chuti_flag( $c->ID ) ); ?></span> <?php echo esc_html( get_the_title( $c ) ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $related_glossary ) : ?>
					<h3 style="font-size:var(--fs-h3);margin-top:var(--space-6);"><?php esc_html_e( 'Související pojmy', 'atlas-chuti' ); ?></h3>
					<div class="flex-wrap-gap">
						<?php foreach ( $related_glossary as $g ) : ?>
							<a class="chip" href="<?php echo esc_url( get_permalink( $g ) ); ?>"><?php echo esc_html( get_the_title( $g ) ); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>

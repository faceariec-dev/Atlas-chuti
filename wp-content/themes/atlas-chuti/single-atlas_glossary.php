<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

while ( have_posts() ) :
	the_post();
	$post_id  = get_the_ID();
	$category = get_the_terms( $post_id, 'atlas_glossary_category' );
	$origin   = (int) get_post_meta( $post_id, 'atlas_origin_country_id', true );

	$short      = get_post_meta( $post_id, 'atlas_short_definition', true );
	$detailed   = get_post_meta( $post_id, 'atlas_detailed', true );
	$taste      = get_post_meta( $post_id, 'atlas_taste', true );
	$usage      = get_post_meta( $post_id, 'atlas_usage', true );
	$substitute = get_post_meta( $post_id, 'atlas_substitute', true );
	$recipe_ids = get_post_meta( $post_id, 'atlas_related_recipes', true );
	$country_ids = get_post_meta( $post_id, 'atlas_related_countries', true );
	?>

	<section class="container-narrow" style="padding:64px var(--gutter) 24px;">
		<div class="card-eyebrow" style="gap:14px;margin-bottom:18px;">
			<?php if ( $category && ! is_wp_error( $category ) ) : ?>
				<span class="glossary-cat-badge"><?php echo esc_html( $category[0]->name ); ?></span>
			<?php endif; ?>
			<?php if ( $origin && 'publish' === get_post_status( $origin ) ) : ?>
				<span style="display:flex;align-items:center;gap:6px;">
					<span style="font-size:17px;"><?php echo esc_html( atlas_chuti_flag( $origin ) ); ?></span>
					<a href="<?php echo esc_url( get_permalink( $origin ) ); ?>" style="color:inherit;"><?php echo esc_html( get_the_title( $origin ) ); ?></a>
				</span>
			<?php endif; ?>
		</div>
		<h1><?php the_title(); ?></h1>
		<?php if ( $short ) : ?><p style="font-size:17px;color:var(--text-body);line-height:1.65;"><?php echo esc_html( $short ); ?></p><?php endif; ?>
	</section>

	<section class="container-narrow" style="padding:8px var(--gutter) 0;display:flex;flex-direction:column;gap:36px;">
		<?php if ( $detailed ) : ?><div><h2 style="font-size:24px;"><?php esc_html_e( 'Detailní vysvětlení', 'atlas-chuti' ); ?></h2><div><?php echo wp_kses_post( wpautop( $detailed ) ); ?></div></div><?php endif; ?>
		<?php if ( $taste ) : ?><div><h2 style="font-size:24px;"><?php esc_html_e( 'Jak chutná', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--text-soft);line-height:1.65;"><?php echo esc_html( $taste ); ?></p></div><?php endif; ?>
		<?php if ( $usage ) : ?><div><h2 style="font-size:24px;"><?php esc_html_e( 'Jak se používá', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--text-soft);line-height:1.65;"><?php echo esc_html( $usage ); ?></p></div><?php endif; ?>
		<?php if ( $substitute ) : ?><div><h2 style="font-size:24px;"><?php esc_html_e( 'Čím ho případně nahradit', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--text-soft);line-height:1.65;"><?php echo esc_html( $substitute ); ?></p></div><?php endif; ?>
		<?php if ( $country_ids ) : ?>
			<div>
				<h2 style="font-size:24px;"><?php esc_html_e( 'Používá se v', 'atlas-chuti' ); ?></h2>
				<div class="flex-wrap-gap">
					<?php foreach ( (array) $country_ids as $cid ) : if ( 'publish' !== get_post_status( $cid ) ) { continue; } ?>
						<a class="chip" href="<?php echo esc_url( get_permalink( $cid ) ); ?>"><span><?php echo esc_html( atlas_chuti_flag( $cid ) ); ?></span> <?php echo esc_html( get_the_title( $cid ) ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>
	</section>

	<?php
	$recipes = ! empty( $recipe_ids ) ? array_filter( array_map( 'get_post', (array) $recipe_ids ) ) : array();
	if ( $recipes ) :
		?>
		<section class="container section" style="padding-top:56px;">
			<h2><?php echo esc_html( sprintf( __( 'Recepty s pojmem „%s“', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
			<div class="card-grid card-grid-3">
				<?php foreach ( $recipes as $r ) : ?>
					<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>

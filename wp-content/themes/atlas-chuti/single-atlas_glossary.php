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

	<section class="container-narrow" style="padding:var(--space-14) var(--gutter) var(--space-5);">
		<span class="kicker is-blue"><?php esc_html_e( 'Kuchařský slovníček', 'atlas-chuti' ); ?></span>
		<div class="card-eyebrow" style="gap:14px;margin-bottom:var(--space-4);">
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
		<?php if ( $short ) : ?><p class="lede" style="max-width:none;"><?php echo esc_html( $short ); ?></p><?php endif; ?>
	</section>

	<section class="container-narrow" style="padding:var(--space-2) var(--gutter) 0;display:flex;flex-direction:column;gap:var(--space-10);">
		<?php if ( $detailed ) : ?><div><h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Detailní vysvětlení', 'atlas-chuti' ); ?></h2><div><?php echo wp_kses_post( wpautop( $detailed ) ); ?></div></div><?php endif; ?>
		<?php if ( $taste ) : ?><div><h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Jak chutná', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--color-text);line-height:1.65;"><?php echo esc_html( $taste ); ?></p></div><?php endif; ?>
		<?php if ( $usage ) : ?><div><h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Jak se používá', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--color-text);line-height:1.65;"><?php echo esc_html( $usage ); ?></p></div><?php endif; ?>
		<?php if ( $substitute ) : ?><div><h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Čím ho případně nahradit', 'atlas-chuti' ); ?></h2><p style="font-size:15px;color:var(--color-text);line-height:1.65;"><?php echo esc_html( $substitute ); ?></p></div><?php endif; ?>
		<?php if ( $country_ids ) : ?>
			<div>
				<h2 style="font-size:var(--fs-h3);"><?php esc_html_e( 'Používá se v', 'atlas-chuti' ); ?></h2>
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
		<section class="section bg-blue-tint" style="margin-top:var(--space-10);">
			<div class="container">
				<h2><?php echo esc_html( sprintf( __( 'Recepty s pojmem „%s“', 'atlas-chuti' ), get_the_title() ) ); ?></h2>
				<div class="card-grid card-grid-3">
					<?php foreach ( $recipes as $r ) : ?>
						<?php get_template_part( 'template-parts/recipe-card', null, array( 'post_id' => $r->ID ) ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

<?php endwhile; ?>

<?php get_footer(); ?>

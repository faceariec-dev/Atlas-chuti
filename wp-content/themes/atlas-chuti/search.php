<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$query   = get_search_query();
$results = Atlas_Chuti_Search::instance()->search( $query );
$labels  = array(
	'atlas_recipe'   => __( 'Recept', 'atlas-chuti' ),
	'atlas_country'  => __( 'Země', 'atlas-chuti' ),
	'atlas_glossary' => __( 'Slovníček', 'atlas-chuti' ),
	'post'           => __( 'Magazín', 'atlas-chuti' ),
	'atlas_topic'    => __( 'Diskuze', 'atlas-chuti' ),
);
$total = array_sum( array_map( 'count', $results ) );
?>

<section class="container-medium" style="padding:var(--space-14) var(--gutter) var(--space-5);">
	<h1><?php esc_html_e( 'Výsledky hledání', 'atlas-chuti' ); ?></h1>
	<p style="color:var(--color-text);font-size:17px;">
		<?php
		if ( $query ) {
			/* translators: %1$s: search query, %2$s: result count phrase, e.g. "3 výsledky" */
			printf( esc_html__( 'Pro dotaz „%1$s“ jsme našli %2$s.', 'atlas-chuti' ), esc_html( $query ), esc_html( atlas_chuti_czech_plural( $total, __( 'výsledek', 'atlas-chuti' ), __( 'výsledky', 'atlas-chuti' ), __( 'výsledků', 'atlas-chuti' ) ) ) );
		} else {
			esc_html_e( 'Zadejte, co hledáte.', 'atlas-chuti' );
		}
		?>
	</p>
	<form class="search-box" style="max-width:520px;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Hledat recept, zemi, jídlo nebo surovinu…', 'atlas-chuti' ); ?>" value="<?php echo esc_attr( $query ); ?>">
	</form>
</section>

<section class="container-medium section">
	<?php if ( $total > 0 ) : ?>
		<?php foreach ( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary', 'post', 'atlas_topic' ) as $type ) : ?>
			<?php foreach ( $results[ $type ] as $post ) : ?>
				<a class="result-row" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
					<span class="result-group-label"><?php echo esc_html( $labels[ $type ] ); ?></span>
					<h3><?php echo esc_html( get_the_title( $post ) ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ? $post->post_content : get_post_meta( $post->ID, 'atlas_excerpt', true ) . get_post_meta( $post->ID, 'atlas_intro', true ) . get_post_meta( $post->ID, 'atlas_short_definition', true ) ), 26 ) ); ?></p>
				</a>
			<?php endforeach; ?>
		<?php endforeach; ?>
	<?php elseif ( $query ) : ?>
		<p class="empty-state"><?php esc_html_e( 'Pro tento dotaz jsme nic nenašli. Zkuste jiné klíčové slovo.', 'atlas-chuti' ); ?></p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>

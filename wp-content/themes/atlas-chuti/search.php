<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();

$query   = get_search_query();
$results = Atlas_Chuti_Search::instance()->search( $query );
$labels  = array(
	'atlas_recipe'   => 'Recept',
	'atlas_country'  => 'Země',
	'atlas_glossary' => 'Slovníček',
);
$total = count( $results['atlas_recipe'] ) + count( $results['atlas_country'] ) + count( $results['atlas_glossary'] );
?>

<section class="container-medium" style="padding:56px var(--gutter) 24px;">
	<h1>Výsledky hledání</h1>
	<p style="color:var(--text-body);">
		<?php
		if ( $query ) {
			printf( 'Pro dotaz „%s“ jsme našli %s.', esc_html( $query ), esc_html( atlas_chuti_czech_plural( $total, 'výsledek', 'výsledky', 'výsledků' ) ) );
		} else {
			echo 'Zadejte, co hledáte.';
		}
		?>
	</p>
	<form class="search-box" style="max-width:520px;" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
		<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.5" y2="16.5"></line></svg>
		<input type="search" name="s" placeholder="Hledat recept, zemi, jídlo nebo surovinu…" value="<?php echo esc_attr( $query ); ?>">
	</form>
</section>

<section class="container-medium section">
	<?php if ( $total > 0 ) : ?>
		<?php foreach ( array( 'atlas_recipe', 'atlas_country', 'atlas_glossary' ) as $type ) : ?>
			<?php foreach ( $results[ $type ] as $post ) : ?>
				<a class="result-row" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
					<span class="result-group-label"><?php echo esc_html( $labels[ $type ] ); ?></span>
					<h3><?php echo esc_html( get_the_title( $post ) ); ?></h3>
					<p><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ? $post->post_content : get_post_meta( $post->ID, 'atlas_excerpt', true ) . get_post_meta( $post->ID, 'atlas_intro', true ) . get_post_meta( $post->ID, 'atlas_short_definition', true ) ), 26 ) ); ?></p>
				</a>
			<?php endforeach; ?>
		<?php endforeach; ?>
	<?php elseif ( $query ) : ?>
		<p class="empty-state">Pro tento dotaz jsme nic nenašli. Zkuste jiné klíčové slovo.</p>
	<?php endif; ?>
</section>

<?php get_footer(); ?>

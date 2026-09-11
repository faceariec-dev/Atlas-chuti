<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an editor pick a Media Library photo for each of the 6 continents (item 20 of
 * this phase's brief) — WordPress core has no built-in term image field, so this adds
 * one to the atlas_continent term add/edit screens, storing the attachment ID as term
 * meta. Rendering is the theme's job (see inc/continent-image.php); this class only
 * owns the taxonomy data, matching "Core plugin řeší taxonomie".
 */
class Atlas_Chuti_Continent_Image {

	const META_KEY = 'thumbnail_id';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'atlas_continent_add_form_fields', array( $this, 'render_add_field' ) );
		add_action( 'atlas_continent_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_atlas_continent', array( $this, 'save' ) );
		add_action( 'edited_atlas_continent', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		if ( 'term.php' !== $hook && 'edit-tags.php' !== $hook ) {
			return;
		}
		if ( ! isset( $_GET['taxonomy'] ) || 'atlas_continent' !== $_GET['taxonomy'] ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'atlas-continent-image', ATLAS_CHUTI_URL . 'admin/js/continent-image.js', array( 'jquery' ), ATLAS_CHUTI_VERSION, true );
		wp_localize_script(
			'atlas-continent-image',
			'AtlasContinentImageL10n',
			array(
				'title'  => __( 'Vyberte fotografii světadílu', 'atlas-chuti' ),
				'button' => __( 'Použít tuto fotografii', 'atlas-chuti' ),
				'choose' => __( 'Vybrat fotografii', 'atlas-chuti' ),
				'change' => __( 'Změnit fotografii', 'atlas-chuti' ),
				'remove' => __( 'Odebrat fotografii', 'atlas-chuti' ),
			)
		);
	}

	public function render_add_field() {
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Fotografie světadílu', 'atlas-chuti' ); ?></label>
			<div class="atlas-continent-image-field" data-attachment-id="">
				<div class="atlas-continent-image-preview"></div>
				<input type="hidden" name="atlas_continent_thumbnail_id" class="atlas-continent-image-input" value="">
				<p>
					<button type="button" class="button atlas-continent-image-choose"><?php esc_html_e( 'Vybrat fotografii', 'atlas-chuti' ); ?></button>
					<button type="button" class="button atlas-continent-image-remove" style="display:none;"><?php esc_html_e( 'Odebrat fotografii', 'atlas-chuti' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	public function render_edit_field( $term ) {
		$attachment_id = (int) get_term_meta( $term->term_id, self::META_KEY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Fotografie světadílu', 'atlas-chuti' ); ?></label></th>
			<td>
				<div class="atlas-continent-image-field" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
					<div class="atlas-continent-image-preview">
						<?php if ( $attachment_id ) : ?>
							<?php echo wp_get_attachment_image( $attachment_id, 'medium' ); ?>
						<?php endif; ?>
					</div>
					<input type="hidden" name="atlas_continent_thumbnail_id" class="atlas-continent-image-input" value="<?php echo esc_attr( $attachment_id ); ?>">
					<p>
						<button type="button" class="button atlas-continent-image-choose"><?php echo $attachment_id ? esc_html__( 'Změnit fotografii', 'atlas-chuti' ) : esc_html__( 'Vybrat fotografii', 'atlas-chuti' ); ?></button>
						<button type="button" class="button atlas-continent-image-remove" style="<?php echo $attachment_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Odebrat fotografii', 'atlas-chuti' ); ?></button>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	public function save( $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		if ( isset( $_POST['atlas_continent_thumbnail_id'] ) ) {
			$attachment_id = absint( $_POST['atlas_continent_thumbnail_id'] );
			if ( $attachment_id ) {
				update_term_meta( $term_id, self::META_KEY, $attachment_id );
			} else {
				delete_term_meta( $term_id, self::META_KEY );
			}
		}
	}
}

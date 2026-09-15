<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KROK 5, items 22-25: user-submitted recipe photos through a CONTROLLED upload
 * endpoint — the brief is explicit that a normal `subscriber` must never be granted
 * the blanket `upload_files` capability just to make this work (item 4/22). This
 * class never checks/grants that capability at all: it calls wp_handle_upload()/
 * wp_insert_attachment() directly itself (server-side, after its OWN validation),
 * bypassing the standard media-library capability gate entirely — the REST layer
 * (class-rest-api.php) is the only gate, and it only requires is_user_logged_in().
 *
 * Moderation status (pending/approved/rejected) lives in `atlas_recipe_photos`, NOT
 * on the attachment's own post_status — every public/own-user read in this class
 * filters by that status column explicitly, so a pending photo is never
 * accidentally reachable through a generic WP media query (there isn't one; this
 * plugin never exposes attachments through any listing that doesn't go through
 * this class).
 */
class Atlas_Chuti_Photos {

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	const MODERATE_CAPABILITY = 'moderate_comments'; // real WP capability editors/admins already hold.

	// 5 MB — generous enough for a phone photo, small enough not to be an abuse vector.
	const MAX_BYTES = 5 * 1024 * 1024;

	const ALLOWED_MIMES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'deleted_user', array( $this, 'delete_all_for_user' ) );
		add_action( 'admin_menu', array( $this, 'add_moderation_menu' ) );
		add_action( 'admin_post_atlas_moderate_photo', array( $this, 'handle_admin_moderate' ) );
		// No-JS fallback (item 27) — a real <form enctype="multipart/form-data">
		// posts here; only logged-in visitors ever see the upload form at all (item
		// 22), so only the priv hook is needed.
		add_action( 'admin_post_atlas_photo_upload', array( $this, 'handle_admin_post_upload' ) );
	}

	public function handle_admin_post_upload() {
		if ( ! is_user_logged_in() || ! isset( $_POST['atlas_photo_nonce'] ) || ! wp_verify_nonce( $_POST['atlas_photo_nonce'], 'atlas_photo_upload' ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$recipe_key  = isset( $_POST['recipe_key'] ) ? sanitize_title( wp_unslash( $_POST['recipe_key'] ) ) : '';
		$redirect_to = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : home_url( '/' );
		$status_flag = 'chyba';

		if ( ! empty( $_FILES['photo'] ) ) {
			$result = $this->upload( get_current_user_id(), $recipe_key, $_FILES['photo'] );
			if ( ! is_wp_error( $result ) ) {
				$status_flag = 'nahrano';
			}
		}

		$target = wp_validate_redirect( $redirect_to, home_url( '/' ) );
		wp_safe_redirect( add_query_arg( 'foto', $status_flag, $target ) . '#fotografie' );
		exit;
	}

	// ---------------------------------------------------------------------
	// Moderation admin screen (item 23: "administrátor/editor s odpovídající
	// capability ji může schválit" — moderate_comments is a real, already-existing
	// WP capability editors/admins hold; a plain subscriber never has it).
	// ---------------------------------------------------------------------

	public function add_moderation_menu() {
		add_submenu_page(
			'atlas-chuti-import',
			__( 'Fotografie ke schválení', 'atlas-chuti' ),
			__( 'Fotografie ke schválení', 'atlas-chuti' ),
			self::MODERATE_CAPABILITY,
			'atlas-chuti-photos',
			array( $this, 'render_moderation_page' )
		);
	}

	public function render_moderation_page() {
		if ( ! current_user_can( self::MODERATE_CAPABILITY ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'atlas-chuti' ) );
		}
		global $wpdb;
		$table  = Atlas_Chuti_DB::table_photos();
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at ASC", self::STATUS_PENDING ), ARRAY_A );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atlas chutí – Fotografie ke schválení', 'atlas-chuti' ); ?></h1>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'Žádné fotografie nečekají na schválení.', 'atlas-chuti' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px;">
					<thead><tr>
						<th><?php esc_html_e( 'Náhled', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Recept (recipe_key)', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Uživatel', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Nahráno', 'atlas-chuti' ); ?></th>
						<th><?php esc_html_e( 'Akce', 'atlas-chuti' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo wp_get_attachment_image( (int) $row['attachment_id'], array( 80, 80 ) ); ?></td>
							<td><code><?php echo esc_html( $row['recipe_key'] ); ?></code></td>
							<td><?php echo esc_html( get_the_author_meta( 'display_name', (int) $row['user_id'] ) ); ?></td>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td>
								<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=atlas_moderate_photo&id=' . (int) $row['id'] . '&decision=approve' ), 'atlas_moderate_photo_' . $row['id'] ) ); ?>"><?php esc_html_e( 'Schválit', 'atlas-chuti' ); ?></a>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=atlas_moderate_photo&id=' . (int) $row['id'] . '&decision=reject' ), 'atlas_moderate_photo_' . $row['id'] ) ); ?>"><?php esc_html_e( 'Zamítnout', 'atlas-chuti' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_admin_moderate() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! current_user_can( self::MODERATE_CAPABILITY ) || ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'atlas_moderate_photo_' . $id ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		$decision = isset( $_GET['decision'] ) ? sanitize_key( $_GET['decision'] ) : '';
		if ( 'approve' === $decision ) {
			$this->approve( $id, get_current_user_id() );
		} elseif ( 'reject' === $decision ) {
			$this->reject( $id, get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=atlas-chuti-photos' ) );
		exit;
	}

	/**
	 * $file is one entry of $_FILES (already isset-checked by the caller). Returns
	 * the new photo row ID, or a WP_Error naming exactly what failed (item 42:
	 * "invalid MIME"/"too large"/"unsupported image type" are all distinct,
	 * user-facing-safe error codes — never a raw PHP/SQL error).
	 */
	public function upload( $user_id, $recipe_key, $file ) {
		if ( ! $user_id || '' === (string) $recipe_key ) {
			return new WP_Error( 'atlas_photo_invalid', __( 'Neplatný požadavek.', 'atlas-chuti' ) );
		}
		if ( ! $this->recipe_key_exists( $recipe_key ) ) {
			return new WP_Error( 'atlas_photo_unknown_recipe', __( 'Recept nebyl nalezen.', 'atlas-chuti' ) );
		}
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'atlas_photo_no_file', __( 'Soubor se nepodařilo přijmout.', 'atlas-chuti' ) );
		}
		// Whether $file['tmp_name'] genuinely came from an HTTP upload is checked
		// once, by wp_handle_upload() itself below (its own real is_uploaded_file()
		// check, part of its documented behavior) — checking it again here would
		// only duplicate that, not add any real safety.
		if ( ! empty( $file['size'] ) && $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'atlas_photo_too_large', __( 'Fotografie je příliš velká (max. 5 MB).', 'atlas-chuti' ) );
		}

		// Never trust the client's declared MIME/extension (item 22: "Nevěř pouze
		// HTML accept=") — getimagesize() actually decodes the file header, so a
		// renamed .php-as-.jpg or a corrupt file is rejected here, not just by name.
		$real = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- deliberately silenced, false return is the expected/handled failure path for a non-image file.
		if ( ! $real || empty( $real['mime'] ) || ! in_array( $real['mime'], self::ALLOWED_MIMES, true ) ) {
			return new WP_Error( 'atlas_photo_bad_type', __( 'Nepodporovaný typ obrázku (povoleno: JPEG, PNG, WebP).', 'atlas-chuti' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => self::ALLOWED_MIMES,
		);
		$moved = wp_handle_upload( $file, $overrides );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'atlas_photo_upload_failed', $moved['error'] );
		}

		// No post_parent (never attached to the recipe post itself — this plugin's
		// own moderation table is the only place that relates a photo to a
		// recipe_key, see the class docblock) and post_status 'private' as a second,
		// belt-and-suspenders layer on top of that (never publicly listed by WP's
		// own default queries even if some future code forgets to filter).
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( basename( $moved['file'] ) ),
				'post_status'    => 'private',
				'post_author'    => $user_id,
			),
			$moved['file']
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );

		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		$now   = current_time( 'mysql', true );
		$wpdb->insert(
			$table,
			array(
				'user_id'       => $user_id,
				'recipe_key'    => $recipe_key,
				'attachment_id' => $attachment_id,
				'status'        => self::STATUS_PENDING,
				'caption'       => '',
				'created_at'    => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	private function recipe_key_exists( $recipe_key ) {
		foreach ( Atlas_Chuti_I18N::SUPPORTED_LOCALES as $locale ) {
			if ( Atlas_Chuti_I18N::find_by_recipe_key( $recipe_key, $locale ) ) {
				return true;
			}
		}
		return false;
	}

	public function approve( $photo_id, $moderator_id ) {
		return $this->set_status( $photo_id, self::STATUS_APPROVED, $moderator_id );
	}

	public function reject( $photo_id, $moderator_id ) {
		return $this->set_status( $photo_id, self::STATUS_REJECTED, $moderator_id );
	}

	private function set_status( $photo_id, $status, $moderator_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		return (bool) $wpdb->update(
			$table,
			array(
				'status'       => $status,
				'moderated_at' => current_time( 'mysql', true ),
				'moderated_by' => $moderator_id,
			),
			array( 'id' => (int) $photo_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * A user may delete their OWN photo regardless of its moderation status
	 * (item 25) — never someone else's, enforced here by requiring the row's
	 * user_id to match, not just by the REST layer's login check.
	 */
	public function delete_own( $photo_id, $user_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND user_id = %d", $photo_id, $user_id ), ARRAY_A );
		if ( ! $row ) {
			return false;
		}
		wp_delete_attachment( (int) $row['attachment_id'], true );
		$wpdb->delete( $table, array( 'id' => $photo_id ), array( '%d' ) );
		return true;
	}

	/**
	 * Approved photos are concept-level UGC (item 23): reachable by recipe_key, so
	 * the SAME photo shows under both the CZ and EN post of "the same" recipe.
	 * Never the original full-size file (item 24: EXIF/metadata risk on the
	 * as-uploaded original) — only a generated intermediate size, see the report's
	 * documented limitation on this point.
	 */
	public function get_approved_for_recipe_key( $recipe_key, $limit = 12 ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, attachment_id, caption, created_at FROM {$table} WHERE recipe_key = %s AND status = %s ORDER BY created_at DESC LIMIT %d",
				$recipe_key,
				self::STATUS_APPROVED,
				$limit
			),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	public function get_user_photos( $user_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	/**
	 * Adds a safe, public-facing display URL + display name (item 24: never email,
	 * login, internal user ID, or IP) and never the original attachment file.
	 */
	private function hydrate_row( $row ) {
		$size = wp_get_attachment_image_src( (int) $row['attachment_id'], 'atlas-ugc' );
		if ( ! $size ) {
			$size = wp_get_attachment_image_src( (int) $row['attachment_id'], 'medium' );
		}
		$row['image_url']   = $size ? $size[0] : '';
		$row['display_name'] = get_the_author_meta( 'display_name', (int) $row['user_id'] );
		return $row;
	}

	public function delete_all_for_user( $user_id ) {
		global $wpdb;
		$table = Atlas_Chuti_DB::table_photos();
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$table} WHERE user_id = %d", $user_id ) );
		foreach ( $rows as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}
		$wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
	}
}

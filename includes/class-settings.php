<?php

namespace STN\VideoMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Settings > Video admin page and manages STN credentials.
 */
final class Settings {
	/**
	 * Option key for stored credentials.
	 */
	const OPTION_NAME = 'stn_video_settings';

	/**
	 * Menu page slug.
	 */
	const MENU_SLUG = 'video-settings';

	/**
	 * Nonce action for settings form.
	 */
	const NONCE_ACTION = 'stn_video_settings_nonce';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this, 'handle_settings_save' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( STNVM_MAIN_FILE ), [ $this, 'add_plugin_action_links' ] );
	}

	/**
	 * Register the Settings > Video page.
	 */
	public function register_settings_page(): void {
		add_options_page(
			__( 'Video Settings', 'stn-video-meta' ),
			__( 'Video', 'stn-video-meta' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$settings = self::get_settings();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param for admin notice only.
		$notice = isset( $_GET['stn-updated'] ) && 'true' === $_GET['stn-updated'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video Settings', 'stn-video-meta' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Video settings saved.', 'stn-video-meta' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'STN Video Credentials', 'stn-video-meta' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, 'stn_video_settings_nonce_field' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="stn_cid"><?php esc_html_e( 'Company ID (CID)', 'stn-video-meta' ); ?></label>
							</th>
							<td>
								<input type="text"
										id="stn_cid"
										name="stn_cid"
										value="<?php echo esc_attr( $settings['cid'] ); ?>"
										class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="stn_authcode"><?php esc_html_e( 'Auth Code', 'stn-video-meta' ); ?></label>
							</th>
							<td>
								<input type="text"
										id="stn_authcode"
										name="stn_authcode"
										value="<?php echo esc_attr( $settings['authcode'] ); ?>"
										class="regular-text" />
							</td>
						</tr>
					</tbody>
				</table>

				<?php
				/**
				 * Hook for other plugins to render additional settings sections.
				 *
				 * Used by stn-video-performance to render its performance options.
				 */
				do_action( 'stn_video_settings_sections' );

				submit_button( __( 'Save Settings', 'stn-video-meta' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the settings form submission.
	 */
	public function handle_settings_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page context only.
		if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_POST['stn_video_settings_nonce_field'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stn_video_settings_nonce_field'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stn-video-meta' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'stn-video-meta' ) );
		}

		$cid      = isset( $_POST['stn_cid'] ) ? sanitize_text_field( wp_unslash( $_POST['stn_cid'] ) ) : '';
		$authcode = isset( $_POST['stn_authcode'] ) ? sanitize_text_field( wp_unslash( $_POST['stn_authcode'] ) ) : '';

		update_option(
			self::OPTION_NAME,
			[
				'cid'      => $cid,
				'authcode' => $authcode,
			]
		);

		/**
		 * Fires after the main video settings are saved, before redirect.
		 *
		 * Allows other plugins (e.g. stn-video-performance) to save their
		 * fields from the shared form.
		 */
		do_action( 'stn_video_settings_saved' );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'        => self::MENU_SLUG,
					'stn-updated' => 'true',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Add settings link to the plugin action links.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified links.
	 */
	public function add_plugin_action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'stn-video-meta' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get the stored settings.
	 *
	 * @return array{cid: string, authcode: string}
	 */
	public static function get_settings(): array {
		$defaults = [
			'cid'      => '',
			'authcode' => '',
		];

		$settings = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		return wp_parse_args( $settings, $defaults );
	}
}

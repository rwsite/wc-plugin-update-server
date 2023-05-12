<?php
/**
 * main Plugin class
 */

final class LicPlugin {

	private static $instance;

	private function __construct() {
		$this->file = WCPUS;
		$this->get_plugin_data();
		$this->includes();
		$this->load_textdomain();
	}

	/**
	 * Setup plugin data
	 */
	public function get_plugin_data() {
		$this->plugin_data = get_file_data( $this->file, [
			'version'     => 'Version',
			'author'      => 'Author',
			'name'        => 'Plugin Name',
			'locale'      => 'Text Domain',
			'description' => 'Description',
			'plugin_url'  => 'Plugin URI',
		] );
		$this->version     = $this->plugin_data["version"];
		$this->key         = $this->plugin_data["locale"];
		$this->locale      = $this->plugin_data["locale"];
		$this->name        = $this->plugin_data["name"];

		return $this->plugin_data;
	}

	/**
	 * Include necessary files
	 */
	private function includes() {
		// Get out if WC is not active
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WPPUS_License_Server' ) ) {
			add_action( 'admin_notices', function () { ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php echo __( 'Woocommerce or WP Plugin Update Server is not activated. To work this plugin, you need to install and activate WooCommerce and WPPUS_License_Server plugins.',
                        'wc-pus' ); ?>
                </div>
				<?php
			} );
			return;
		}

		if ( is_admin() ) {
			// new Software_Licence_Manager_integration();
		}

		( new LicOrderMetaBox() )->add_actions();
		( new LicProduct() )->add_actions();
		( new LicOrder() )->add_actions();
	}

	/**
	 * Internationalization
	 */
	public function load_textdomain() {
		// Load the default language files
		load_plugin_textdomain( 'wc-pus', false, dirname( plugin_basename( $this->file ) ) . '/languages/' );
	}

	/**
	 * @return LicPlugin
	 */
	public static function getInstance() {
		if ( static::$instance === null ) {
			static::$instance = new self();
		}

		return static::$instance;
	}

	public static function activation() {
		// nothing
	}

	public static function uninstall() {
		// nothing
	}
}
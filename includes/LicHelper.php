<?php
/**
 * Get plugin info by slug for showing in any site part.
 *
 * How to use: [plugin_info slug="plugin_slug"]
 */


class LicHelper {

	/**
	 * Shortcode for showing plugin info to product page or somewhere...
	 */
	public function add_actions(){
		add_shortcode('plugin_info', [$this, 'render_html']);
	}

	public function render_html($atts){

		$atts = shortcode_atts( [
			'slug' => '',
			'data' => 'change_log'
		], $atts, 'plugin_info' );

		ob_start();
		$info = self::get_package($atts['slug']);
		echo $info[$atts['data']] ?? '<pre> - </pre>';
		return ob_get_clean();
	}


	/**
	 * Getting all package or package info by slug. Data gonna from file cache and will be db cache for 3 hours.
	 *
	 * @param string|null $slug package slug
	 * @return array|null
	 */
	public static function get_package( string $slug = null): ?array {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return null;
		}

		if($slug) {
			$packages = get_site_transient( 'wc_pus_' . $slug );
			if ( false !== $packages ) {
				return $packages;
			}
		}

		$package_directory = WPPUS_Data_Manager::get_data_dir( 'packages' );
		$packages          = [];
		if ( $wp_filesystem->is_dir( $package_directory ) ) {

			$package_paths = glob( trailingslashit( $package_directory ) . '*.zip' );
			if ( ! empty( $package_paths ) ) {
				foreach ( $package_paths as $package_path ) {
					$package = self::get_package_by_path( $package_path );
					$meta    = $package->getMetadata();
					$size = (float) ( $package->getFileSize() / WPPUS_MB_TO_B );

					$packages[ $meta['slug'] ] = [
						'name'               => $meta['name'],
						'version'            => $meta['version'],
						'type'               => isset( $meta['details_url'] ) ? __( 'Theme', 'wppus' ) : __( 'Plugin', 'wppus' ),
						'last_updated'       => $meta['last_updated'],
						'file_name'          => $meta['slug'] . '.zip',
						'file_path'          => $package_path,
						'file_size'          => number_format( $size, 2, '.', '' ) . ' MB',
						'file_last_modified' => $package->getLastModified(),

						'slug'               => $meta['slug'],
						'tested'             => $meta['tested'] ?? '',
						'change_log'         => $meta['sections']['changelog'] ?? '',
						'description'        => $meta['sections']['description'] ?? '',
					];

					set_site_transient('wc_pus_' . $meta['slug'], $packages[ $meta['slug'] ], 60*60*3);
				}
			}
		}

		if(!empty($slug) && key_exists($slug, $packages)){
			return $packages[$slug];
		}

		return !empty($packages) ? $packages  : null;
	}

	public static function get_package_by_path( $path ) {
		return Wpup_Package::fromArchive( $path, null, new Wpup_FileCache( WPPUS_Data_Manager::get_data_dir( 'cache' ) ) );
	}

    /**
     * Get type
     * @return array
     */
    public static function get_types($package_type=null){
        $types = [
            'plugin' => esc_html__('Plugin', 'wc-pus'),
            'theme'  => esc_html__('Theme', 'wc-pus'),
        ];
        return !empty($package_type) ? $types[$package_type] : $package_type;
    }
}
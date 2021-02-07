<?php
/**
 * Plugin Name:     WC Plugin Update Server integration
 * Plugin URI:      http://rwsite.ru
 * Description:
 * Version:         1.0.1
 * Author:          Aleksey Tikhomirov
 * Author URI:      http://rwsite.ru
 * Text Domain:     wc-pus
 * Domain Path:     /languages
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License version 3, as published by the Free Software Foundation. You may NOT assume
 * that you can use any other version of the GPL.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @author          Aleksey <support@rwsite.ru>
 * @copyright       Copyright (c) Aleksey
 * @license         http://www.gnu.org/licenses/gpl-3.0.html
 *
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Lic_Manager')):
class Lic_Manager_Plugin {

    private static $instance;

    /**
     * @return Lic_Manager
     */
    public static function getInstance()
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    private function __construct()
    {
        $this->setup_constants();
        $this->includes();
        $this->load_textdomain();
    }

    /**
     * Setup plugin constants
     */
    private function setup_constants() {
        define('LIC_VER', '1.0.0');
        define('LIC_DIR', plugin_dir_path(__FILE__));
        define('LIC_URL', plugin_dir_url(__FILE__));
    }

    /**
     * Include necessary files
     */
    private function includes() {
        // Get out if WC is not active
        if (!function_exists('WC') || !class_exists('WPPUS_License_Server')) {
            add_action( 'admin_notices', function(){ ?>
                <div class="notice notice-error is-dismissible"><p>
                        <?php echo __('Woocommerce or WPPUS_License_Server is not activated. To work this plugin, you need to install and activate WooCommerce and WPPUS_License_Server plugins.', 'wc-pus'); ?>
                </div>
            <?php });
            return;
        }

        require_once __DIR__ .  '/includes/Lic_Settings.php';
        require_once __DIR__ . '/includes/Lic_Manager.php';
        require_once __DIR__ . '/includes/Software_Licence_Manager_integration.php';
        require_once __DIR__ . '/includes/Lic_Admin.php';

        if (is_admin()) {
            new Software_Licence_Manager_integration();
        }

        new Lic_Admin();

        new Lic_Settings();
        new Lic_Manager();

    }

    /**
     * Internationalization
     */
    public function load_textdomain() {
        // Load the default language files
        load_plugin_textdomain('wc-pus', false, 'wc-lic-server-integration/languages');
        __('WC Software License Manager', 'wc-pus');
    }

    public static function activation() {
        // nothing
    }
    public static function uninstall() {
        // nothing
    }
}

register_activation_hook(__FILE__, ['Lic_Manager_Plugin', 'activation']);
register_uninstall_hook(__FILE__,  ['Lic_Manager_Plugin', 'uninstall']);

add_action('plugins_loaded', ['Lic_Manager_Plugin','getInstance']);
endif;
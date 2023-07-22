<?php
/**
 * Plugin Name:     WooCommerce and Plugin Update Server integration
 * Plugin URI:      http://rwsite.ru
 * Description:
 * Version:         1.0.6
 * Author:          Aleksei Tikhomirov
 * Author URI:      http://rwsite.ru
 * Text Domain:     wc-pus
 * Domain Path:     /languages
 *
 * Tested up to: 6.2.3
 * Requires at least: 5.6
 * Requires PHP: 7.4+
 *
 * WC requires at least: 6.0
 * WC tested up to: 7.9.0
 *
 *
 * @author          <alex@rwsite.ru>
 * @copyright       Copyright (c) Aleksei Tikhomirov
 * @license         http://www.gnu.org/licenses/gpl-3.0.html
 *
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

if(class_exists( 'LicPlugin' ) || defined('WCPUS')){
    return;
}

define('WCPUS', __FILE__);

spl_autoload_register(function ($className){
    $path = realpath(__DIR__ . '/includes/' . strtr($className, '/', DIRECTORY_SEPARATOR) . '.php');
    if (is_readable($path)) {
        include_once $path;
    }
});

register_activation_hook(__FILE__, [ 'LicPlugin', 'activation']);
register_uninstall_hook(__FILE__,  [ 'LicPlugin', 'uninstall']);

add_action('plugins_loaded', [ 'LicPlugin', 'getInstance'], 20);
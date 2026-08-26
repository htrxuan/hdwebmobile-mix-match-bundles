<?php

/**
 * Plugin Name: HDWebmobile Mix & Match Bundles
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-mix-match-bundles/
 * Description: Let customers build their own box: pick a fixed number of items from a category or curated list on a single WooCommerce product.
 * Version: 1.0.3
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-mix-match-bundles
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('HDMMB_VERSION', '1.0.3');
define('HDMMB_PLUGIN_FILE', __FILE__);
define('HDMMB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDMMB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-activator.php';

register_activation_hook(HDMMB_PLUGIN_FILE, array(HDMMB_Activator::class, 'activate'));
add_action('before_woocommerce_init', array(HDMMB_Activator::class, 'declare_hpos_compatibility'));

add_action('plugins_loaded', function () {
    require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-core.php';
    HDMMB_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(HDMMB_PLUGIN_FILE), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" style="color:#d54e21;font-weight:bold;">' . __('Donate', 'hdwebmobile-mix-match-bundles') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});

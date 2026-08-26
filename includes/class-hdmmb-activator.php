<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDMMB_PLUGIN_FILE));
            set_transient('hdmmb_wc_missing_notice', true, 30);
            return;
        }
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDMMB_PLUGIN_FILE, true);
        }
    }
}

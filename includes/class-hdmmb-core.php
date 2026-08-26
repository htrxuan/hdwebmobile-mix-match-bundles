<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

final class HDMMB_Core
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-cart.php';
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-frontend.php';
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-order.php';
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-admin.php';
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-ajax.php';
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-store-api.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        if (is_admin()) {
            HDMMB_Admin::get_instance();
            HDMMB_Ajax::get_instance();
        }

        HDMMB_Frontend::get_instance();
        HDMMB_Cart::get_instance();
        HDMMB_Order::get_instance();
        HDMMB_Store_Api::get_instance();
    }

    public function render_missing_woocommerce_notice()
    {
        if (!get_transient('hdmmb_wc_missing_notice')) {
            return;
        }
        delete_transient('hdmmb_wc_missing_notice');
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e('HDWebmobile Mix & Match Bundles requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-mix-match-bundles'); ?>
            </p>
        </div>
        <?php
    }
}

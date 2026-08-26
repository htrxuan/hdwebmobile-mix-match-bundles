<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exposes bundle contents to the WooCommerce Cart/Checkout Blocks, which read cart line
 * item data via the Store API instead of the classic woocommerce_get_item_data filter.
 */
class HDMMB_Store_Api
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
        add_action('woocommerce_blocks_loaded', array($this, 'register_endpoint_data'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_blocks_frontend_script'));
    }

    public function register_endpoint_data()
    {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        woocommerce_store_api_register_endpoint_data(array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
            'namespace'       => 'hdmmb',
            'data_callback'   => array($this, 'get_cart_item_extension_data'),
            'schema_callback' => array($this, 'get_cart_item_extension_schema'),
            'schema_type'     => ARRAY_A,
        ));
    }

    public function get_cart_item_extension_data($cart_item)
    {
        if (empty($cart_item['hdmmb_selection'])) {
            return array();
        }

        return array(
            'bundle_contents' => HDMMB_Cart::format_selection($cart_item['hdmmb_selection']),
        );
    }

    public function get_cart_item_extension_schema()
    {
        return array(
            'bundle_contents' => array(
                'description' => __('Bundle contents', 'hdwebmobile-mix-match-bundles'),
                'type'        => 'string',
                'context'     => array('view', 'edit'),
                'readonly'    => true,
            ),
        );
    }

    public function maybe_enqueue_blocks_frontend_script()
    {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        if (!wp_script_is('wc-blocks-checkout', 'registered')) {
            return;
        }

        wp_enqueue_script(
            'hdmmb-blocks-frontend-js',
            HDMMB_PLUGIN_URL . 'assets/js/hdmmb-blocks-frontend.js',
            array('wc-blocks-checkout', 'wp-element', 'wp-plugins'),
            HDMMB_VERSION,
            true
        );
    }
}

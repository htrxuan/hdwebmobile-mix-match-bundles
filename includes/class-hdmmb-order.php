<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Order
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
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_line_item_meta'), 10, 4);
    }

    public function add_order_line_item_meta($item, $cart_item_key, $values, $order)
    {
        if (empty($values['hdmmb_selection'])) {
            return;
        }

        $item->add_meta_data(
            __('Bundle Contents', 'hdwebmobile-mix-match-bundles'),
            HDMMB_Cart::format_selection($values['hdmmb_selection']),
            true
        );

        $item->add_meta_data('_hdmmb_selection', $values['hdmmb_selection'], true);
    }
}

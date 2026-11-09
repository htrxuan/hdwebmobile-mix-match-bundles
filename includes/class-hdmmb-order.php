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
        // Fired by wc_reduce_stock_levels() / wc_increase_stock_levels() after WooCommerce has
        // handled the order's own line items, which already guards against running twice.
        add_action('woocommerce_reduce_order_stock', array($this, 'reduce_component_stock'));
        add_action('woocommerce_restore_order_stock', array($this, 'restore_component_stock'));
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

        // Recorded on the order so a later change to the product setting doesn't change what
        // happens to this order's stock.
        if ('yes' === get_post_meta($item->get_product_id(), '_hdmmb_deduct_stock', true)) {
            $item->add_meta_data('_hdmmb_deduct_stock', 'yes', true);
        }
    }

    public function reduce_component_stock($order)
    {
        $changes = array();

        foreach ($order->get_items() as $item) {
            $selection = $item->get_meta('_hdmmb_selection', true);
            if (
                !is_array($selection)
                || 'yes' !== $item->get_meta('_hdmmb_deduct_stock', true)
                || $item->get_meta('_hdmmb_reduced_stock', true)
            ) {
                continue;
            }

            $reduced = array();
            foreach ($selection as $component_id => $qty) {
                $component = wc_get_product($component_id);
                if (!$component || !$component->managing_stock()) {
                    continue;
                }

                $total     = (int) $qty * $item->get_quantity();
                $new_stock = wc_update_product_stock($component, $total, 'decrease');
                if (is_wp_error($new_stock) || null === $new_stock) {
                    continue;
                }

                $reduced[$component_id] = $total;
                $changes[]              = $component->get_name() . ' (' . ($new_stock + $total) . '&rarr;' . $new_stock . ')';
            }

            if ($reduced) {
                $item->update_meta_data('_hdmmb_reduced_stock', $reduced);
                $item->save();
            }
        }

        if ($changes) {
            $order->add_order_note(__('Bundle component stock reduced:', 'hdwebmobile-mix-match-bundles') . ' ' . implode(', ', $changes));
        }
    }

    public function restore_component_stock($order)
    {
        $changes = array();

        foreach ($order->get_items() as $item) {
            $reduced = $item->get_meta('_hdmmb_reduced_stock', true);
            if (!is_array($reduced)) {
                continue;
            }

            foreach ($reduced as $component_id => $total) {
                $component = wc_get_product($component_id);
                if (!$component || !$component->managing_stock()) {
                    continue;
                }

                $new_stock = wc_update_product_stock($component, (int) $total, 'increase');
                if (!is_wp_error($new_stock) && null !== $new_stock) {
                    $changes[] = $component->get_name() . ' (' . ($new_stock - $total) . '&rarr;' . $new_stock . ')';
                }
            }

            $item->delete_meta_data('_hdmmb_reduced_stock');
            $item->save();
        }

        if ($changes) {
            $order->add_order_note(__('Bundle component stock restored:', 'hdwebmobile-mix-match-bundles') . ' ' . implode(', ', $changes));
        }
    }
}

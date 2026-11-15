<?php

namespace htrxuan\hdmmb;

if (!defined('ABSPATH')) {
    exit;
}

class HDMMB_Admin
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
        add_filter('woocommerce_product_data_tabs', array($this, 'add_product_data_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'render_product_data_panel'));
        add_action('woocommerce_process_product_meta_simple', array($this, 'save_product_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        require_once HDMMB_PLUGIN_DIR . 'includes/class-hdmmb-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
    }

    /**
     * Every setting lives on the product-data tab, so this hub tab is an overview of the
     * store's bundles: what each one is set to, with a link straight to its settings.
     */
    public function register_hub_tabs($tabs)
    {
        $tabs['mix-match-bundles'] = array(
            'label'  => __('Mix & Match Bundles', 'hdwebmobile-mix-match-bundles'),
            'order'  => 90,
            'render' => array($this, 'render_plugins_page'),
        );
        return $tabs;
    }

    public function render_plugins_page()
    {
        $bundles = wc_get_products(array(
            'status'     => array('publish', 'draft', 'pending', 'private'),
            'limit'      => -1,
            'orderby'    => 'title',
            'order'      => 'ASC',
            'meta_key'   => '_hdmmb_enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only screen, one query.
            'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ));
        ?>
        <p><?php esc_html_e('Let customers build their own box by picking a set number of items on one product page. Each bundle is set up in its own product\'s "Mix & Match" tab under Product data.', 'hdwebmobile-mix-match-bundles'); ?></p>

        <?php if (!defined('HDMMBP_VERSION')) : ?>
            <p class="description">
                <?php esc_html_e('Need "choose 4 to 8 items", quantity discounts, variable products or gift messages? They are in Mix & Match Bundles Pro, a separate paid add-on. Everything on this page stays free.', 'hdwebmobile-mix-match-bundles'); ?>
                <a href="https://hdwebmobile.com/plugins/hdwebmobile-mix-match-bundles-pro/" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'hdwebmobile-mix-match-bundles'); ?></a>
            </p>
        <?php endif; ?>

        <?php if (empty($bundles)) : ?>
            <p><strong><?php esc_html_e('No bundles yet.', 'hdwebmobile-mix-match-bundles'); ?></strong>
                <?php esc_html_e('Create a simple product, open its "Mix & Match" tab and tick "Enable Mix & Match".', 'hdwebmobile-mix-match-bundles'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=product')); ?>"><?php esc_html_e('Create a bundle product', 'hdwebmobile-mix-match-bundles'); ?></a></p>
            <?php
            return;
        endif;
        ?>
        <table class="widefat striped" style="max-width:1100px;margin-top:12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Bundle', 'hdwebmobile-mix-match-bundles'); ?></th>
                    <th><?php esc_html_e('Pick', 'hdwebmobile-mix-match-bundles'); ?></th>
                    <th><?php esc_html_e('Price', 'hdwebmobile-mix-match-bundles'); ?></th>
                    <th><?php esc_html_e('Items to choose from', 'hdwebmobile-mix-match-bundles'); ?></th>
                    <th><?php esc_html_e('Deducts item stock', 'hdwebmobile-mix-match-bundles'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bundles as $bundle) : ?>
                    <?php
                    $id        = $bundle->get_id();
                    $pick_qty  = absint(get_post_meta($id, '_hdmmb_pick_qty', true));
                    $available = 0;
                    foreach (HDMMB_Cart::get_eligible_component_ids($id) as $component_id) {
                        if (HDMMB_Cart::is_available_component(wc_get_product($component_id))) {
                            $available++;
                        }
                    }

                    if ('discount_percent' === get_post_meta($id, '_hdmmb_pricing_mode', true)) {
                        /* translators: %s: discount percentage */
                        $price = sprintf(__('%s%% off picked items', 'hdwebmobile-mix-match-bundles'), wc_format_localized_decimal((float) get_post_meta($id, '_hdmmb_discount_percent', true)));
                    } else {
                        $price = wp_strip_all_tags(wc_price(HDMMB_Cart::calculate_bundle_price($bundle, array())));
                    }

                    // Same problems that make the product page fall back to a normal Add to cart button.
                    $problem = '';
                    if (!$pick_qty) {
                        $problem = __('Pick quantity not set', 'hdwebmobile-mix-match-bundles');
                    } elseif (!$available) {
                        $problem = __('No items in stock to choose from', 'hdwebmobile-mix-match-bundles');
                    }
                    ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url(get_edit_post_link($id)); ?>"><?php echo esc_html($bundle->get_name()); ?></a></strong>
                            <?php if ('publish' !== $bundle->get_status()) : ?>
                                &mdash; <?php echo esc_html(get_post_status_object($bundle->get_status())->label ?? $bundle->get_status()); ?>
                            <?php endif; ?>
                            <?php if ($problem) : ?>
                                <br><span style="color:#b32d2e;"><?php echo esc_html($problem); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $pick_qty ? esc_html($pick_qty) : '&mdash;'; ?></td>
                        <td><?php echo esc_html($price); ?></td>
                        <td><?php echo esc_html($available); ?></td>
                        <td><?php echo 'yes' === get_post_meta($id, '_hdmmb_deduct_stock', true) ? esc_html__('Yes', 'hdwebmobile-mix-match-bundles') : esc_html__('No', 'hdwebmobile-mix-match-bundles'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function add_product_data_tab($tabs)
    {
        $tabs['hdmmb'] = array(
            'label'    => __('Mix & Match', 'hdwebmobile-mix-match-bundles'),
            'target'   => 'hdmmb_product_data',
            'class'    => array('show_if_simple'),
            'priority' => 21,
        );
        return $tabs;
    }

    public function enqueue_admin_assets($hook)
    {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        global $post_type;
        if ('product' !== $post_type) {
            return;
        }

        wp_enqueue_script(
            'hdmmb-admin-js',
            HDMMB_PLUGIN_URL . 'assets/js/hdmmb-admin.js',
            array('jquery'),
            HDMMB_VERSION,
            true
        );

        wp_enqueue_style(
            'hdmmb-admin-css',
            HDMMB_PLUGIN_URL . 'assets/css/hdmmb-admin.css',
            array(),
            HDMMB_VERSION
        );
    }

    public function render_product_data_panel()
    {
        global $post;

        $product_id       = $post->ID;
        $enabled          = get_post_meta($product_id, '_hdmmb_enabled', true);
        $pick_qty         = get_post_meta($product_id, '_hdmmb_pick_qty', true);
        $pricing_mode     = get_post_meta($product_id, '_hdmmb_pricing_mode', true) ?: 'fixed';
        $bundle_price     = get_post_meta($product_id, '_hdmmb_bundle_price', true);
        $discount_percent = get_post_meta($product_id, '_hdmmb_discount_percent', true);
        $source_type      = get_post_meta($product_id, '_hdmmb_source_type', true) ?: 'category';
        $source_category  = get_post_meta($product_id, '_hdmmb_source_category', true);
        $source_products  = get_post_meta($product_id, '_hdmmb_source_products', true);
        $source_products  = is_array($source_products) ? $source_products : array();
        // On by default for new bundles; bundles set up before 1.1.0 keep their old behavior
        // (no deduction) until the merchant turns it on.
        $deduct_stock     = get_post_meta($product_id, '_hdmmb_deduct_stock', true);
        $deduct_stock     = '' === $deduct_stock && '' === $enabled ? 'yes' : ($deduct_stock ?: 'no');

        wp_nonce_field('hdmmb_save_meta', 'hdmmb_meta_nonce');
        ?>
        <div id="hdmmb_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_hdmmb_enabled',
                    'label'       => __('Enable Mix & Match', 'hdwebmobile-mix-match-bundles'),
                    'description' => __('Let customers build a bundle by picking components on this product\'s page.', 'hdwebmobile-mix-match-bundles'),
                    'value'       => $enabled ?: 'no',
                ));
                ?>
            </div>

            <div class="options_group show_if_hdmmb_enabled">
                <?php
                woocommerce_wp_text_input(array(
                    'id'                => '_hdmmb_pick_qty',
                    'label'             => __('Pick quantity', 'hdwebmobile-mix-match-bundles'),
                    'description'       => __('Customers must select exactly this many units before they can add the bundle to cart.', 'hdwebmobile-mix-match-bundles'),
                    'desc_tip'          => true,
                    'type'              => 'number',
                    'custom_attributes' => array('step' => '1', 'min' => '1'),
                    'value'             => $pick_qty ?: '',
                ));

                woocommerce_wp_radio(array(
                    'id'      => '_hdmmb_pricing_mode',
                    'label'   => __('Pricing mode', 'hdwebmobile-mix-match-bundles'),
                    'options' => array(
                        'fixed'            => __('Fixed bundle price', 'hdwebmobile-mix-match-bundles'),
                        'discount_percent' => __('Percentage off component total', 'hdwebmobile-mix-match-bundles'),
                    ),
                    'value'   => $pricing_mode,
                ));

                woocommerce_wp_text_input(array(
                    'id'            => '_hdmmb_bundle_price',
                    /* translators: %s: store currency symbol */
                    'label'         => sprintf(__('Bundle price (%s)', 'hdwebmobile-mix-match-bundles'), get_woocommerce_currency_symbol()),
                    'data_type'     => 'price',
                    'wrapper_class' => 'show_if_pricing_fixed',
                    'value'         => $bundle_price,
                ));

                woocommerce_wp_text_input(array(
                    'id'                => '_hdmmb_discount_percent',
                    'label'             => __('Discount percent', 'hdwebmobile-mix-match-bundles'),
                    'type'              => 'number',
                    'custom_attributes' => array('step' => '0.01', 'min' => '0', 'max' => '100'),
                    'wrapper_class'     => 'show_if_pricing_discount',
                    'value'             => $discount_percent !== '' ? $discount_percent : '',
                ));

                woocommerce_wp_radio(array(
                    'id'      => '_hdmmb_source_type',
                    'label'   => __('Component source', 'hdwebmobile-mix-match-bundles'),
                    'options' => array(
                        'category' => __('Product category', 'hdwebmobile-mix-match-bundles'),
                        'manual'   => __('Manual product list', 'hdwebmobile-mix-match-bundles'),
                    ),
                    'value'   => $source_type,
                ));
                ?>

                <p class="form-field show_if_source_category">
                    <label for="_hdmmb_source_category"><?php esc_html_e('Category', 'hdwebmobile-mix-match-bundles'); ?></label>
                    <?php
                    wp_dropdown_categories(array(
                        'taxonomy'         => 'product_cat',
                        'name'             => '_hdmmb_source_category',
                        'id'               => '_hdmmb_source_category',
                        'class'            => 'wc-enhanced-select',
                        'show_option_none' => __('Select a category&hellip;', 'hdwebmobile-mix-match-bundles'),
                        'selected'         => $source_category,
                        'hide_empty'       => false,
                    ));
                    ?>
                </p>

                <p class="form-field show_if_source_manual">
                    <label for="_hdmmb_source_products"><?php esc_html_e('Eligible products', 'hdwebmobile-mix-match-bundles'); ?></label>
                    <select
                        id="_hdmmb_source_products"
                        name="_hdmmb_source_products[]"
                        class="wc-product-search"
                        multiple="multiple"
                        style="width:50%;"
                        data-placeholder="<?php esc_attr_e('Search for products&hellip;', 'hdwebmobile-mix-match-bundles'); ?>"
                        data-action="woocommerce_json_search_products_and_variations"
                        data-exclude="<?php echo intval($product_id); ?>"
                    >
                        <?php
                        foreach ($source_products as $product_id_option) {
                            $option_product = wc_get_product($product_id_option);
                            if ($option_product) {
                                echo '<option value="' . esc_attr($product_id_option) . '" selected="selected">' . esc_html($option_product->get_formatted_name()) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </p>

                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_hdmmb_deduct_stock',
                    'label'       => __('Deduct component stock', 'hdwebmobile-mix-match-bundles'),
                    'description' => __('When an order reduces stock, also reduce the stock of each picked item (for items that track stock quantity). Restored if the order is cancelled.', 'hdwebmobile-mix-match-bundles'),
                    'value'       => $deduct_stock,
                ));

                /**
                 * Fires at the end of the Mix & Match product settings, for extra options.
                 *
                 * @param int $product_id Bundle product ID.
                 */
                do_action('hdmmb_product_options', (int) $product_id);
                ?>
            </div>
        </div>
        <?php
    }

    public function save_product_meta($post_id)
    {
        if (!isset($_POST['hdmmb_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdmmb_meta_nonce'])), 'hdmmb_save_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = isset($_POST['_hdmmb_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_hdmmb_enabled', $enabled);
        update_post_meta($post_id, '_hdmmb_deduct_stock', isset($_POST['_hdmmb_deduct_stock']) ? 'yes' : 'no');

        if (isset($_POST['_hdmmb_pick_qty'])) {
            update_post_meta($post_id, '_hdmmb_pick_qty', absint($_POST['_hdmmb_pick_qty']));
        }

        if (isset($_POST['_hdmmb_pricing_mode'])) {
            $pricing_mode = sanitize_text_field(wp_unslash($_POST['_hdmmb_pricing_mode']));
            $pricing_mode = in_array($pricing_mode, array('fixed', 'discount_percent'), true) ? $pricing_mode : 'fixed';
            update_post_meta($post_id, '_hdmmb_pricing_mode', $pricing_mode);
        }

        if (isset($_POST['_hdmmb_bundle_price'])) {
            update_post_meta($post_id, '_hdmmb_bundle_price', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_hdmmb_bundle_price']))));
        }

        if (isset($_POST['_hdmmb_discount_percent'])) {
            update_post_meta($post_id, '_hdmmb_discount_percent', wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_hdmmb_discount_percent']))));
        }

        if (isset($_POST['_hdmmb_source_type'])) {
            $source_type = sanitize_text_field(wp_unslash($_POST['_hdmmb_source_type']));
            $source_type = in_array($source_type, array('category', 'manual'), true) ? $source_type : 'category';
            update_post_meta($post_id, '_hdmmb_source_type', $source_type);
        }

        if (isset($_POST['_hdmmb_source_category'])) {
            update_post_meta($post_id, '_hdmmb_source_category', absint($_POST['_hdmmb_source_category']));
        }

        $source_products = isset($_POST['_hdmmb_source_products']) ? array_map('absint', (array) $_POST['_hdmmb_source_products']) : array();
        update_post_meta($post_id, '_hdmmb_source_products', $source_products);
    }
}

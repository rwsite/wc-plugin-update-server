<?php
/**
 * Admin interface and actions
 */

final class LicOrderMetaBox
{

    /**
     * Add actions
     *
     * @return void
     */
    public function add_actions()
    {

        add_action('add_meta_boxes', [$this, 'add_meta_boxes'], 30);

        // Add licences data to Order preview
        add_filter('woocommerce_admin_order_preview_get_order_details', [$this, 'order_preview_add_data'], 8, 2);

        // Add licences data to Order table
        // add_action( 'woocommerce_admin_order_data_after_billing_address', [$this, 'add_order_meta'], 10, 1 );

        // Order save action. metabox handler
        add_action('save_post', [$this, 'save'], 60, 2);
    }

    /**
     * Add order metabox
     */
    public function add_meta_boxes()
    {
        $screen = get_current_screen();
        $screen_id = $screen ? $screen->id : '';
        foreach (wc_get_order_types('order-meta-boxes') as $type) {
            $order_type_object = get_post_type_object($type);
            if ('shop_order' === $order_type_object->name) {
                add_meta_box('licence', __('Manage licences', 'wc-pus'), [$this,'licence_meta_box'], $type, 'side', 'default');
            }
        }
    }

    /**
     * Render meta-box on admin order page
     *
     * @param WP_Post $post
     */
    public function licence_meta_box(WP_Post $post)
    {
        global $theorder;

        if (!is_object($theorder)) {
            $theorder = wc_get_order($post->ID);
        }

        $order = $theorder;
        ?>

        <div class="lic_data_column_container">
            <div class="lic_data_column">
            <?php $this->add_order_meta($order); ?>
            </div>

            <div class="lic_data_column">
            <?php $this->add_lic_content($order); ?>
            </div>
        </div>

        <style>
            .lic_data_column_container {
                display: grid;
                grid-template-columns: repeat(1, 1fr);
                grid-template-rows: 1fr;
                grid-column-gap: 0;
                grid-row-gap: 0;
            }
            .lic_data_column{
                border-bottom: 1px solid #f0f0f1;
            }
            .lic_data_column h4 {
                font-size: 1.3em;
                margin: 0 !important;
            }
        </style>
        <?php

    }


    /**
     * Save meta box data.
     * @see LicOrderMetaBox::add_lic_content()
     *
     * @param int $post_id Post ID.
     */
    public static function save(int $post_id, WP_Post $post)
    {
        $post_id = absint($post_id);

        // $post_id and $post are required && Dont' save meta boxes for revisions or autosaves.
        if (empty($post_id) || empty($post) || defined('DOING_AUTOSAVE') || is_int(wp_is_post_revision($post)) || is_int(wp_is_post_autosave($post))) {
            return;
        }
        // Check the nonce.
        if (empty($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(wp_unslash($_POST['woocommerce_meta_nonce']), 'woocommerce_save_data')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }
        // Check the post being saved == the $post_id to prevent triggering this call for other save_post events.
        if (empty($_POST['post_ID']) || absint($_POST['post_ID']) !== $post_id) {
            return;
        }

        // Check user has permission to edit. Check right button click
        if (!current_user_can('edit_post', $post_id) || !isset($_POST['wc-pus_save'])) {
            return;
        }

        /**
         *
         * [lic_name] => 0
         * [lic_type] => plugin
         * [lic_slug] => woo-to-iiko
         * [lic_key] => 8bc3be6a-dfb7-489c-a229-5eeb1d4b8287
         * [lic_sites_allowed] => 1
         * [lic_renewal_period] => 1|''
         */
        $data = [];
        if (isset($_POST['lic_product_id'], $_POST['lic_type'], $_POST['lic_slug'], $_POST['lic_key'],
                $_POST['lic_sites_allowed'], $_POST['lic_renewal_period']) && !empty($_POST['lic_product_id'])) {

            $order = wc_get_order($post_id);
            $name = '';
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                $name = $product->get_name();
            }

            $data['item'] = $name;
            $data['key'] = $_POST['lic_key'];

            $renewal_period= intval($_POST['lic_renewal_period']);
            if (0 >= $renewal_period) {
                $renewal_period = date('Y-m-d', strtotime('+99 years'));
            } else {
                $renewal_period = date('Y-m-d', strtotime('+' . $renewal_period . ' years'));
            }

            if (0 !== intval($_POST['lic_renewal_period'])) {
                $data['expires'] = date('Y-m-d', strtotime('+' . intval($_POST['lic_renewal_period']) . ' years'));
            }

            $meta = (array)get_post_meta($post_id, LicOrder::key, true);
            $order_new_lic_data = $data;

            $data['date_created'] = date('Y-m-d', strtotime(current_time('mysql')));
            $data['date_renewed'] = $data['date_created'];
            $data['date_expiry'] = $renewal_period;

            $data['max_allowed_domains'] = intval($_POST['lic_sites_allowed']) > 0 ? intval($_POST['lic_sites_allowed']) : 1;
            $data['email'] = $_POST['_billing_email'] ?? '';
            $data['package_slug'] = $_POST['lic_slug'];
            $data['package_type'] = $_POST['lic_type'];
            $data['owner_name'] = $_POST['_billing_first_name'] . ' ' . $_POST['_billing_last_name'];
            $data['company_name'] = $_POST['_billing_company'];
            $data['txn_id'] = (string)$post_id;
            $data['first_name'] = $_POST['_billing_first_name'];
            $data['last_name'] = $_POST['_billing_last_name'];

            $result = self::save_licence_to_WPPUS($data);
            
            if ($result instanceof stdClass) {
                $order_new_lic_data['lic_id'] = $result->id;
            }

            $meta[] = $order_new_lic_data;
            update_post_meta($post_id, LicOrder::key, $meta);

            if (!empty($order_new_lic_data['lic_id'])) {
                $message = __('License Key(s) generated', 'wc-pus');
                $message .= '<br />' . $order_new_lic_data['item'] . ': ' . $order_new_lic_data['key'];
            } else {
                $message = __('Error! License Key(s) could not be created.', 'wc-pus');
            }

            LicOrder::add_order_note($post_id, $message);
        }
    }

    /**
     * Save new licence
     *
     * @param array $payload
     * @return array|array[]|bool[]|mixed|object
     */
    public static function save_licence_to_WPPUS(array $payload)
    {
        $lic_manager = new WPPUS_License_Server();
        $result = $lic_manager->add_license($payload);
        if (is_array($result) && isset($result['errors'])) {
            error_log(print_r($result, true));
        }
        return $result;
    }


    /**
     * Showing licence keys on admin Order page
     *
     * @param WC_Order $order
     */
    public function add_order_meta(WC_Order $order)
    {
        $order_id = $order->get_id();
        $licenses = get_post_meta($order_id, LicOrder::key, true);
        $licenses_enable = !empty($licenses);
         if ($licenses_enable): ?>
            <h4><?php _e('Licences', 'wc-pus')?>:</h4>
            <!--<button id="lic-t-btn" href="#" class="button button-primary">Show licence list</button>-->
            <div class="lic-list" style="<?php echo $licenses_enable ? '' : ' display:none;'; ?>">
                <?php if (!empty($licenses)) {
                    foreach ($licenses as $k => $lic) {
                        if (!empty($lic['lic_id'])) {
                            $licence = self::get_license_by_id($lic['lic_id']);
                            woocommerce_wp_text_input([
                                'id'                => LicOrder::key . '[' . $k . ']',
                                'label'             => sprintf('%s %s. </br> %s to %s. ', $licence->package_type, $licence->package_slug, $licence->date_created, $licence->date_expiry),
                                'value'             => $licence->license_key,
                                'custom_attributes' => ['readonly' => true],
                                'description'       => __('You can change this data in WP PUS', 'wc-pus'),
                                'desc_tip'          => true,
                            ]);
                        }
                    }
                } ?>
            </div>
        <?php
        endif;
        add_action('admin_footer', function () {
            ?>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    $("#lic-t-btn").on("click", function (e) {
                        e.preventDefault();
                        $(".lic-list").toggle('slow');
                    });
                });
            </script>
            <?php
        });
    }

    /**
     * @see LicOrderMetaBox::save()
     * @param $order
     *
     * @return void
     */
    public function add_lic_content($order)
    {
        $items = [];
        $line_items = $order->get_items(apply_filters('woocommerce_admin_order_item_types', 'line_item'));
        foreach ($line_items as $item) {
            $items[$item->get_id()] = $item->get_name();
        }

        if (!empty($items)) {
            woocommerce_wp_select([
                'id'      => 'lic_product_id',
                'label'   => __('Product name', 'wc-pus'),
                'options' => $items,
                'value'   => array_shift($line_items)->get_id(),
            ]);
        }

        woocommerce_wp_select([
            'id'      => 'lic_type',
            'label'   => __('Product type', 'wc-pus'),
            'options' => LicOrder::get_types(),
            'value'   => 'plugin',
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_slug',
            'label' => __('Product slug', 'wc-pus'),
            'value' => apply_filters('lic_default_value', 'woo-to-iiko'),
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_key',
            'label' => __('New Key', 'wc-pus'),
            'value' => bin2hex(openssl_random_pseudo_bytes(16)),
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_sites_allowed',
            'label' => __('Maximum domains', 'wc-pus'),
            'type'  => 'number',
            'default' => '1'
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_renewal_period',
            'label' => __('License renewal period. In years.', 'wc-pus'),
            'type'  => 'number',
            'default' => '1'
        ]);

        submit_button( __('Add new licence','wc-pus'), 'primary large', 'wc-pus_save', false, null);
    }


    /**
     * Showing data in admin order Preview
     *
     * @param array $args
     * @param WC_Order $order
     * @return array
     */
    public function order_preview_add_data(array $args, WC_Order $order)
    {
        $licenses = get_post_meta($order->get_id(), LicOrder::key, true);

        if (!empty($licenses)) {
            $string = '<h3>Licences:</h3>';
            $string .= '<ul>';
            foreach ($licenses as $lic) {
                if (!empty($lic['lic_id'])) {
                    $licence = self::get_license_by_id($lic['lic_id']);
                    $string .= sprintf('<li><b>%s %s:</b> <pre><code>%s</code></pre></li>',
                        $licence->package_type, $licence->package_slug, $licence->license_key);
                }
            }
            $string .= '</ul>';
            $args['payment_via'] = $string;
        }
        return $args;
    }

    /**
     * Get licence by ID
     *
     * [id] => 127
     * [license_key] => df3b5f4d6b244b517260ed8dd1605ebf
     * [max_allowed_domains] => 1
     * [allowed_domains] => a:0:{}
     * [status] => pending
     * [owner_name] => testТихомиров
     * [email] => tech@rwsite.ru
     * [company_name] =>
     * [txn_id] => 4783
     * [date_created] => 2022-06-01
     * [date_renewed] => 2022-06-01
     * [date_expiry] => 2023-06-14
     * [package_slug] => woo-to-iiko
     * [package_type] => plugin
     * @return stdClass{id: int, license_key: string, max_allowed_domains: int, date_expiry: string, package_slug:string, package_type: string}
     */
    public static function get_license_by_id($id)
    {
        /** @var wpdb */
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wppus_licenses WHERE `id` = '%d'", $id));
        return $result[0] ?? null;
    }
}
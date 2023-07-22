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
                add_meta_box('licence', __('Manage licences', 'wc-pus'), [$this, 'render_meta_box' ], $type, 'side', 'default');
            }
        }
    }

    /**
     * Render meta-box on admin order page
     *
     * @param WP_Post $post
     */
    public function render_meta_box(WP_Post $post)
    {
        global $theorder;

        if (!is_object($theorder)) {
            $theorder = wc_get_order($post->ID);
        }

        $order = $theorder;
        if(!$order){
            return;
        }
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
            .short {
                width: 100%;
            }
        </style>
        <?php
    }


    /**
     * Save meta box data. Save data form process
     *
     * @see LicOrderMetaBox::add_lic_content()
     *
     * @param int $post_id Post ID.
     */
    public static function save(int $post_id, WP_Post $post = null)
    {
        $post_id = absint($post_id);

        if(false === self::save_validation($post_id, $post)){
            return;
        }
	    $product_id     = absint($_POST['lic_product_id']);

	    $data = self::save_data_complete($post_id, $product_id);
        /*
         * [id]
         * [license_key]
         * [max_allowed_domains]
         * [allowed_domains]
         * [status]
         * [owner_name]
         * [email]
         * [company_name]
         * [txn_id]
         * [date_created]
         * [date_renewed]
         * [date_expiry]
         * [package_slug]
         * [package_type]
         */
        $result = self::save_licence_to_WPPUS($data);

        if (!$result instanceof stdClass) {
	        wp_die('WC Pus: saving error');
        }

	    $meta = (array) get_post_meta($post_id, LicOrder::key, true);
        $meta[] = $result->id;

        update_post_meta($post_id, LicOrder::key, $meta);

        if ($result) {
            $message = __('The license Key has been generated success', 'wc-pus');
            $message .= '<br />' . $result->package_type .' '. $result->package_slug . ': ' . $result->license_key;
        } else {
            $message = __('Error! The license key creating has been failed.', 'wc-pus');
	        trigger_error(print_r([$data, $result], true));
        }

        LicOrder::add_order_note($post_id, $message);

    }

	/**
     *
     *
	 * @param int $post_id
	 * @param int $product_id
	 *
	 * @return array
	 */
    private static function save_data_complete($post_id, $product_id){
	    $order = wc_get_order($post_id);
	    $wc_product = wc_get_product($product_id);

	    if(empty($wc_product) || empty($order) || '1' !== $wc_product->get_meta('_wc_slm_licensing_enabled')){
		    wp_die('WC Pus: saving error');
	    }

	    $data = [];
	    $data['item'] = $wc_product->get_name();
	    $data['key']  = bin2hex(openssl_random_pseudo_bytes(16));

	    $renewal_period = $_POST['lic_renewal_period'] ?? (int)$wc_product->get_meta('_wc_slm_licensing_renewal_period');

	    if (0 >= $renewal_period) {
		    $data['date_expiry'] = date('Y-m-d', strtotime('+99 years'));
	    } else {
		    $data['date_expiry'] = date('Y-m-d', strtotime('+' . $renewal_period . ' years'));
	    }

	    if (0 !== intval($renewal_period)) {
		    $data['expires'] = date('Y-m-d', strtotime('+' . $renewal_period . ' years'));
	    }

	    $data['date_created'] = date('Y-m-d', strtotime(current_time('mysql')));
	    $data['date_renewed'] = $data['date_created'];
	    $data['max_allowed_domains'] = $_POST['lic_sites_allowed'] ?? $wc_product->get_meta('_wc_slm_sites_allowed');
	    $data['package_slug'] = $_POST['lic_slug'] ?? $wc_product->get_meta('_wc_slm_slug');
	    $data['package_type'] = $_POST['lic_type'] ?? $wc_product->get_meta('_wc_slm_type');

	    $data['email'] = $_POST['_billing_email'] ?? '';
	    $data['owner_name'] = $_POST['_billing_first_name'] . ' ' . $_POST['_billing_last_name'];
	    $data['company_name'] = $_POST['_billing_company'];
	    $data['txn_id'] = (string)$post_id;
	    $data['first_name'] = $_POST['_billing_first_name'];
	    $data['last_name'] = $_POST['_billing_last_name'];

        return $data;
    }


	/**
	 * @return bool
	 */
    private static function save_validation($post_id, $post){

	    // $post_id and $post are required && Dont' save meta boxes for revisions or autosaves.
	    if (empty($post_id) || defined('DOING_AUTOSAVE') || is_int(wp_is_post_revision($post)) || is_int(wp_is_post_autosave($post))) {
		    return false;
	    }

	    // Check the nonce.
	    if (empty($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce(wp_unslash($_POST['woocommerce_meta_nonce']), 'woocommerce_save_data')) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		    return false;
	    }

	    // Check the post being saved == the $post_id to prevent triggering this call for other save_post events.
	    if (empty($_POST['post_ID']) || absint($_POST['post_ID']) !== $post_id) {
		    return false;
	    }

	    // Check user has permission to edit. Check right button click
	    if (!current_user_can('edit_post', $post_id) || empty($_POST['lic_product_id']) ) {
		    return false;
	    }

        if('wc-completed' !== $_POST['order_status'] && !isset($_POST['lic_save'])){
            return false;
        }

        return true;
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
            <h3><?php _e('Licences', 'wc-pus')?></h3>

            <?php if( count($licenses) > 3):?>
                <button id="lic-t-btn" href="#" class="button button-secondary">Show license list</button>
                 <p></p>
            <?php endif; ?>

            <div class="lic-list" style="<?php echo count($licenses) > 3 ? ' display:none;' : ''; ?>">
                <?php if (!empty($licenses)) {
                    foreach ($licenses as $k => $lic) {
                        $licence = self::get_license_by_id($lic['lic_id'] ?? $lic);
	                    if (!empty($licence)) {
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
                (function ($) {
                    $("#lic-t-btn").on("click", function (e) {
                        e.preventDefault();
                        $(".lic-list").toggle('display');
                    });
                })(jQuery);
            </script>
            <?php
        });
    }

    /**
     * @see LicOrderMetaBox::save()
     * @param WC_Order| WC_Order_Refund $order
     *
     * @return void
     */
    public function add_lic_content( $order)
    {
        $options_products = [];
        $line_items = $order->get_items();
        $can_add = false;
        foreach ($line_items as $item) {
            if($item instanceof WC_Order_Item_Product) {
	            $product = $item->get_product();
                if('1' == $product->get_meta('_wc_slm_licensing_enabled')) {
	                $options_products[ $product->get_id() ] = $item->get_name();
                    $can_add = true;
                }
            }
        }

	    if(!$can_add){
            _e('Licenses cannot be added to this order.', 'wc-pus');
            return;
        }
        ?>

        <h3><?php _e('Add new licence', 'wc-pus')?>:</h3>

        <?php
        if (!empty($options_products)) {
            woocommerce_wp_select([
                'id'      => 'lic_product_id',
                'label'   => __('Choose product', 'wc-pus'),
                'options' => $options_products,
                'value'   => array_shift($line_items)->get_id(),
            ]);
        }

        /*if(!empty($options)){
	        woocommerce_wp_select([
		        'id'      => 'lic_package',
		        'label'   => __('Package', 'wc-pus'),
		        'options' => $options,
		        'value'   => $package['slug'],
	        ]);
        } else {
	        woocommerce_wp_select([
		        'id'      => 'lic_type',
		        'label'   => __('Product type', 'wc-pus'),
		        'options' => LicOrder::get_types(),
		        'value'   => !empty($package['type']) ? strtolower($package['type']) : 'plugin',
	        ]);

	        woocommerce_wp_text_input( [
		        'id'    => 'lic_slug',
		        'label' => __( 'Package slug', 'wc-pus' ),
		        'value' => apply_filters( 'lic_default_value', $package['slug'] ),
	        ] );
        }*/

        /*woocommerce_wp_text_input([
            'id'    => 'lic_key',
            'label' => __('New Key', 'wc-pus'),
            'value' => bin2hex(openssl_random_pseudo_bytes(16)),
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_sites_allowed',
            'label' => __('Maximum domains', 'wc-pus'),
            'type'  => 'number',
            'value' => '1'
        ]);

        woocommerce_wp_text_input([
            'id'    => 'lic_renewal_period',
            'label' => __('License renewal period. In years.', 'wc-pus'),
            'type'  => 'number',
            'value' => '1'
        ]);
*/
        submit_button( __('Add new licence','wc-pus'), 'primary large', 'lic_save', false, null);
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
            $string = '<h3>'. __('Licences', 'wc-pus') .':</h3>';
            $string .= '<ul>';
            foreach ($licenses as $lic) {
                $licence = self::get_license_by_id($lic['lic_id'] ?? $lic);
                if(!empty($licence)) {
	                $string .= sprintf( '<li><b>%s %s:</b> <pre><code>%s</code></pre></li>',
		                $licence->package_type,
		                $licence->package_slug,
		                $licence->license_key );
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

        if(!empty($id)) {
	        $result = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wppus_licenses WHERE `id` = '%d'",$id ) );
        }

        return $result[0] ?? null;
    }
}
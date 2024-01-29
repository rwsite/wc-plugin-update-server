<?php
/**
 * Manager
 */

final class LicOrder
{
    public const key = '_wc_slm_payment_licenses';

    public function __construct(){
    }

    public function add_actions(){

        // 1 - create and save new keys
        add_action('woocommerce_order_status_completed',                [$this, 'create_license_keys'], 20, 1);
        // 2 - add data to email
        add_action('woocommerce_email_before_order_table',              [$this, 'email_content'], 10, 2);
        // 3 - call
        add_action('woocommerce_order_details_before_order_table',      [$this, 'print_order_meta'], 10, 1);

		// download package
	    if ( isset( $_GET['package_slug'] ) && ( isset( $_GET['email'] ) || isset( $_GET['uid'] ) ) ) {
		    add_action( 'init', [ LicOrder::class, 'download_package' ] );
	    }
    }

    /**
     * Generate licences and save to order data
     *
     * @param int|null $order_id Order ID.
     * @return void
     */
    public function create_license_keys( $order_id = null)
    {
        $payment_meta = $licenses = [];
        $order = wc_get_order($order_id);

        if (empty($order)) {
            return;
        }

        $user_id = $order->get_user_id();
        $get_user_meta = get_user_meta($user_id);

        if(!is_array($get_user_meta)){
            error_log('get_user_meta() failed!'); return;
        }

        $payment_meta['user_info']['first_name']    = $get_user_meta['billing_first_name'][0];
        $payment_meta['user_info']['last_name']     = $get_user_meta['billing_last_name'][0] ?? '';
        $payment_meta['user_info']['email']         = $get_user_meta['billing_email'][0];
        $payment_meta['user_info']['company']       = $get_user_meta['billing_company'][0] ?? '';

        // Generate license keys for all products in order
        foreach ($order->get_items() as $item => $values) {

            if(!$values instanceof WC_Order_Item_Product){
                trigger_error('!$values instanceof WC_Order_Item_Product');
            }

            $lic_key = $values->get_meta('_wc_pus_license');
            $product_id = $values->get_product_id();
            $product = new WC_Product($product_id);

            if ( false === LicProduct::get_licensing_enabled($product_id) && empty($lic_key) ) {
                continue;
            }

            $old_lic = LicProlongation::I()->get_old_lic_data($lic_key);
            if(!empty($old_lic)){
                if(LicProlongation::I()->update_old_lic($old_lic)) {
                    $message = esc_html__('License key successfully renewed', 'wc-pus');
                } else {
                    $message = esc_html__('An error occurred while updating the license', 'wc-pus');
                    trigger_error($message);
                }
                self::add_order_note($order_id, $message);
                return;
            }

            $download_quantity = absint($values->get_quantity());
            for ($i = 1; $i <= $download_quantity; $i++) {

                $renewal_period = LicProduct::get_renewal_period($product_id);
                if (0 === $renewal_period) {
                    $renewal_period = date('Y-m-d', strtotime('+99 years'));
                } else {
                    $renewal_period = date('Y-m-d', strtotime('+' . $renewal_period . ' years'));
                }

                // Sites allowed
                $sites_allowed = LicProduct::get_sites_allowed($product_id);
                if ($sites_allowed <= 0 ) {
                    $sites_allowed_error = __('License could not be created: Invalid sites allowed number.', 'wc-pus');
                    $this->add_order_note($order_id, $sites_allowed_error);
                    break;
                }

                $product_slug = LicProduct::get_slug($product_id);
                if (empty($product_slug)) {
                    $this->add_order_note($order_id, __('License could not be created: Invalid product slug.', 'wc-pus'));
                    break;
                }

                $product_type = LicProduct::get_type($product_id);
                if (empty($product_type)) {
                    $this->add_order_note($order_id, __('License could not be created: Invalid product type.', 'wc-pus'));
                    break;
                }

                // Build item name
                $owner_name = (isset($payment_meta['user_info']['first_name'])) ? $payment_meta['user_info']['first_name'] : '';
                $owner_name .= (isset($payment_meta['user_info']['last_name'])) ? ' ' . $payment_meta['user_info']['last_name'] : '';

                // Build parameters
                $api_params = [];
                $api_params['linknonce'] = wp_create_nonce('linknonce');
                $api_params['wppus_license_action'] = 'create';
                $api_params['page'] = 'wppus-page-licenses';

                $payload = [
                    // default data
                    'license_key'         => bin2hex(openssl_random_pseudo_bytes(16)),
                    'date_created'        => mysql2date('Y-m-d', current_time('mysql'), false),
                    'status'              => 'pending',
                    // setup
                    'max_allowed_domains' => $sites_allowed,
                    'email'               => $payment_meta['user_info']['email'] ?? '',
                    'date_renewed'        => mysql2date('Y-m-d', current_time('mysql'), false),
                    'date_expiry'         => $renewal_period,
                    'package_slug'        => $product_slug,
                    'package_type'        => $product_type,
                    'owner_name'          => $owner_name,
                    'company_name'        => $payment_meta['user_info']['company'],
                    'txn_id'              => (string)$order_id,
                    // custom
                    'first_name'          => $payment_meta['user_info']['first_name'] ?? '',
                    'last_name'           => $payment_meta['user_info']['last_name'] ?? '',
                ];

                $lic_manager = new WPPUS_License_Server();
                $result = $lic_manager->add_license($payload);
				$license = $lic_manager->read_license($payload);

                // Collect license keys
                if (!empty($result) && !empty($license->license_key) && !empty($license->id) ) {
                    $licenses[] = [
                        'item'      => $product->get_title(),
                        'key'       => $license->license_key,
                        'expires'   => $renewal_period,
                        'lic_id'    => $license->id
                    ];
                } else {
                    $message = __('License Key(s) could not be created.', 'wc-pus');
                    if(!empty($result['errors'][0])){
                        $message .= $result['errors'][0];
                    }
                    trigger_error($message);
                    self::add_order_note($order_id, $message);
                }
            }
        }


        if (count($licenses) > 0) {

            update_post_meta($order_id, LicOrder::key, $licenses);

            $message = __('License Key(s) generated', 'wc-pus');
            foreach ($licenses as $license) {
                $message .= '<br />' . $license['item'] . ': ' . $license['key'] . PHP_EOL;
            }
            self::add_order_note($order_id, $message);
        }
    }

    /**
     * Add note to order
     */
    public static function add_order_note( int $order_id, string $note){
        $order = wc_get_order($order_id);
        $order->add_order_note($note);
    }

    /**
     * Add license details to user account details
     *
     * @param WC_Order|bool|WC_Order_Refund $order
     */
    public function print_order_meta($order, $show_title = true){

        $licenses = get_post_meta( is_int($order) ? $order : $order->get_id() , LicOrder::key, true);

        if (!empty($licenses) && count($licenses) != 0) {
	        $output = '';
			if($show_title) {
				$output .= '<h2>' . __( 'Your Licenses', 'wc-pus' ) . ':</h2>';
			}
            $output .= '<table class="shop_table shop_table_responsive"><tr>';
	        $output .= '<th class="td">' . __('Expires', 'wc-pus') . '</th>';
	        $output .= '<th class="td">' . __('Key', 'wc-pus') . '</th>';
			$output .= '<th class="td">' . __('Domain', 'wc-pus' ) . '</th>';
	        $output .= '<th class="td">' . __('Download', 'wc-pus') . '</th></tr>';

            $output = apply_filters('wc_pus_table_header', $output, $licenses);

			$lic_manager = new WPPUS_License_Server();
            foreach ($licenses as $license) {
				/*
				[id] => 187
	            [license_key] => 2f61e0aee14b77d84e35fe67008942a7
	            [max_allowed_domains] => 1
	            [allowed_domains] => []
	            [status] => pending
	            [owner_name] => Test
	            [email] => tech@rwsite.ru
	            [company_name] =>
	            [txn_id] => 4948
	            [date_created] => 2023-02-17
	            [date_renewed] => 2023-02-17
	            [date_expiry] => 2024-02-17
	            [package_slug] => woo-to-iiko
	            [package_type] => plugin
				 */
				$lic = (array) $lic_manager->read_license(['id' => $license['lic_id'] ?? $license]);
				if(empty($lic)){
					continue;
				}

	            $lic['download'] = $this->generate_download_link($lic);
				$html = '';
				foreach ($lic['allowed_domains'] as $domain){
					$html .= '<a href="https://'.$domain.'" target="_blank" class="domain">' .$domain . '</a><br>';
				}

	            $output .= '<tr>';
	            $output .= '<td class="td">' . $lic['date_expiry'] . '</td>';
                $output .= '<td class="td">' . $lic['license_key'] .'</td>';
				$output .= '<td class="td">' . $html . '</td>';

				if(strtotime($lic['date_expiry']) > time()) {

                    $bs4['class'] = LicProlongation::I()->bs4 ? 'btn btn-primary' : 'button';
                    $bs4['icon']  = LicProlongation::I()->bs4 ? '<i class="las la-cloud-download-alt"></i>' : '';

					$output .= '<td class="td"><a href="' . $lic['download'] . '" 
					target="_blank" class="'.$bs4['class'].' download">' . $bs4['icon'] . $lic['package_slug'] . '</a></td>';
				} else {
					$output .= '<td class="td">' . __( 'Licence has expired', 'wc-pus' ) . '</td>';
				}
                $output .= '</tr>';
                $output .= '<tr><td colspan="2">' . LicProlongation::I()->render_renewal_checkout_link($lic) . '</td></tr>';
            }
            $output .= '</table>';
        }

        echo apply_filters('wc_pus_table', $output ?? '', $licenses, $order);
    }

    /**
     * Email
     * @param WC_Order $order
     * @param bool $is_admin_email
     */
    public function email_content( WC_Order $order, $is_admin_email)
    {
        if ('wc-completed' === $order->get_status() || 'completed' === $order->get_status() ) {
            $output = '';

            // Check if licenses were generated
            $licenses = get_post_meta($order->get_id(), LicOrder::key, true);

            if ($licenses && count($licenses) != 0) {
                $output = '<h3>' . __('Your Licenses', 'wc-pus') . ':</h3><table><tr>
				<th class="td">' . __('Item', 'wc-pus') . '</th>
				<th class="td">' . __('License', 'wc-pus') . '</th>
				<th class="td">' . __('Expiry', 'wc-pus') . '</th>
				</tr>';
                foreach ($licenses as $license) {
                    $output .= '<tr>';
                    if (isset($license['item']) && isset($license['key'])) {
                        if ($output) {
                            $output .= '<br />';
                        }
                        $output .= '<td class="td">' . $license['item'] . '</td>';
                        $output .= '<td class="td">' . $license['key'] . '</td>';
	                    $output .= '<td class="td">' . $license['expires'] . '</td>';
                    } else {
                        // $output .= 'No item and key assigned';
                    }
                    $output .= '</tr>';
                }
                $output .= '</table>';
            } else {
                // $output .= 'No License Generated';
            }

            echo $output;
        }
    }


	/**
	 * Render link to download file
	 *
	 * @param array $lic
	 * @return string
	 */
	private function generate_download_link(array $lic){
		$args = [
			'uid'          => get_current_user_id(),
			'package_slug' => $lic['package_slug'],
			'order_id'     => $lic['txn_id'],
			'lic_id'       => $lic['id']
		];
		return get_site_url() . '?' . http_build_query( $args );
	}

	/**
	 * Get file by link
	 *
	 * @return void
	 */
	public static function download_package(){

		$user_id = absint($_GET['uid']);
		$package_slug = $_GET['package_slug'];
		$order_id = (absint($_GET['order_id']));
		$lic_id = (absint($_GET['lic_id']));

		if ( empty( $user_id ) ) { // WPCS: input var ok, CSRF ok.
			self::download_error( __( 'Invalid download link.', 'woocommerce' ) );
		}

		if ( ! is_user_logged_in() ) {
			self::download_error( __( 'You must be logged in to download files.', 'woocommerce' ) . ' <a href="' . esc_url( wp_login_url( wc_get_page_permalink( 'myaccount' ) ) ) . '" class="wc-forward">' . __( 'Login', 'woocommerce' ) . '</a>', __( 'Log in to Download Files', 'woocommerce' ), 403 );
		} elseif ( get_current_user_id() !== $user_id ){
			self::download_error( __( 'This is not your download link.', 'woocommerce' ), '', 403 );
		}

		$lic = (new WPPUS_License_Server())->read_license(['id' => $lic_id]);
		if ( strtotime('midnight', time()) > strtotime($lic->date_expiry)) {
			self::download_error( __( 'Sorry, this download has expired', 'woocommerce' ), '', 403 );
		}

		wppus_download_local_package($package_slug);
	}


	/**
	 * Die with an error message if the download fails.
	 *
	 * @param string  $message Error message.
	 * @param string  $title   Error title.
	 * @param integer $status  Error status.
	 */
	private static function download_error( $message, $title = '', $status = 404 ) {
		/*
		 * Since we will now render a message instead of serving a download, we should unwind some of the previously set
		 * headers.
		 */
		if ( headers_sent() ) {
			wc_get_logger()->log( 'warning', __( 'Headers already sent when generating download error message.', 'woocommerce' ) );
		} else {
			header( 'Content-Type: ' . get_option( 'html_type' ) . '; charset=' . get_option( 'blog_charset' ) );
			header_remove( 'Content-Description;' );
			header_remove( 'Content-Disposition' );
			header_remove( 'Content-Transfer-Encoding' );
		}

		if ( ! strstr( $message, '<a ' ) ) {
			$message .= ' <a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" class="wc-forward">' . esc_html__( 'Go to shop', 'woocommerce' ) . '</a>';
		}
		wp_die( $message, $title, array( 'response' => $status ) ); // WPCS: XSS ok.
	}
}
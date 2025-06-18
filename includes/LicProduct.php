<?php
/**
 * Product Settings
 * Настройка привязки товара к теме/плагину
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

final class LicProduct
{
    public static $section_id = 'products';

    public static $licensing_enabled    = '_wc_slm_licensing_enabled';
    public static $sites_allowed        = '_wc_slm_sites_allowed';
    public static $renewal_period       = '_wc_slm_licensing_renewal_period';
    public static $type                 = '_wc_slm_type';
    public static $slug                 = '_wc_slm_slug';

    public function __construct(){

    }

    public function add_actions(){
        // Admin product settings
        if(is_admin()) {
            add_action('woocommerce_product_options_general_product_data', [$this, 'show_options']);
            add_action('woocommerce_process_product_meta', [$this, 'save_product_meta'], 10, 1);
        }
    }

    /**
     * Show options values from post meta
     */
    public function show_options() {
        global $product_object;

        $product = $product_object;
        if(!$product){
            return new WP_Error('error', 'Product not found.');
        }

        $licensing_enabled = (bool)$product->get_meta(self::$licensing_enabled);
        $sites_allowed = esc_attr($product->get_meta( self::$sites_allowed));
        $licensing_renewal_period = esc_attr($product->get_meta(self::$renewal_period));
        $product_type = esc_attr($product->get_meta(self::$type));
        $product_slug = esc_attr($product->get_meta( self::$slug));

        $display = $licensing_enabled ? '' : ' style="display:none;"';
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function($) {
                $( "#licensing_enabled" ).on( "click",function() {
                    $( ".variable-toggled-hide" ).toggle();
                    $( ".toggled-hide" ).toggle();
                });
            });
        </script>

        <p class="form-field">
            <input type="checkbox" name="licensing_enabled" id="licensing_enabled" value="1" <?php echo checked(true, $licensing_enabled, false); ?> />
            <label for="licensing_enabled"><?php _e('Enable licensing for this download.', 'wc-pus');?></label>
        </p>

        <div <?php echo $display; ?> class="toggled-hide">
            <p class="form-field">
                <label for="licensing_renewal_period">
                    <?php _e('license renewal period(yearly). Enter 0 for lifetime.', 'wc-pus');?>
                </label>
                <input type="number" name="licensing_renewal_period" id="licensing_renewal_period" value="<?php echo (int) $licensing_renewal_period; ?>"  />
            </p>
            <p class="form-field">
                <label for="sites_allowed">
                    <?php _e('How many sites can be activated trough a single license key?', 'wc-pus');?>
                </label>
                <input type="number" name="sites_allowed" class="small-text" value="<?php echo (int) $sites_allowed; ?>" />
            </p>
            <p class="form-field">
                <label for="type"><?php _e('Product type (plugin or theme).', 'wc-pus');?></label>
                <select name="type">
                    <option <?php echo $product_type == 'plugin' ? 'selected': ''?> >plugin</option>
                    <option <?php echo $product_type == 'theme'  ? 'selected': ''?> >theme</option>
                </select>
            </p>
            <p class="form-field">
                <label for="slug"><?php _e('Product slug.', 'wc-pus');?></label>
                <input type="text" name="slug" class="small-text" value="<?php echo $product_slug; ?>" />
            </p>
        </div>
        <?php
    }

    /**
     * Save options value
     *
     * @param int $post_id
     */
    public function save_product_meta(int $post_id) {

        $properties = [
            'licensing_enabled'        => self::$licensing_enabled,
            'sites_allowed'            => self::$sites_allowed,
            'licensing_renewal_period' => self::$renewal_period,
            'type'                     => self::$type,
            'slug'                     => self::$slug
        ];
        $product = wc_get_product($post_id);
        foreach ($properties as $key => $name) {
            if (isset($_POST[$key])) {
                $value = ($key === 'sites_allowed' && (int)$_POST[$key] <= 0) ? 1 : esc_html($_POST[$key]);
                $product->update_meta_data($name, $value);
            }
        }
        $product->save();
    }

    /**
     * @param $product_id
     * @return bool
     */
    public static function get_licensing_enabled($product_id){
        $product = wc_get_product($product_id);
         return boolval($product->get_meta( self::$licensing_enabled));
    }

    /**
     * @param $product_id
     * @return int
     */
    public static function get_sites_allowed($product_id){
        $product = wc_get_product($product_id);
        return intval($product->get_meta( self::$sites_allowed));
    }

    /**
     * @param $product_id
     * @return int
     */
    public static function get_renewal_period($product_id){
        $product = wc_get_product($product_id);
        return intval($product->get_meta( self::$renewal_period,));
    }

    /**
     * @param $product_id
     * @return string
     */
    public static function get_type($product_id){
        $product = wc_get_product($product_id);
        return (string)$product->get_meta(self::$type, true);
    }

    /**
     * @param $product_id
     * @return string
     */
    public static function get_slug($product_id){
        $product = wc_get_product($product_id);
        return (string)$product->get_meta( self::$slug, true);
    }
}
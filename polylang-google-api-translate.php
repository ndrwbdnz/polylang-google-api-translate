<?php
/**
 * Plugin Name: Polylang Google API Translate
 * Plugin URI:
 * Description: Translate polylang using Google Cloud Translate API. Compatible with Polylang, Polylang pro and Polylang Woocommerce
 * Version: 1.0
 * Author: Andrzej Bednorz
 * Author URI:
 * License: GPL3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PAT_translate_class' ) ) :

class PAT_translate_class{

    protected static $_instance;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    private $pat_api_key, $pat_strings_to_exclude,
            $pat_meta_to_exclude, $pat_meta_to_translate,
            $pat_taxonomies_to_exclude, $pat_taxonomies_to_translate,
            $pat_batch_size;

    private $settings_panel;

    public function __construct(){

		if ( ! ( $this->pat_is_plugin_active_or_multisite( 'polylang/polylang.php' ) || $this->pat_is_plugin_active_or_multisite( 'polylang-pro/polylang.php' ) ) ) {
			return;
		}

        //get settings values from database
        $this->pat_get_options();

        //load library to manage settings
        require __DIR__ . '/vendor/autoload.php';
        $this->settings_panel  = new \TDP\OptionsKit( 'pat' );
        $this->settings_panel->set_page_title( __( 'Polylang Google API Translate Settings' ) );

        add_filter( 'pat_menu', array( $this, 'pat_setup_menu' ));
        add_filter( 'pat_settings_tabs', array( $this, 'pat_register_settings_tabs' ));
        add_filter( 'pat_registered_settings', array( $this, 'pat_register_settings' ));
        //add_filter( 'rest_request_after_callbacks', array($this, 'pat_get_options'));
        //this has to be fired every time options are udpated - the page is not reloaded
        add_action( 'update_option_pat_settings', array($this, 'pat_get_options'), 10);
        
        //enqueue scripts
        add_action('admin_enqueue_scripts', array( $this, 'pat_admin_enqueue_scripts'), 10);
        add_action('admin_print_styles', array( $this, 'pat_admin_enqueue_styles') );

        //add user interface functions (links are created in js script)
        add_action('current_screen', array( $this, 'pat_set_bulk_actions_hooks' ), 10, 1);
        add_action('admin_post_pat_auto_translate', array( $this, 'pat_auto_translate_handler' ));
        add_action('admin_notices', array( $this, 'pat_auto_translate_notice'));
        add_action('admin_footer', array( $this, 'pat_footer'));

        //ajax handlers
        add_action( 'wp_ajax_pat_auto_translate', array($this, 'pat_auto_translate_ajax_handler') );
        add_action( 'wp_ajax_pat_string_translate', array($this, 'pat_string_translate') );
        
    }

 // User interface
    //auto-translate icons are set in the java script
    //below bulk actions are set

    function pat_set_bulk_actions_hooks($current_screen){
        if ( !pll_is_translated_post_type( $current_screen->post_type) || ( array_key_exists( 'post_status', $_GET ) && 'trash' === $_GET['post_status'] ) ) {
			return;
		}
        add_filter( "bulk_actions-{$current_screen->id}", array( $this, 'pat_add_bulk_action' ) );
        add_action( "handle_bulk_actions-{$current_screen->id}", array( $this, 'pat_handle_bulk_action' ), 10, 3 );
    }

    function pat_add_bulk_action($actions) {
        $actions['pat_link_translations'] = 'Re-link Translations';
        $actions['pat_mass_translate'] = 'Auto-translate selected';
        return $actions;
    }
    

 // Settings ---------------------------------------------------------------------------------------------------------------
    
    function pat_setup_menu( $menu ) {
        // These defaults can be customized
        // $menu['parent'] = 'options-general.php';
        // $menu['menu_title'] = 'Settings Panel';
        // $menu['capability'] = 'manage_options';
        
        $menu['page_title'] = __( 'Polylang Auto Translate' );
        $menu['menu_title'] = $menu['page_title'];

        return $menu;
    }

    function pat_register_settings_tabs( $tabs ) {
        return array(
            'general' => __( 'General' ),
            //'docs' => __( 'Documentation' )
        );
    }

    function pat_register_settings( $settings ) {
        $settings = array(
            'general' => array(
                array(
                    'id'   => 'pat_api_key',
                    'name' => __( 'Google Translate API key' ),
                    'desc' => __( 'Add your API key to get started' ),
                    'type' => 'text'
                ),
                array(
                    'id'   => 'pat_strings_to_exclude',
                    'name' => __( 'Exclude strings from automatic translation' ),
                    'desc' => __( 'Each string in a new line' ),
                    'type' => 'textarea'
                ),
                array(
                    'id'   => 'pat_meta_to_exclude',
                    'name' => __( 'Meta values to not copy or translate to translated post' ),
                    'desc' => __( 'These metas will not be present in the translated post. Woocommerce _product_attributes meta is always excluded because it is handled by the plugin separately.' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->pat_get_metas(),
                ),
                array(
                    'id'   => 'pat_meta_to_translate',
                    'name' => __( 'Meta values to translate' ),
                    'desc' => __( 'These metas will be translated. Metas that are neither translated nor excluded will be copied to the translated post, without translating them.' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->pat_get_metas(),
                ),
                array(
                    'id'   => 'pat_taxonomies_to_exclude',
                    'name' => __( 'Taxonomies to not copy or translate to translated post' ),
                    'desc' => __( 'These taxonomies will not be present in the translated post. Polylang taxonomies such as language, term_language, term_translation and post_translations as well as woocommerce taxonomy product_type are already excluded because they are handled by the plugin separately.' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->pat_get_taxonomies(),
                ),
                array(
                    'id'   => 'pat_taxonomies_to_translate',
                    'name' => __( 'Taxonomies to translate' ),
                    'desc' => __( 'These taxonomies will be translated. Taxonomies that are neither translated nor excluded will be copied to the translated post, without translating them.' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->pat_get_taxonomies(),
                ),
                array(
                    'id'   => 'pat_batch_size',
                    'name' => __( 'Batch size for mass-translating posts' ),
                    'desc' => __( 'Default 10. This is how many posts will be translated in one batch if you select posts and choose Auto-translate mass action. Keep in mind that if you have 5 languages per post, and set batch size to 10, it will be 50 translations per batch. Tweak this number depending on the number of languages and post size. If server timeouts occur during mass translations, lower the batch size.' ),
                    'type' => 'text'
                ),

            ),
            // 'docs' => array(

            // ),
        );
    
        return $settings;
    }
    
    function pat_get_options(){

        $option_table = get_option('pat_settings');
        $option_table = is_array($option_table)? $option_table : array();

        $this->pat_strings_to_exclude = array_key_exists('pat_strings_to_exclude', $option_table)? explode(PHP_EOL, $option_table['pat_strings_to_exclude']) : "";
        $this->pat_meta_to_exclude = array_key_exists('pat_meta_to_exclude', $option_table)? $option_table['pat_meta_to_exclude'] : array();
        $this->pat_meta_to_translate = array_key_exists('pat_meta_to_translate', $option_table)? $option_table['pat_meta_to_translate'] : array();
        $this->pat_taxonomies_to_exclude = array_key_exists('pat_taxonomies_to_exclude', $option_table)? $option_table['pat_taxonomies_to_exclude'] : array();
        $this->pat_taxonomies_to_translate = array_key_exists('pat_taxonomies_to_translate', $option_table)? $option_table['pat_taxonomies_to_translate'] : array();
        $this->pat_api_key = array_key_exists('pat_api_key', $option_table)? $option_table['pat_api_key'] : '';
        $this->pat_batch_size = array_key_exists('pat_batch_size', $option_table)? $option_table['pat_batch_size'] : 10;

    }

    private function pat_get_metas(){
        //if we are on the settings page
        if ($_GET['page'] === 'pat-settings'){
            global $wpdb;
            $p = $wpdb->get_blog_prefix();
            $query = $wpdb->prepare( "SELECT DISTINCT pm.meta_key as value, pm.meta_key as label  FROM {$p}postmeta pm
                                    LEFT JOIN {$p}posts p ON p.ID = pm.post_id 
                                    WHERE p.post_type in ('post', 'page', 'product')
                                    AND pm.meta_key not in ('_product_attributes')
                                    ORDER BY pm.meta_key");
            $result = $wpdb->get_results($query);    
        } else {
            //else we don't need this data now
            $result = array();
        }
        
        return $result;
        
    }

    private function pat_get_taxonomies(){
        //if we are on the settings page
        if ($_GET['page'] === 'pat-settings'){
            global $wpdb;
            $p = $wpdb->get_blog_prefix();
            $query = $wpdb->prepare( "SELECT DISTINCT tt.taxonomy as value, tt.taxonomy as label FROM {$p}term_taxonomy tt
                                        LEFT JOIN {$p}term_relationships tr ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                        LEFT JOIN {$p}posts p ON p.ID = tr.object_id 
                                        WHERE p.post_type in ('post', 'page', 'product')
                                        AND tt.taxonomy not in ('language', 'term_language', 'term_translations', 'post_translations', 'product_type')
                                        ORDER BY tt.taxonomy");
            $result = $wpdb->get_results($query);
        } else {
            $resutl = array();
        }
        
        return $result;
    }
    
    function pat_footer(){
        echo '<div id="pat_thickbox" style="display:none;">
                <p>Processing Ajax. Please dont close this site untill the process is finished.</p>
                <div id="pat_tb_messages">
                </div>
                <div class="pat_tb_bottom">
                    <span class="pat_spinner spinner"></span>
                    <button id="TB_closeWindowButton" class="button-primary" disabled>Close</button>
                </div>
            </div>';
    }

 // Scripts  ---------------------------------------------------------------------------------------------------------------

    function pat_admin_enqueue_scripts(){
        //if this is post, page or product edit table page
        if ( function_exists('get_current_screen')) {
            if ( in_array(get_current_screen()->post_type, array('post', 'page', 'product')) || 
                (array_key_exists( 'page', $_GET ) && 'mlang_strings' === $_GET['page'] )
            ){
                add_thickbox();
                wp_enqueue_script( 'pat-js', plugin_dir_url( __FILE__ ) . 'assets/pat.js', array('jquery'), null, true );
                wp_localize_script(
                    'pat-js',
                    'pat_js_obj',
                    array(
                        'ajaxurl' => admin_url( 'admin-ajax.php' ),
                        'nonce' => wp_create_nonce('pat-ajax-nonce'),
                        'pat_batch_size' => $this->pat_batch_size
                    )
                );
            }
        }
    }

    function pat_admin_enqueue_styles(){
        if ( function_exists('get_current_screen')) {
            if ( in_array(get_current_screen()->post_type, array('post', 'page', 'product')) || 
                (array_key_exists( 'page', $_GET ) && 'mlang_strings' === $_GET['page'] )
            ){
                wp_enqueue_style( 'pat-css',  plugin_dir_url( __FILE__ ) . 'assets/pat.css');
            }
        }
    }

 // Admin actions ---------------------------------------------------------------------------------------------------------------
    public function pat_auto_translate_handler() {

        //get parameters from the request URL
        $message = '';
        $location = ''; 

        if (!check_admin_referer( 'new-post-translation' )){
            $message = 'The link has expired. Please try again.';
            $post_type = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : '';
            $ref_path = isset($_REQUEST['ref_path']) ? $_REQUEST['ref_path'] : 'edit.php';
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'pat_translation_error' => 1,
                'pat_translation_msg' => urlencode($message),
                'post_status' => 'all'
            ), $ref_path );        //strtok($_SERVER["REQUEST_URI"], '?');
        } else {

            //request is either from http request or from ajax call
            if( (!isset($_REQUEST['from_post']) || !isset($_REQUEST['from_tag']) ) && !isset($_REQUEST['new_lang'])){
                $message = 'Cannot translate: post or language not provided';
                $translated_post_id = FALSE;
                $post_type = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : '';
                $location = add_query_arg( array(
                    'post_type' => $post_type,
                    'pat_translation_error' => 1,
                    'pat_translation_msg' => urlencode($message),
                    'post_status' => 'all'
                ), 'edit.php' );

            } else {
                $from_post = isset($_REQUEST['from_post']) ? $_REQUEST['from_post'] : 0;
                $post_type = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : '';
                $target_lang = $_REQUEST['new_lang'];
                $edit_post = isset($_REQUEST['edit_post']) ? $_REQUEST['edit_post'] : 0;            //when post is edited
                $taxonomy = isset($_REQUEST['taxonomy']) ? $_REQUEST['taxonomy'] : '';              
                $from_tag = isset($_REQUEST['from_tag']) ? $_REQUEST['from_tag'] : 0;

                //depending on the above paramters do different translations
                
                $message = '';
                $location = '';
                if ($from_post != 0 && in_array($post_type, array('post', 'page'))){
                    $translated_post_id = $this->pat_translate_post($from_post, $target_lang, $edit_post, $post_type, $message, $location);
                } elseif ($from_post != 0 && $post_type == 'product') {
                    $translated_post_id = $this->pat_translate_product($from_post, $target_lang, $edit_post, $post_type, $message, $location);
                } elseif ($taxonomy != '' && $from_tag != 0) {
                    $translated_term = $this->pat_translate_taxonomy($target_lang, $from_tag, $taxonomy, $post_type, $message, $location);
                }

            }

        }

        wp_redirect( admin_url( $location ) );
        exit();

    }

    public function pat_auto_translate_ajax_handler() {

        $response = new WP_Ajax_Response();

        if( wp_doing_ajax() ){
            $nonce = $_POST['nonce'];
            if ( ! wp_verify_nonce( $nonce, 'pat-ajax-nonce' ) ) {
                die( 'Nonce value cannot be verified.' );
            }
        }

        $message = '';
        $location ='';

        //process the parameter array
        $parametrs = $_POST["parameter_array"];
        $parametrs = json_decode(stripslashes($parametrs), true);
        $post_type = isset($_POST['post_type']) ? $_POST['post_type'] : '';
        $taxonomy = isset($_POST['taxonomy']) ? $_POST['taxonomy'] : '';
        $unique_ids = array();      //these ids will be collected in the first loop and then used in the second loop to fetch single rows for displaying the results
        //main lopp that processes each batch (as well as a single translation)
        //each parameters array entry is one translation
        //first loop is to make translations, get result messages and collect all post ids
        foreach ($parametrs as $t){             //$t as one single translation. Short name to shorten referring to parameters for each translation
            
            $message = '';      //reset message for the next translation

            if (in_array($post_type, array('post', 'page', 'product')) && $t['from_post'] != 0 && $taxonomy == ''){

                if ($post_type == 'post' || $post_type == 'page'){
                    $translated_post_id = $this->pat_translate_post($t['from_post'], $t['new_lang'], '', $post_type, $message, $location);     //location is not used in ajax
                } elseif ($post_type == 'product'){
                    $translated_post_id = $this->pat_translate_product($t['from_post'], $t['new_lang'], '', $post_type, $message, $location);
                }
                $row_id = $t['from_post'];       //this is to be able to assign message to proper row in the results table
                if ($translated_post_id) {         //translation was successful
                    $post_translations = pll_get_post_translations($t['from_post']);
                    //we treat post id as array key and hence it will be unique, the value is the original post id - we use it later for inserting values in proper places in the table
                    $post_translations = array_fill_keys(array_values($post_translations), $row_id);
                    $unique_ids = $unique_ids + $post_translations;
                }

            } elseif ($taxonomy != '' && $t['from_tag'] != 0){
                $translated_term = $this->pat_translate_taxonomy($t['new_lang'], $t['from_tag'], $taxonomy, $post_type, $message, $location);
                $row_id = $t['from_tag'];
                if ($translated_term){       //translation was successful
                    //it is assumed that there will be no case where tags are mixed with posts on the same translation run
                    $term_translations = pll_get_term_translations($t['from_tag']);
                    //we treat term id as array key and hence it will be unique, the value is the original term - we use it later for inserting values in proper places in the table
                    $term_translations = array_fill_keys(array_values($term_translations), $row_id);
                    $unique_ids = $unique_ids + $term_translations;
                }
            }
            
            $response->add( array( 'what' => 'message', 'data' => $message ));
        }
        
        //once we have translations ready, it's time to get updated rows
        if ( in_array($post_type, array('post', 'page', 'product')) && $taxonomy == ''){
            //get proper list table depending on post type
                $screen = WP_Screen::get( 'edit' );
                if ($post_type == 'post'){
                } elseif ($post_type == 'page'){
                    $screen->post_type = 'page';
                } elseif ($post_type == 'product'){
                    //product
                    $screen->post_type = 'product';
                    //this is all just to get filters from wc_admin_list_table_products and abstract_wc_admin_list_table hooked up to correct WP_list_table functions so that proper columns can be displayed
                    include_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/class-wc-admin-meta-boxes.php';
                    include_once WP_PLUGIN_DIR . '/woocommerce/includes/admin/list-tables/class-wc-admin-list-table-products.php';
                    $wc_list_table = new WC_Admin_List_Table_Products();
                }
                $list_table = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => $screen ) );
            
            //get single row for each post and attach it to ajax response
            foreach ($unique_ids as $post_id => $from_post){
                $level = is_post_type_hierarchical( $post_type ) ? count( get_ancestors( $post_id, $post_type ) ) : 0;
                if ( $post = get_post( $post_id ) ) {
                    ob_start();
                    $list_table->single_row( $post, $level );
                    $data = ob_get_clean();
                    $row_id = "#post-" . $post_id;
                    $ref_row_id = "#post-" . $from_post;
                    $response->add( array( 'what' => 'row', 'data' => $data,
                            'supplemental' => array( 'row_id' => $row_id, 'ref_row_id' => $ref_row_id) ));
                }
            }

        } elseif ($taxonomy != ''){
            $GLOBALS['taxonomy'] = $taxonomy;       //don't know why global taxonomy may be different from taxonomy. This in turn messes up fetching of single row language columns
            $screen = WP_Screen::get('edit-tags');
            $screen->post_type = 'product';
            $screen->taxonomy = 'product_cat';
            $list_table = _get_list_table( 'WP_Terms_List_Table', array( 'screen' => $screen ) );

            foreach ($unique_ids as $term_id => $from_term){
                $level = is_taxonomy_hierarchical( $taxonomy ) ? count( get_ancestors( $term_id, $taxonomy, 'taxonomy' ) ) : 0;
                if ( $term = get_term( $term_id, $taxonomy ) ) {
                    ob_start();
                    $list_table->single_row( $term, $level );
                    $data = ob_get_clean();
                    $row_id = "#tag-" . $term_id;
                    $ref_row_id = "#tag-" . $from_term;
                    $response->add( array( 'what' => 'row', 'data' => $data,
                        'supplemental' => array( 'row_id' => $row_id, 'ref_row_id' => $ref_row_id )) );
                }
            }

        }

        $response->send();
    }
    
    public function pat_auto_translate_notice() {

        global $pagenow; //, $typenow;
        //in_array($typenow, array('post', 'page', 'product'))
        if( in_array($pagenow, array('edit.php', 'edit-tags.php')) && isset( $_REQUEST['pat_translation_msg'] )){
            if (isset( $_REQUEST['pat_translation_error'] )){
                echo "<div class=\"notice notice-error is-dismissible\"><p>{$_REQUEST['pat_translation_msg']}</p></div>";
            } else {
                echo "<div class=\"notice notice-success is-dismissible\"><p>{$_REQUEST['pat_translation_msg']}</p></div>";
            }
        }

    }

    public function pat_handle_bulk_action( $location, $action, $items ) {

        if ($action == 'pat_link_translations' || $action == 'pat_mass_translate'){

            global $pagenow;
            $page = '';
            if ($pagenow == 'edit.php'){
                $page = "post";
            } elseif($pagenow == 'edit-tags.php'){
                $page = "tag";
            } else {
                $error_msg = 'This item type cannot be processed.';
                $location = add_query_arg( array(
                    'pat_translation_error' => 1,
                    'pat_translation_msg' => urlencode($error_msg)
                ), $location);
                return $location;
            }

            if ($action == 'pat_mass_translate'){
                $error_msg = 'Bulk translation can only be done via Ajax. Please check that your browser does not block javascript and that there are no errors in the javascript console.';
                $location = add_query_arg( array(
                    'pat_translation_error' => 1,
                    'pat_translation_msg' => urlencode($error_msg)
                ), $location);
                return $location;

            } elseif( empty($items) ){
                $error_msg = 'Please select items first';
                $location = add_query_arg( array(
                    'pat_translation_error' => 1,
                    'pat_translation_msg' => urlencode($error_msg)
                ), $location);
                return $location;
                
            } elseif ($action == 'pat_link_translations') {
                //the simplest approach is to first break all translation links of each item
                $item_langs = array();              //this is for checking if there is more than one item with the same language
                $new_translation_links = array();   //this is for keeping new translation links

                foreach ($items as $item){
                    $item_lang = ($page == 'post')? pll_get_post_language($item) : pll_get_term_language($item);

                    if (key_exists($item_lang, $item_langs)){                       //if another item with the same language was aready processed - exit with error message
                        $error_msg = 'More than one items with the language '.$item_lang.' selected. Please select only items with different languages and try again.';
                        $location = add_query_arg( array(
                            'pat_translation_error' => 1,
                            'pat_translation_msg' => urlencode($error_msg)
                        ), $location);
                        return $location;
                    } else {
                        $item_langs[$item_lang] = $item;
                    }

                    //clean item links
                    $item_translations = ($page == 'post')? pll_get_post_translations($item) : pll_get_term_translations($item);        //get existing post translations
                    $item_translations[$item_lang] = "";                                                                                //remove post id for given language
                    ($page == 'post')? pll_save_post_translations($item_translations) : pll_save_term_translations($item_translations); //save new post translations

                    $new_translation_links[$item_lang] = $item;
                }

                ($page == 'post')? pll_save_post_translations($new_translation_links) : pll_save_term_translations($new_translation_links); //save new post translations
                
            }
            
        }

        return $location;
	}

    public function pat_string_translate (){
        $nonce = $_POST['nonce'];

        if ( ! wp_verify_nonce( $nonce, 'pat-ajax-nonce' ) ) {
            die( 'Nonce value cannot be verified.' );
        }
     
        $translated_text = '';

        // The $_REQUEST contains all the data sent via ajax
        if ( isset($_REQUEST) ) {
            $source_lang = ''; //pll_default_language();        //empty source lang forces google to detect language
            $target_lang = $_REQUEST['to_lang'];
            $text_to_translate = $_REQUEST['string_to_translate'];

            $translated_text = $this->pat_translate_text($source_lang, $target_lang, $text_to_translate, $this->pat_strings_to_exclude);
        }

        echo $translated_text;
        
        die();
    }

 // Main translation functions - post (and page) and product ---------------------------------------------------------------------------------------------------------------   
    
    private function pat_translate_post($post_id, $target_lang, $edit_post, $post_type, &$message, &$location){

        try{
            $source_lang = pll_get_post_language($post_id);

            $post_to_translate = get_post($post_id);
            $post_title = get_the_title($post_to_translate);
            $message .= "Translation from $source_lang $post_title (id: $post_id) to $target_lang ";
            
            $translated_content = $this->pat_translate_text($source_lang, $target_lang, $post_to_translate->post_content, $this->pat_strings_to_exclude);
            $translated_excerpt = $this->pat_translate_text($source_lang, $target_lang, $post_to_translate->post_excerpt, $this->pat_strings_to_exclude);
            $translated_title = $this->pat_translate_text($source_lang, $target_lang, $post_to_translate->post_title, $this->pat_strings_to_exclude);
            $translated_metas = $this->pat_translate_metas($source_lang, $target_lang, get_post_meta($post_id));
            $translated_taxonomies = $this->pat_translate_post_taxonomies($source_lang, $target_lang, get_post_taxonomies($post_id), $post_id);
            $translated_categories = array_key_exists('category', $translated_taxonomies) ? $translated_taxonomies['category'] : array();
            $translated_tags = array_key_exists('post_tag', $translated_taxonomies) ? $translated_taxonomies['post_tag'] : array();

            //after all translations have been done - instert the post
            $translated_post_id = wp_insert_post(array(
                'post_author' => $post_to_translate->post_author,
                'post_date' => $post_to_translate->post_date,
                'post_date_gmt' => $post_to_translate->post_date_gmt,
                'post_content' => $translated_content,
                'post_title' => html_entity_decode($translated_title, ENT_QUOTES),
                'post_excerpt' => html_entity_decode($translated_excerpt, ENT_QUOTES),
                'post_status' => 'draft',
                'post_type' => $post_to_translate->post_type,
                'comment_status' => $post_to_translate->comment_status,
                'ping_status' => $post_to_translate->ping_status,
                'post_password' => $post_to_translate->post_password,
                'post_modified' => $post_to_translate->post_modified,
                'post_modified_gmt' => $post_to_translate->post_modified_gmt,
                'post_parent' => $post_to_translate->post_parent,
                'menu_order' => $post_to_translate->menu_order,
                'post_category' => $translated_categories,
                'tags_input' => $translated_tags,
                'tax_input' => $translated_taxonomies,
                'meta_input' => $translated_metas
            ));

            //join the translated post with the original post
            pll_set_post_language($translated_post_id, $target_lang);
            $post_translations = pll_get_post_translations($post_id);       //get existing post translations
            $post_translations[$target_lang] = $translated_post_id;         //set post id for the translated language
            pll_save_post_translations($post_translations);                 //save new post translations

            $translated_post_title = get_the_title($translated_post_id);
            $message .= "$translated_post_title (id: $translated_post_id). Success.";

            if($edit_post == 1){                                                          //when post is edited
            
                $location = add_query_arg( array(
                    'post' => $translated_post_id,
                    'action' => 'edit',
                    'pat_translation_msg' => urlencode($message)
                ), 'post.php' );

            } else {

                $location = add_query_arg( array(
                    'post_type' => $post_type,
                    'pat_translation_msg' => urlencode($message),
                    'post_status' => 'all'
                ), 'edit.php' );
            }

            return $translated_post_id;

        } catch (Exception $e) {
            $message .= "Error was thrown: ".$e->getMessage();
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'pat_translation_error' => 1,
                'pat_translation_msg' => urlencode($message),
                'post_status' => 'all'
            ), 'edit.php' );
            return FALSE;
        }
    }
    
    //copy of the woocommerce function product_duplicate - with modifications
    //cannot use woocommerce duplicate_product function because polylang hooks into it and copies all product translations
    //this duplication is different - it is supposed to only copy the specific product (not all its translation products) and create a trasnlation product from it
    private function pat_translate_product($post_id, $target_lang, $edit_post, $post_type, &$message, &$location){
        
        try{
            $source_lang = pll_get_post_language($post_id);
            $product = wc_get_product( $post_id );
            $post_title = get_the_title($product);
            $message .= "Translation from $source_lang $post_title (id: $post_id) to $target_lang ";

            $meta_to_exclude = array_filter(
                apply_filters(
                    'woocommerce_duplicate_product_exclude_meta',
                    $this->pat_meta_to_exclude,
                    array_map(
                        function ( $datum ) {
                            return $datum->key;
                        },
                        $product->get_meta_data()
                    )
                )
            );
            
            //for the post we could first translate everything and then create a new post
            //for the product - we first clone the product object to incluide all it's details, and then we translate 
            $duplicate = clone $product;
            $duplicate->set_id( 0 );
            $duplicate->set_name($this->pat_translate_text($source_lang, $target_lang, $product->get_name()));
            $duplicate->set_description($this->pat_translate_text($source_lang, $target_lang, $product->get_description()));
            $duplicate->set_short_description($this->pat_translate_text($source_lang, $target_lang, $product->get_short_description()));
            $duplicate->set_purchase_note($this->pat_translate_text($source_lang, $target_lang, $product->get_purchase_note()));
            $duplicate->set_status( 'draft' );
            $duplicate->set_slug( '' );

            add_filter( 'wc_product_has_unique_sku', array($this, 'pat_disable_unique_sku'), PHP_INT_MAX );     //temporarily disable unique sku
            $duplicate->set_sku($product->get_sku( 'edit' ));

            //$duplicate->set_parent_id($product->get_parent_id());                 //in case of products there are no parent ids
            //$duplicate->set_total_sales( 0 );                                     //we want to keep sales count for the product translation - it is the same product
            //if ( '' !== $product->get_sku( 'edit' ) ) {
            //	$duplicate->set_sku( wc_product_generate_unique_sku( 0, $product->get_sku( 'edit' ) ) );        //we want to keep the same sku as the original product
            //}
            //$duplicate->set_date_created( null );                                                             //we keep date, ratings, reviews etc of the original product
            //$duplicate->set_rating_counts( 0 );
            //$duplicate->set_average_rating( 0 );
            //$duplicate->set_review_count( 0 );
    
            //meta data is copied when object is cloned, so we have to remove unwanted metas
            foreach ( $meta_to_exclude as $meta_key ) {
                $duplicate->delete_meta_data( $meta_key );
            }
            //once we removed unwanted metas, we now translate the remaining ones
            //first we get all metas
            $duplicate_metas = $duplicate->get_meta_data();
            //we loop through them
            foreach($duplicate_metas as $meta_object){
                $meta_data = $meta_object->get_data();              //these metas are in a strange table with each entry being a wc_meta_data object that contains current_data and data tables. We can get the meta data using a function
                $translated_meta = $this->pat_translate_metas($source_lang, $target_lang, array($meta_data['key'] => $meta_data['value']));     //then we extract from that result key and value to be able to send it to our translation function. We translate only one meta at a time, to avoid looping these values over and over
                if (!empty($translated_meta) && $translated_meta[$meta_data['key']] != $meta_data['value']){
                    $duplicate->update_meta_data($meta_data['key'], $translated_meta[$meta_data['key']]);           //finally we update the meta data
                }
            }

            $translated_taxonomies = $this->pat_translate_post_taxonomies($source_lang, $target_lang, get_post_taxonomies($post_id), $post_id);
            $duplicate->set_tag_ids($translated_taxonomies['product_tag']);
            $duplicate->set_category_ids($translated_taxonomies['product_cat']);

            //attributes meta field (_product_attributes meta key in the wp_postmeta table)
            //stores all the attributes for the product (size, weight, color etc) with their relevant paramters (for variations, visible in front end, etc)
            //the different values of the attributes (e.g. possible color values) are stored as taxonomies
            //each attribute has the options array inside with the stored ID of taxonomy term, that contains the value (e.g. color name)
            //we have translated all taxonomies already above
            //attributes structure stays the same as in the original product
            //the only thing that changes are taxonomy tag IDs
            $attributes = (array) $product->get_attributes();
            $translated_attributes = array();

            foreach( $attributes as $key => $attribute ){
                //we are looping only on attributes, not taxonomies
                //we are not interested here in taxonomies that are not used for attributes
                //i.e. we will not create new attribtes from them, as might have been the case in another scenario
                //https://stackoverflow.com/questions/53944532/auto-set-specific-attribute-term-value-to-purchased-products-on-woocommerce

                if( ! is_null( $translated_taxonomies[$attribute] )) {
                    $translated_attribute = $attribute;                                             //attribute is a term object (get_term($translated_term_id, $term->taxonomy))
                    $translated_attribute->set_options( $translated_taxonomies[$attribute] );       //here we assign translation
                    $translated_attributes[$key] = $translated_attribute;
                } else {
                    //if the attribute has not been translated (neither copied, nor translarted - i.e. it must have been excluded)
                    //then exclude it from attributes - i.e. do not copy it to $translated_attributes array
                }
            }

            $duplicate->set_attributes( $translated_attributes );

            // Append the new term in the product
            // if( ! has_term( $term_name, $taxonomy, $_product->get_id() ) ){
            //        wp_set_object_terms($_product->get_id(), $term_slug, $taxonomy, true );
            // }

            //check if there are translated products for cross sell and up sell.
            //If so - link them. If not - set these references empty
            $cross_sell_ids = $product->get_cross_sell_ids();
            $translated_cross_sell_id = array();
            foreach ($cross_sell_ids as $cross_sell_id){
                $translated_cross_sell_id = pll_get_post($cross_sell_id, $target_lang);
                if (!is_null($translated_cross_sell_id)){
                    array_push($translated_cross_sell_ids, $translated_cross_sell_id);
                }
            }
            $duplicate->set_cross_sell_ids($translated_cross_sell_ids);

            $up_sell_ids = $product->get_upsell_ids();
            $translated_up_sell_id = array();
            foreach ($up_sell_ids as $up_sell_id){
                $translated_up_sell_id = pll_get_post($up_sell_id, $target_lang);
                if (!is_null($translated_up_sell_id)){
                    array_push($translated_up_sell_ids, $translated_up_sell_id);
                }
            }
            $duplicate->set_upsell_ids($translated_up_sell_ids);

            // Save parent product.
            $duplicate->save();

            // Duplicate children of a variable product.
            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $child_id ) {
                    $child           = wc_get_product( $child_id );
                    $child_duplicate = clone $child;
                    $child_duplicate->set_parent_id( $duplicate->get_id() );
                    $child_duplicate->set_id( 0 );
                    //$child_duplicate->set_date_created( null );

                    // If we wait and let the insertion generate the slug, we will see extreme performance degradation
                    // in the case where a product is used as a template. Every time the template is duplicated, each
                    // variation will query every consecutive slug until it finds an empty one. To avoid this, we can
                    // optimize the generation ourselves, avoiding the issue altogether.
                    $this->pat_generate_unique_slug( $child_duplicate );

                    // if ( '' !== $child->get_sku( 'edit' ) ) {
                    // 	$child_duplicate->set_sku( wc_product_generate_unique_sku( 0, $child->get_sku( 'edit' ) ) );
                    // }
                    $child_duplicate->set_sku($child->get_sku( 'edit' ));
                    
                    //child metas are cloned during object cloning. We have to remove unwanted metas
                    foreach ( $meta_to_exclude as $meta_key ) {
                        $child_duplicate->delete_meta_data( $meta_key );
                    }
                    $child_duplicate_metas = $child_duplicate->get_meta_data();
                    //we loop through them
                    foreach($child_duplicate_metas as $meta_object){
                        $meta_data = $meta_object->get_data();              //these metas are in a strange table with each entry being a wc_meta_data object that contains current_data and data tables. We can get the meta data using a function
                        $translated_meta = $this->pat_translate_metas($source_lang, $target_lang, array($meta_data['key'] => $meta_data['value']));     //then we extract from that result key and value to be able to send it to our translation function. We translate only one meta at a time, to avoid looping these values over and over
                        if (!empty($translated_meta) && $translated_meta[$meta_data['key']] != $meta_data['value']){
                            $child_duplicate->update_meta_data($meta_data['key'], $translated_meta[$meta_data['key']]);           //finally we update the meta data
                        }
                    }

                    //do_action( 'woocommerce_product_duplicate_before_save', $child_duplicate, $child );
                    $child_duplicate->save();
                }

                // Get new object to reflect new children.
                $duplicate = wc_get_product( $duplicate->get_id() );
            }

            remove_filter( 'wc_product_has_unique_sku', array($this, 'pat_disable_unique_sku'));                //stop disabling unique sku 

            $translated_post_id = $duplicate->get_id();

            //join the translated post with the original post
            pll_set_post_language($translated_post_id, $target_lang);
            $post_translations = pll_get_post_translations($post_id);       //get existing post translations
            $post_translations[$target_lang] = $translated_post_id;         //set post id for the translated language
            pll_save_post_translations($post_translations);                 //save new post translations

            $translated_post_title = get_the_title($translated_post_id);
            $message .= "$translated_post_title (id: $translated_post_id). Success.";
            
            if($edit_post == 1){                                                          //when post is edited

                $location = add_query_arg( array(
                    'post' => $translated_post_id,
                    'action' => 'edit',
                    'pat_translation_pat_translate_taxonomymsg' => urlencode($message)
                ), 'post.php' );

            } else {

                $location = add_query_arg( array(
                    'post_type' => $post_type,
                    'pat_translation_msg' => urlencode($message),
                    'post_status' => 'all'
                ), 'edit.php' );
            }

            return $translated_post_id;

        } catch (Exception $e) {
            $message .= "Error was thrown: ".$e->getMessage();
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'pat_translation_error' => 1,
                'pat_translation_msg' => urlencode($message),
                'post_status' => 'all'
            ), 'edit.php' );
            return FALSE;
        }
        
    }

    function pat_disable_unique_sku(){
        return false;
    }

    private function pat_translate_taxonomy ($target_lang, $from_tag, $taxonomy, $post_type, &$message, &$location){
        try{
            $source_lang = pll_get_term_language($from_tag);
            
            $term = get_term($from_tag, $taxonomy);
            $message = "Translation from $source_lang $term->name (taxonomy id: $taxonomy) to $target_lang ";

            $translated_term = $this->pat_translate_terms($source_lang, $target_lang, $from_tag, $taxonomy, "translate");
            
            if ($translated_term){
                $term = get_term($translated_term, $taxonomy);
                $message .= "$term->name. Success.";
            } else {
                $message .= ". Error has occured. ";
            }

            //besides just transtaling terms, we also want to automatically update any translated posts that should have the translated terms linked to them
            $posts = get_posts( array(  'post_type' => 'any',
                                        'post_status' => array('publish', 'draft'),
                                        'numberposts' => -1,
                                        'tax_query' => array(array('taxonomy' => $taxonomy, 'terms' => $from_tag))
                                    )
                                );

            foreach ($posts as $post){                                          //take each post that has this tag
                $post_translations = pll_get_post_translations($post->ID);          //see if there are linked translated posts
                //for the translated post in the new language (if exixts)
                wp_set_object_terms($post_translations[$target_lang], $translated_term, $taxonomy, true);  //true means append (not replace) terms
            }

            $location = add_query_arg( array(
                'taxonomy' => $taxonomy,
                'post_type' => $post_type,
                'pat_translation_msg' => urlencode($message)
            ), 'edit-tags.php' );

            return $translated_term;

        } catch (Exception $e) {
            $message .= "Error was thrown: ".$e->getMessage();
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'pat_translation_error' => 1,
                'pat_translation_msg' => urlencode($message),
                'post_status' => 'all'
            ), 'edit-tags.php' );
            return FALSE;
        }

    }

 // Specialized translation sub-functions - metas, taxonomies and terms -------------------------------------------------------------------------------------
    //translate metas
    private function pat_translate_metas($source_lang, $target_lang, $post_metas){
        
        $translated_metas = array();

        foreach ($post_metas as $key => $meta_value) {
            //check if metas are to be assigned to the translated post.
            $meta_flag = $this->pat_meta_flag($key);
            if ( $meta_flag != "exclude"){
                //check if meta values are to be translated, if not - simply copy the values. Flag 1 means translate
                if ( $meta_flag == "translate"){
                    $translated_meta = $this->pat_translate_text($source_lang, $target_lang, $meta_value[0], $this->pat_strings_to_exclude);
                } else {
                    $translated_meta = $meta_value[0];
                }
                $translated_metas[$key] = $translated_meta;
            }
        }
        
        return $translated_metas;

    }

    //translate taxonomies
    private function pat_translate_post_taxonomies($source_lang, $target_lang, $post_taxonomies, $post_id){

        $translated_taxonomies = array();

        foreach ($post_taxonomies as $taxonomy){
            //check if taxonomy is not to be skipped
            $taxonomy_flag = $this->pat_taxonomy_flag($taxonomy);
            if ($taxonomy_flag != "exclude"){ 
                //get terms for the given taxonomy assigned to this post
                $terms = wp_get_post_terms($post_id, $taxonomy);

                $counter = 0;
                foreach ($terms as $term) {
                    //this function is called recursively to walk up the tree of terms and recreate the term tree in the target language (parent terms)
                    $translated_term_id = $this->pat_translate_terms($source_lang, $target_lang, $term, $taxonomy, $taxonomy_flag);
                    $translated_term = get_term($translated_term_id, $term->taxonomy);
                    $translated_taxonomies[$translated_term->taxonomy][$counter] = $translated_term->term_id;
                    $counter = $counter + 1;
                }
            }
        }
        return $translated_taxonomies;
    }
    
    //recursively translate terms
    private function pat_translate_terms($source_lang, $target_lang, $term, $term_taxonomy, $taxonomy_flag){
        
        //if term is term id
        if (is_numeric($term)){
            $term = get_term($term, $term_taxonomy);
        }
        
        //see if the term alraedy exists in the target language
        $translated_term_id = pll_get_term($term->term_id, $target_lang);
        
        if (!$translated_term_id) {

            //create the term in the target language. First check if it is to be translated.
            //either all terms from a given taxonomy are translated or none of them - the check is on the whole taxonomy level,  not on the level of individual term
            if($taxonomy_flag == "translate"){
                $term_name_translation = $this->pat_translate_text($source_lang, $target_lang, $term->name, $this->pat_strings_to_exclude);
            } else {
                $term_name_translation = $term->name;
            }
            $translated_term_slug = sanitize_title($term_name_translation).'-'.$target_lang;

            //regardless if the term name should be translated, the term is another language version, so we have still to create it

            //see if the orignal term had a parent - this is the recursive part - walking up the taxonomy tree
            $translated_term_parent_id = 0;
            if ($term->parent != 0){
                $parent_term = get_term($term->parent, $term->taxonomy);
                $translated_term_parent_id = $this->pat_translate_terms($source_lang, $target_lang, $parent_term, $term_taxonomy, $taxonomy_flag);
            }
            
            $translated_term = wp_insert_term($term_name_translation, $term->taxonomy,
                                            array('parent'=> $translated_term_parent_id, 
                                                    'slug' => $translated_term_slug));
            
            //it can happen that the same terms exist, but they are not linked in polylang. Translating them results in an error.
            //here we handle that error.
            if (get_class($translated_term) === 'WP_Error'){
                //if term in fact exists
                if ($translated_term->get_error_code() ===  'term_exists'){
                    //we use this existing term as out translated term
                    $translated_term_id = $translated_term->get_error_data();
                    $translated_term = array('term_id' => $translated_term_id, 'term_taxonomy_id' => $term->term_taxonomy_id);
                } else {
                    //otherwise we have to throw the error
                    $error_message = '';
                    foreach ($translated_term->get_error_messages() as $message){
                        $error_message .= $message . ' ';
                    }
                    throw new Exception('A problem occured while translating terms. Error message is: ' . $error_message);
                }
            } else {
                $translated_term_id = $translated_term['term_id'];
            }
            
            //terms can have meta data
            $translated_term_meta = $this->pat_translate_metas($source_lang, $target_lang, get_term_meta($term->term_id));
            foreach($translated_term_meta as $meta_key => $meta_value ){
                add_term_meta($translated_term['term_id'], $meta_key, $meta_value);
            }

            //if terms are different
            if ($translated_term_id != $term->term_id){
                //we set the new term language
                pll_set_term_language($translated_term['term_id'], $target_lang);
                $term_translations = pll_get_term_translations($term->term_id);       //get existing post translations
                $term_translations[$target_lang] = $translated_term['term_id'];         //set post id for the translated language

                //and save term language relationships
                pll_save_term_translations($term_translations);
            
            }
            
            //enable further translations and changes if necessary
            do_action( 'pat_translated_term_action', $term->term_id, $translated_term['term_id'], $source_lang, $target_lang, $this->pat_strings_to_exclude);

        }

        return $translated_term_id;

    }


 // Generic translate text function and API ------------------------------------------------------------------------------------------------------------------
    //public function so that it can be used also from other plugins etc
    public function pat_translate_text($source_lang, $target_lang, $text_to_translate, $excluded_strings = array()){

        $placeholders = array();
        for( $i = 1 ; $i < count($excluded_strings); $i++ ){
            $placeholders[] = '#$%' . $i . '%$#';
        }
        $text_to_translate = str_replace($excluded_strings, $placeholders, $text_to_translate);
        
        //prepare data to be translated
        $translation_method = 'POST';
        $translation_url = 'https://translation.googleapis.com/language/translate/v2';
        $translation_data = array('source' => $source_lang, 'target' => $target_lang, 'q' => $text_to_translate, 'key' => $this->pat_api_key);

        //perform curl request
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $translation_data);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, "username:password");
        curl_setopt($curl, CURLOPT_URL, $translation_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($curl, CURLOPT_REFERER, actual_link());

        //execute request and close curl object
        $result = curl_exec($curl);
        curl_close($curl);

        //handle trasnlated result
        $translation_object = json_decode( (string) $result);

        if(!$result){
            throw new Exception('Google returned FALSE. Check in Google API Console if your API key is allowed to be used on this site.');
        } else if ($translation_object->error){
            throw new Exception($translation_object->error->message);
        } else {
            $translated_text = $translation_object->data->translations[0]->translatedText;
            $translated_text = str_ireplace( $placeholders, $excluded_strings, $translated_text );
            return $translated_text;
        }
    }

 // Helper functions --------------------------------------------------------------------------------------------------------------------

    public function pat_meta_flag($meta){

        if (in_array($meta, $this->pat_meta_to_exclude)){
            $meta_flag = "exclude";
        } else if (in_array($meta, array('_product_attributes'))) {
            $meta_flag = "exclude";                                                         //always exclude _product_attributes meta
        } else if(in_array($meta, $this->pat_meta_to_translate)){
            $meta_flag = "translate";
        } else {
            $meta_flag = "copy";
        }
        return $meta_flag;
    }

    public function pat_taxonomy_flag($taxonomy){

        if (in_array($taxonomy, $this->pat_taxonomies_to_exclude)){
            $taxonomy_flag = "exclude";
        } else if(in_array($taxonomy, array('language', 'term_language', 'term_translations', 'post_translations', 'product_type'))) {
            $taxonomy_flag = "exclude";                                                 //always exclude polylang taxonomies
        } else if(in_array($taxonomy, $this->pat_taxonomies_to_translate)){
            $taxonomy_flag = "translate";
        } else {
            $taxonomy_flag = "copy";
        }
        return $taxonomy_flag;
    }

    //copied from woocommerce duplicate product WC_Admin_Duplicate_Product class
    private function pat_generate_unique_slug( $product ) {
		global $wpdb;

		// We want to remove the suffix from the slug so that we can find the maximum suffix using this root slug.
		// This will allow us to find the next-highest suffix that is unique. While this does not support gap
		// filling, this shouldn't matter for our use-case.
		$root_slug = preg_replace( '/-[0-9]+$/', '', $product->get_slug() );

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_name FROM $wpdb->posts WHERE post_name LIKE %s AND post_type IN ( 'product', 'product_variation' )", $root_slug . '%' )
		);

		// The slug is already unique!
		if ( empty( $results ) ) {
			return;
		}

		// Find the maximum suffix so we can ensure uniqueness.
		$max_suffix = 1;
		foreach ( $results as $result ) {
			// Pull a numerical suffix off the slug after the last hyphen.
			$suffix = intval( substr( $result->post_name, strrpos( $result->post_name, '-' ) + 1 ) );
			if ( $suffix > $max_suffix ) {
				$max_suffix = $suffix;
			}
		}

		$product->set_slug( $root_slug . '-' . ( $max_suffix + 1 ) );
	}

    private function pat_is_plugin_active_or_multisite($plugin){

        $active = in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );

        if ( $active ) {
            return true;
        }

		if ( is_multisite() ) {
            $plugins = get_site_option( 'active_sitewide_plugins' );

            if ( isset( $plugins[ $plugin ] ) ) {
                return true;
            }
        }

		return false;
    }

}

endif;

function PAT_auto_translate() {
	return PAT_translate_class::instance();
}

$pat_auto_translate_instance = PAT_auto_translate();
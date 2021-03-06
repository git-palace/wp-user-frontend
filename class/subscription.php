<?php

/**
 * WPUF subscription manager
 *
 * @since 0.2
 * @author Tareq Hasan
 * @package WP User Frontend
 */
class WPUF_Subscription {

    private static $_instance;

    function __construct() {

        add_action( 'init', array($this, 'register_post_type') );
        add_filter( 'wpuf_add_post_args', array($this, 'set_pending'), 10, 4 );
        add_filter( 'wpuf_add_post_redirect', array($this, 'post_redirect'), 10, 4 );

        add_filter( 'wpuf_addpost_notice', array($this, 'force_pack_notice'), 20, 3 );
        add_filter( 'wpuf_can_post', array($this, 'force_pack_permission'), 20, 3 );
        add_action( 'wpuf_add_post_form_top', array($this, 'add_post_info'), 10, 2 );

        add_action( 'wpuf_add_post_after_insert', array($this, 'monitor_new_post'), 10, 3 );
        add_action( 'wpuf_draft_post_after_insert', array($this, 'monitor_new_draft_post'), 10, 3 );
        add_action( 'wpuf_payment_received', array($this, 'payment_received'), 10, 2 );

        add_shortcode( 'wpuf_sub_info', array($this, 'subscription_info') );
        add_shortcode( 'wpuf_sub_pack', array($this, 'subscription_packs') );

        add_action( 'save_post', array( $this, 'save_form_meta' ), 10, 2 );
        add_filter( 'enter_title_here', array( $this, 'change_default_title' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'subscription_script' ) );

        add_action( 'user_register', array( $this,'after_registration' ), 10, 1 );

        add_action( 'register_form',array( $this, 'register_form') );
        add_action( 'wpuf_add_post_form_top',array( $this, 'register_form') );
        add_filter( 'wpuf_user_register_redirect', array( $this, 'subs_redirect_pram' ), 10, 5 );

        add_filter( 'template_redirect', array( $this, 'user_subscription_cancel' ) );

        add_action( 'wpuf_draft_post_after_insert', array( $this, 'reset_user_subscription_data' ), 10, 4 );

    }

    /**
     * Handle subscription cancel request from the user
     *
     * @return WPUF_Subscription
     */
    public static function subscriber_cancel( $user_id, $pack_id ) {
        global $wpdb;

        $sql = $wpdb->prepare( "SELECT transaction_id FROM " . $wpdb->prefix . "wpuf_transaction
            WHERE user_id = %d AND pack_id = %d LIMIT 1", $user_id, $pack_id );
        $result = $wpdb->get_row( $sql );

        $transaction_id = $result ? $result->transaction_id : 0;

        $wpdb->update( $wpdb->prefix.'wpuf_subscribers', array( 'subscribtion_status' => 'cancel' ), array( 'user_id' => $user_id, 'subscribtion_id' => $pack_id, 'transaction_id' => $transaction_id ) );
    }

    /**
     * Handle subscription cancel request from the user
     *
     * @return WPUF_Subscription
     */
    public function user_subscription_cancel() {
        if ( isset( $_POST['wpuf_cancel_subscription'] ) ) {

            if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wpuf-sub-cancel' ) ) {
                wp_die( __( 'Nonce failure', 'wpuf' ) );
            }

            $current_pack = self::get_user_pack( $_POST['user_id'] );

            $gateway = ( $_POST['gateway'] == 'bank/manual' ) ? 'bank' : sanitize_text_field( $_POST['gateway'] );

            if ( 'bank' == $gateway || 'no' == $current_pack['recurring'] ) {
                $this->update_user_subscription_meta( $_POST['user_id'], 'Cancel' );
            } else {
                do_action( "wpuf_cancel_subscription_{$gateway}", $_POST );
            }

            $this::subscriber_cancel( $_POST['user_id'], $current_pack['pack_id'] );

            wp_redirect( $_SERVER['REQUEST_URI'] );

        }
    }


    public static function init() {
        if ( !self::$_instance ) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

    /**
     * Redirect a user to subscription page after signup
     *
     * @since 2.2
     *
     * @param  array $response
     * @param  int $user_id
     * @param  array $userdata
     * @param  int $form_id
     * @param  array $form_settings
     * @return array
     */
    function subs_redirect_pram( $response, $user_id, $userdata, $form_id, $form_settings ) {
        if ( ! isset( $_POST['wpuf_sub'] ) || $_POST['wpuf_sub'] != 'yes' ) {
            return $response;
        }

        if ( ! isset( $_POST['pack_id'] ) || empty( $_POST['pack_id'] ) ) {
            return $response;
        }

        $pack           = $this->get_subscription( $_POST['pack_id'] );
        $billing_amount = ( $pack->meta_value['billing_amount'] >= 0 && !empty( $pack->meta_value['billing_amount'] ) ) ? $pack->meta_value['billing_amount'] : false;

        if ( $billing_amount !== false ) {
            $pay_page = intval( wpuf_get_option( 'payment_page', 'wpuf_payment' ) );
            $redirect =  add_query_arg( array('action' => 'wpuf_pay', 'user_id' => $user_id, 'type' => 'pack', 'pack_id' => (int) $_POST['pack_id'] ), get_permalink( $pay_page ) );

            $response['redirect_to'] = $redirect;
            $response['show_message'] = false;
        }

        return $response;
    }

    /**
     * Insert hidden field on the register form based on selected package
     *
     * @since 2.2
     *
     * @return void
     */
    function register_form() {
        if ( !isset( $_GET['type'] ) || $_GET['type'] != 'wpuf_sub' ) {
            return;
        }

        if ( !isset( $_GET['pack_id'] ) || empty( $_GET['pack_id'] ) ) {
            return;
        }

        $pack_id = (int) $_GET['pack_id'];
        ?>
        <input type="hidden" name="wpuf_sub" value="yes" />
        <input type="hidden" name="pack_id" value="<?php echo $pack_id; ?>" />

        <?php
    }

    /**
     * Redirect to payment page or add free subscription after user registration
     *
     * @since 2.2
     *
     * @param  int $user_id
     * @return void
     */
    function after_registration( $user_id ) {

        if ( !isset( $_POST['wpuf_sub'] ) || $_POST['wpuf_sub'] != 'yes' ) {
            return $user_id;
        }

        if ( !isset( $_POST['pack_id'] ) || empty( $_POST['pack_id'] ) ) {
            return $user_id;
        }

        $pack_id        = isset( $_POST['pack_id'] ) ? intval( $_POST['pack_id'] ) : 0;
        $pack           = $this->get_subscription( $pack_id );
        $billing_amount = ( $pack->meta_value['billing_amount'] >= 0 && !empty( $pack->meta_value['billing_amount'] ) ) ? $pack->meta_value['billing_amount'] : false;

        if ( $billing_amount === false ) {
            wpuf_get_user( $user_id )->subscription()->add_pack( $pack_id, null, false, 'free' );
            wpuf_get_user( $user_id )->subscription()->add_free_pack( $user_id, $pack_id );
        } else {
            $pay_page = intval( wpuf_get_option( 'payment_page', 'wpuf_payment' ) );
            $redirect = add_query_arg( array( 'action' => 'wpuf_pay', 'type' => 'pack', 'pack_id' => (int) $pack_id ), get_permalink( $pay_page ) );
        }
    }

    /**
     * Enqueue scripts and styles
     *
     * @since 2.2
     */
    function subscription_script() {
        wp_enqueue_script( 'wpuf-subscriptions', WPUF_ASSET_URI . '/js/subscriptions.js', array('jquery'), false, true );
    }

    /**
     * Get all subscription packs
     *
     * @return array
     */
    function get_subscriptions( $args = array() ) {
        $defaults = array(
            'post_type'      => 'wpuf_subscription',
            'posts_per_page' => -1,
            'post_status'    => 'publish',

        );

        $args  = wp_parse_args($args, $defaults);
        $posts = get_posts( $args );

        if ( $posts ) {
            foreach ($posts as $key => $post) {
                $post->meta_value = $this->get_subscription_meta( $post->ID, $posts );
            }
        }

        return $posts;
    }

    /**
     * Set meta fields on a subscription pack
     *
     * @since 2.2
     *
     * @param  int $subscription_id
     * @param  \WP_Post $pack_post
     * @return array
     */
    public static function get_subscription_meta( $subscription_id,  $pack_post = null ) {

        $meta['post_content']               = isset( $pack_post->post_content ) ? $pack_post->post_content : '';
        $meta['post_title']                 = isset( $pack_post->post_title ) ? $pack_post->post_title : '';
        $meta['billing_amount']             = get_post_meta( $subscription_id, '_billing_amount', true );
        $meta['expiration_number']          = get_post_meta( $subscription_id, '_expiration_number', true );
        $meta['expiration_period']          = get_post_meta( $subscription_id, '_expiration_period', true );
        $meta['recurring_pay']              = get_post_meta( $subscription_id, '_recurring_pay', true );
        $meta['billing_cycle_number']       = get_post_meta( $subscription_id, '_billing_cycle_number', true );
        $meta['cycle_period']               = get_post_meta( $subscription_id, '_cycle_period', true );
        $meta['billing_limit']              = get_post_meta( $subscription_id, '_billing_limit', true );
        $meta['trial_status']               = get_post_meta( $subscription_id, '_trial_status', true );
        $meta['trial_duration']             = get_post_meta( $subscription_id, '_trial_duration', true );
        $meta['trial_duration_type']        = get_post_meta( $subscription_id, '_trial_duration_type', true );
        $meta['post_type_name']             = get_post_meta( $subscription_id, '_post_type_name', true );
        $meta['_enable_post_expiration']    = get_post_meta( $subscription_id, '_enable_post_expiration', true );
        $meta['_post_expiration_time']      = get_post_meta( $subscription_id, '_post_expiration_time', true );
        $meta['_expired_post_status']       = get_post_meta( $subscription_id, '_expired_post_status', true );
        $meta['_enable_mail_after_expired'] = get_post_meta( $subscription_id, '_enable_mail_after_expired', true );
        $meta['_post_expiration_message']   = get_post_meta( $subscription_id, '_post_expiration_message', true );

        $meta = apply_filters( 'wpuf_get_subscription_meta', $meta, $subscription_id  );

        return $meta;
    }

    /**
     * Get all post types
     *
     * @since 2.2
     * @return array
     */
    function get_all_post_type() {
        $post_types = get_post_types();

        unset(
            $post_types['attachment'],
            $post_types['revision'],
            $post_types['nav_menu_item'],
            $post_types['wpuf_forms'],
            $post_types['wpuf_profile'],
            $post_types['wpuf_subscription'],
            $post_types['wpuf_coupon'],
            $post_types['wpuf_input'],
            $post_types['custom_css'],
            $post_types['customize_changeset'],
            $post_types['oembed_cache']
        );

        return apply_filters( 'wpuf_posts_type', $post_types );
    }

    /**
     * Post type name placeholder text
     *
     * @param  string $title
     * @return string
     */
    function change_default_title( $title ) {
        $screen = get_current_screen();

        if ( 'wpuf_subscription' == $screen->post_type ) {
            $title = __( 'Pack Name', 'wpuf' );
        }

        return $title;
    }

    /**
     * Save form data
     *
     * @param  int $post_ID
     * @param  \WP_Post $post
     * @return void
     */
    function save_form_meta( $subscription_id, $post ) {

        $post_data = $_POST;

        if ( !isset( $post_data['billing_amount'] ) ) {
            return;
        }

        if ( !isset( $_POST['meta_box_nonce'] ) || !wp_verify_nonce( $_POST['meta_box_nonce'], 'subs_meta_box_nonce' ) ) {
            return;
        }

        // Is the user allowed to edit the post or page?
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        update_post_meta( $subscription_id, '_billing_amount', $post_data['billing_amount'] );
        update_post_meta( $subscription_id, '_expiration_number', $post_data['expiration_number'] );
        update_post_meta( $subscription_id, '_expiration_period', $post_data['expiration_period'] );
        update_post_meta( $subscription_id, '_recurring_pay', $post_data['recurring_pay'] );
        update_post_meta( $subscription_id, '_billing_cycle_number', $post_data['billing_cycle_number'] );
        update_post_meta( $subscription_id, '_cycle_period', $post_data['cycle_period'] );
        update_post_meta( $subscription_id, '_billing_limit', $post_data['billing_limit'] );
        update_post_meta( $subscription_id, '_trial_status', $post_data['trial_status'] );
        update_post_meta( $subscription_id, '_trial_duration', $post_data['trial_duration'] );
        update_post_meta( $subscription_id, '_trial_duration_type', $post_data['trial_duration_type'] );
        update_post_meta( $subscription_id, '_post_type_name', $post_data['post_type_name'] );
        update_post_meta( $subscription_id, '_enable_post_expiration', ( isset($post_data['post_expiration_settings']['enable_post_expiration']) ? $post_data['post_expiration_settings']['enable_post_expiration']:'' ) );
        update_post_meta( $subscription_id, '_post_expiration_time', $post_data['post_expiration_settings']['expiration_time_value'] . ' ' . $post_data['post_expiration_settings']['expiration_time_type'] );
        update_post_meta( $subscription_id, '_expired_post_status', ( isset($post_data['post_expiration_settings']['expired_post_status']) ? $post_data['post_expiration_settings']['expired_post_status']:'' ) );
        update_post_meta( $subscription_id, '_enable_mail_after_expired', ( isset($post_data['post_expiration_settings']['enable_mail_after_expired']) ? $post_data['post_expiration_settings']['enable_mail_after_expired']:'' ) );
        update_post_meta( $subscription_id, '_post_expiration_message', ( isset($post_data['post_expiration_settings']['post_expiration_message']) ? $post_data['post_expiration_settings']['post_expiration_message']:'' ) );
        do_action( 'wpuf_update_subscription_pack', $subscription_id, $post_data );
    }

    /**
     * Subscription post types
     *
     * @return void
     */
    function register_post_type() {

        $capability = wpuf_admin_role();

        register_post_type( 'wpuf_subscription', array(
            'label'           => __( 'Subscription', 'wpuf' ),
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false,
            'hierarchical'    => false,
            'query_var'       => false,
            'supports'        => array( 'title' ),
            'capability_type' => 'post',
            'capabilities'    => array(
                'publish_posts'       => $capability,
                'edit_posts'          => $capability,
                'edit_others_posts'   => $capability,
                'delete_posts'        => $capability,
                'delete_others_posts' => $capability,
                'read_private_posts'  => $capability,
                'edit_post'           => $capability,
                'delete_post'         => $capability,
                'read_post'           => $capability,
            ),
            'labels' => array(
                'name'               => __( 'Subscription', 'wpuf' ),
                'singular_name'      => __( 'Subscription', 'wpuf' ),
                'menu_name'          => __( 'Subscription', 'wpuf' ),
                'add_new'            => __( 'Add Subscription', 'wpuf' ),
                'add_new_item'       => __( 'Add New Subscription', 'wpuf' ),
                'edit'               => __( 'Edit', 'wpuf' ),
                'edit_item'          => __( 'Edit Subscription', 'wpuf' ),
                'new_item'           => __( 'New Subscription', 'wpuf' ),
                'view'               => __( 'View Subscription', 'wpuf' ),
                'view_item'          => __( 'View Subscription', 'wpuf' ),
                'search_items'       => __( 'Search Subscription', 'wpuf' ),
                'not_found'          => __( 'No Subscription Found', 'wpuf' ),
                'not_found_in_trash' => __( 'No Subscription Found in Trash', 'wpuf' ),
                'parent'             => __( 'Parent Subscription', 'wpuf' ),
            ),
        ) );
    }

    /**
     * Update users subscription
     *
     * Updates the pack when new re-curring payment IPN notification is being
     * sent from PayPal.
     *
     * @return void
     */
    function update_paypal_subscr_payment() {
        if ( !isset( $_POST['txn_type'] ) && $_POST['txn_type'] != 'subscr_payment'  ) {
            return;
        }

        if ( strtolower( $_POST['payment_status'] ) != 'completed' ) {
            return;
        }

        $pack  = $this->get_subscription( $pack_id );
        $payer = json_decode( stripcslashes( $_POST['custom'] ) );

        $this->update_user_subscription_meta( $payer->payer_id, $pack );
    }

    /**
     * Get a subscription row from database
     *
     * @global object $wpdb
     * @param int $sub_id subscription pack id
     * @return object|bool
     */
    public static function get_subscription( $sub_id ) {
        $pack = get_post( $sub_id );

        if ( ! $pack ) {
            return false;
        }

        $pack->meta_value = self::get_subscription_meta( $sub_id, $pack );

        return $pack;
    }

    /**
     * Set the new post status if charging is active
     *
     * @param string $postdata
     * @return string
     */
    function set_pending( $postdata, $form_id, $form_settings, $form_vars ) {

        $form             = new WPUF_Form( $form_id );
        $payment_options  = $form->is_charging_enabled();
        $pay_per_post     = $form->is_enabled_pay_per_post();
        $fallback_cost    = $form->is_enabled_fallback_cost();
        $current_user     = wpuf_get_user();
        $current_pack     = $current_user->subscription()->current_pack();
        $has_post         = $current_user->subscription()->has_post_count( $form_settings['post_type'] );

        if ( is_wp_error( $current_pack ) && $fallback_cost && !$has_post )  {
            $postdata['post_status'] = 'pending';
        }

        if ( $payment_options && ( $pay_per_post || ( $fallback_cost && !$has_post )))  {
            $postdata['post_status'] = 'pending';
        }

        return $postdata;
    }

    /**
     * Checks the posting validity after a new post
     *
     * @global object $userdata
     * @global object $wpdb
     * @param int $post_id
     */
    function monitor_new_post( $post_id, $form_id, $form_settings ) {

        global $wpdb, $userdata;

        // bail out if charging is not enabled
        $form = new WPUF_Form( $form_id );

        if ( !$form->is_charging_enabled() ) {
            return;
        }

        $force_pack    = $form->is_enabled_force_pack();
        $pay_per_post  = $form->is_enabled_pay_per_post();
        $fallback_cost = $form->is_enabled_fallback_cost();
        $current_user  = wpuf_get_user();
        $current_pack  = $current_user->subscription()->current_pack();
        $has_post      = $current_user->subscription()->has_post_count( $form_settings['post_type'] );

        if ( $force_pack && ! is_wp_error( $current_pack ) && $has_post ) {

            $sub_info    = self::get_user_pack( $userdata->ID );
            $post_type   = isset( $form_settings['post_type'] ) ? $form_settings['post_type'] : 'post';
            $count       = isset( $sub_info['posts'][$post_type] ) ? intval( $sub_info['posts'][$post_type] ) : 0;
            $post_status = isset( $form_settings['post_status'] ) ? $form_settings['post_status'] : 'publish';

            $old_status = $post->post_status;
            wp_transition_post_status( $post_status, $old_status, $post );
            // wp_update_post( array( 'ID' => $post_id , 'post_status' => $post_status) );

            // decrease the post count, if not umlimited
            $wpuf_post_status = get_post_meta( $post_id, 'wpuf_post_status', true );

            if ( $wpuf_post_status != 'new_draft' ) {
                if ( $count > 0 ) {
                    $sub_info['posts'][$post_type] = $count - 1;
                    $this->update_user_subscription_meta( $userdata->ID, $sub_info );
                }
            }
            //meta added to make post have flag if post is published
            update_post_meta( $post_id, 'wpuf_post_status', 'published' );


        } elseif ( $pay_per_post || ($fallback_cost && !$has_post )) {
            //there is some error and it needs payment
            //add a uniqid to track the post easily
            $order_id = uniqid( rand( 10, 1000 ), false );
            update_post_meta( $post_id, '_wpuf_order_id', $order_id, true );
            update_post_meta( $post_id, '_wpuf_payment_status', 'pending' );
        }

    }

    /**
     * Check if the post is draft and charging is enabled
     *
     * @global object $userdata
     * @global object $wpdb
     * @param int $post_id
     */
    function monitor_new_draft_post( $post_id, $form_id, $form_settings ) {

        global $wpdb, $userdata;

        // bail out if charging is not enabled
        $charging_enabled = '';
        $form             = new WPUF_Form( $form_id );
        $payment_options  = $form->is_charging_enabled();
        if ( !$payment_options || !is_user_logged_in() ) {
            $charging_enabled = 'no';
        } else {
            $charging_enabled = 'yes';
        }
        // if ( wpuf_get_option( 'charge_posting', 'wpuf_payment', 'no' ) != 'yes' ) {
        //     return;
        // }

        $userdata = get_userdata( get_current_user_id() );

        if ( self::has_user_error( $form_settings ) ) {
            //there is some error and it needs payment
            //add a uniqid to track the post easily
            $order_id = uniqid( rand( 10, 1000 ), false );
            update_post_meta( $post_id, '_wpuf_order_id', $order_id, true );
        }

    }

    /**
     * Redirect to payment page after new post
     *
     * @param string $str
     * @param type $post_id
     * @return string
     */
    function post_redirect( $response, $post_id, $form_id, $form_settings ) {

        $form             = new WPUF_Form( $form_id );
        $payment_options  = $form->is_charging_enabled();
        $force_pack       = $form->is_enabled_force_pack();
        $fallback_cost    = $form->is_enabled_fallback_cost();
        $current_user     = wpuf_get_user();
        $current_pack     = $current_user->subscription()->current_pack();
        $has_pack         = $current_user->subscription()->has_post_count( $form_settings['post_type'] );
        $ppp_cost_enabled = $form->is_enabled_pay_per_post();
        $sub_expired      = $current_user->subscription()->expired();

        if ( ( $payment_options && !$has_pack ) || ( $payment_options && $sub_expired ) ) {
            $order_id = get_post_meta( $post_id, '_wpuf_order_id', true );

            // check if there is a order ID
            if ( $order_id || ( $payment_options && $fallback_cost ) ) {
                $response['show_message'] = false;
                $response['redirect_to']  = add_query_arg( array(
                    'action'  => 'wpuf_pay',
                    'type'    => 'post',
                    'post_id' => $post_id
                ), get_permalink( wpuf_get_option( 'payment_page', 'wpuf_payment' ) ) );
            }
            if ( !$force_pack && $ppp_cost_enabled ) {
                $response['show_message'] = false;
                $response['redirect_to'] = add_query_arg( array(
                    'action'  => 'wpuf_pay',
                    'type'    => 'post',
                    'post_id' => $post_id
                ), get_permalink( wpuf_get_option( 'payment_page', 'wpuf_payment' ) ) );
            }
        }
        return $response;
    }

    /**
     * Perform actions when a new payment is made
     *
     * @param array $info payment info
     */
    function payment_received( $info, $recurring ) {
        if ( $info['post_id'] ) {
            $order_id = get_post_meta( $info['post_id'], '_wpuf_order_id', true );

            $this->handle_post_publish( $order_id );

        } else if ( $info['pack_id'] ) {

            if ( $recurring ) {
                $profile_id = $info['profile_id'];
            }else{
                $profile_id = isset( $info['user_id'] ) ? $info['user_id'] : null;
            }

            wpuf_get_user( $info['user_id'] )->subscription()->add_pack( $info['pack_id'], $profile_id, $recurring, $info['status'] );

        }
    }

    /**
     * Store new subscription info on user profile
     *
     * if data = 0, means 'unlimited'
     *
     * @param int $user_id
     * @param int $pack_id subscription pack id
     */
    public function new_subscription( $user_id, $pack_id, $profile_id = null, $recurring, $status = null ) {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user( $user_id )->subscription()->add_pack( $pack_id, $profile_id = null, $recurring, $status = null );' );

        wpuf_get_user( $user_id )->subscription()->add_pack( $pack_id, $profile_id = null, $recurring, $status = null );
    }

    /**
     * update user meta
     *
     * if data = 0, means 'unlimited'
     *
     * @param int $user_id
     * @param array $data
     */
    public static function update_user_subscription_meta( $user_id, $user_meta ) {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user( $user_id )->subscription()->update_meta( $user_meta );' );

        wpuf_get_user( $user_id )->subscription()->update_meta( $user_meta );
    }

    public static function post_by_orderid( $order_id ) {
        global $wpdb;

        //$post = get_post( $post_id );
        $sql = $wpdb->prepare( "SELECT p.ID, p.post_status
            FROM $wpdb->posts p, $wpdb->postmeta m
            WHERE p.ID = m.post_id AND p.post_status <> 'publish' AND m.meta_key = '_wpuf_order_id' AND m.meta_value = %s", $order_id );

        return $wpdb->get_row( $sql );
    }

    /**
     * Publish the post if payment is made
     *
     * @param int $post_id
     */
    function handle_post_publish( $order_id ) {
        $post = self::post_by_orderid( $order_id );

        if ( $post ) {
            // set the payment status
            update_post_meta( $post->ID, '_wpuf_payment_status', 'completed' );

            if ( $post->post_status != 'publish' ) {
                $this->set_post_status( $post->ID );
            }
        }
    }

    /**
     * Maintain post status from the form settings
     *
     * @since 2.1.9
     * @param int $post_id
     */
    function set_post_status( $post_id ) {
        $post_status = 'publish';
        $form_id     = get_post_meta( $post_id, '_wpuf_form_id', true );

        if ( $form_id ) {
            $form_settings = wpuf_get_form_settings( $form_id );
            $post_status   = $form_settings['post_status'];
        }

        $update_post = array(
            'ID'          => $post_id,
            'post_status' => $post_status
        );

        wp_update_post( $update_post );
    }

    /**
     * Generate users subscription info with a shortcode
     *
     * @global type $userdata
     */
    function subscription_info() {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user()->subscription()->pack_info( $form_id );' );
        // wpuf_get_user()->subscription()->pack_info( $form_id );
        $sections        = wpuf_get_account_sections();
        do_action( "wpuf_account_content_subscription", $sections, 'subscription' );
    }


    /**
     * Show the subscription packs that are built
     * from admin Panel
     */
    function subscription_packs( $atts = null ) {
        $cost_per_post = isset( $form_settings['pay_per_post_cost'] ) ? $form_settings['pay_per_post_cost'] : 0;

        $defaults = array(
            'include' => '',
            'exclude' => '',
            'order'   => '',
            'orderby' => ''
        );

        $arranged = array();
        $args     = wp_parse_args( $atts, $defaults );

        if ( $args['include'] != ""  ) {
            $pack_order = explode(',', $args['include']);
        } else {
            $args['order'] = isset( $args['order'] ) ? $args['order'] : 'ASC';
        }

        $packs = $this->get_subscriptions( $args );

        $details_meta = $this->get_details_meta_value();

        ob_start();

        if ( isset( $_GET['action'] ) && $_GET['action'] == 'wpuf_paypal_success' ) {
            printf( '<h1>%1$s</h1><p>%2$s</p>', __( 'Payment is complete', 'wpuf' ), __( 'Congratulations, your payment has been completed!', 'wpuf' ) );
        }

        if ( isset( $_GET['pack_msg'] ) && $_GET['pack_msg'] == 'buy_pack' ) {
            _e('Please buy a subscription pack to post', 'wpuf' );
        }

        if ( isset( $_GET['ppp_msg'] ) && $_GET['ppp_msg'] == 'pay_per_post' ) {
            _e('Please buy a subscription pack to post', 'wpuf' );
        }

        $current_pack = self::get_user_pack( get_current_user_id() );

        if ( isset( $current_pack['pack_id'] ) ) {
            global $wpdb;

            $user_id = get_current_user_id();
            $payment_gateway = $wpdb->get_var( "SELECT payment_type FROM {$wpdb->prefix}wpuf_transaction WHERE user_id = {$user_id} AND status = 'completed' ORDER BY created DESC" );

            $payment_gateway = strtolower( $payment_gateway );
            ?>

            <?php _e( '<p><i>You have a subscription pack activated. </i></p>', 'wpuf' ); ?>
            <?php _e( '<p><i>Pack name : '.get_the_title( $current_pack['pack_id'] ).' </i></p>', 'wpuf' );

            ?>
            <?php _e( '<p><i>To cancel the pack, press the following cancel button</i></p>', 'wpuf' ); ?>

            <form action="" method="post">
                <?php wp_nonce_field( 'wpuf-sub-cancel' ); ?>
                <input type="hidden" name="user_id" value="<?php echo get_current_user_id(); ?>">
                <input type="hidden" name="gateway" value="<?php echo $payment_gateway; ?>">
                <input type="submit" name="wpuf_cancel_subscription" class="btn btn-sm btn-danger" value="<?php _e( 'Cancel', 'wpuf' ); ?>">
            </form>
            <?php
        }

        if ( $packs ) {
            echo '<ul class="wpuf_packs">';
            if ( isset($args['include']) && $args['include'] != "" ) {
                for ( $i = 0; $i < count( $pack_order ); $i++ ) {
                    foreach ($packs as $pack) {
                        if (  (int) $pack->ID == $pack_order[$i] ) {
                            $class = 'wpuf-pack-' . $pack->ID;
                            ?>
                            <li class="<?php echo $class; ?>">
                                <?php $this->pack_details( $pack, $details_meta, isset( $current_pack['pack_id'] ) ? $current_pack['pack_id'] : '' ); ?>
                            </li>
                            <?php
                        }
                    }
                }
            } else {
                foreach ($packs as $pack) {
                    $class = 'wpuf-pack-' . $pack->ID;
                    ?>
                    <li class="<?php echo $class; ?>">
                        <?php $this->pack_details( $pack, $details_meta, isset( $current_pack['pack_id'] ) ? $current_pack['pack_id'] : '' ); ?>
                    </li>
                    <?php
                }
            }
            echo '</ul>';
        }

        $contents = ob_get_clean();

        return apply_filters( 'wpuf_subscription_packs', $contents, $packs );


    }

    function get_details_meta_value() {

        $meta['payment_page'] = get_permalink( wpuf_get_option( 'payment_page', 'wpuf_payment' ) );
        $meta['onclick'] = '';
        $meta['symbol']  = wpuf_get_currency( 'symbol' );

        return $meta;
    }

    function pack_details( $pack, $details_meta, $current_pack_id = '', $coupon_satus = false ) {

        if ( function_exists( 'wpuf_prices_include_tax' ) ) {
            $price_with_tax = wpuf_prices_include_tax();
        }

        $billing_amount = ( $pack->meta_value['billing_amount'] >= 0 && !empty( $pack->meta_value['billing_amount'] ) ) ? $pack->meta_value['billing_amount'] : '0.00';

        if ( isset( $price_with_tax ) && $price_with_tax ) {
            $billing_amount = apply_filters( 'wpuf_payment_amount', $billing_amount);
        }

        if ( $billing_amount && $pack->meta_value['recurring_pay'] == 'yes' ) {
            $recurring_des = sprintf( __('Every', 'wpuf').' %s %s', $pack->meta_value['billing_cycle_number'], $pack->meta_value['cycle_period'], $pack->meta_value['trial_duration_type'] );
            $recurring_des .= !empty( $pack->meta_value['billing_limit'] ) ? __( sprintf( ', '.__('for', 'wpuf').' %s '.__( 'installments', 'wpuf' ), $pack->meta_value['billing_limit'] ), 'wpuf' ) : '';
            $recurring_des = '<div class="wpuf-pack-cycle wpuf-nullamount-hide">'.$recurring_des.'</div>';
        } else {
            $recurring_des = '<div class="wpuf-pack-cycle wpuf-nullamount-hide">' . __( 'One time payment', 'wpuf' ) . '</div>';
        }

        if ( $billing_amount && $pack->meta_value['recurring_pay'] == 'yes' && $pack->meta_value['trial_status'] == 'yes' ) {

            $duration = _n( $pack->meta_value['trial_duration_type'], $pack->meta_value['trial_duration_type'].'s', $pack->meta_value['trial_duration'], 'wpuf'  );
            $trial_des = __( sprintf( 'Trial available for first %s %s', $pack->meta_value['trial_duration'], $duration ), 'wpuf' );

        } else {
            $trial_des = '';
        }

        if (  ! is_user_logged_in()  ) {
            $button_name = __( 'Sign Up', 'wpuf' );
            $url = wp_login_url();
        } else if ( $billing_amount == '0.00' ) {
            $button_name = __( 'Free', 'wpuf' );
        } else {
            $button_name = __( 'Buy Now', 'wpuf' );
        }
        ?>
        <div class="wpuf-pricing-wrap">
            <h3><?php echo wp_kses_post( $pack->post_title ); ?> </h3>
            <div class="wpuf-sub-amount">

                <?php if ( $billing_amount != '0.00' ) { ?>
                    <sup class="wpuf-sub-symbol"><?php echo $details_meta['symbol']; ?></sup>
                    <span class="wpuf-sub-cost"><?php echo $billing_amount; ?></span>
                <?php } else { ?>
                    <span class="wpuf-sub-cost"><?php _e( 'Free', 'wpuf' ); ?></span>
                <?php } ?>

                <?php _e( $recurring_des , 'wpuf' ); ?>

            </div>
            <?php
            if ( $pack->meta_value['recurring_pay'] == 'yes' ) {
            ?>
                <div class="wpuf-sub-body wpuf-nullamount-hide">
                    <div class="wpuf-sub-terms"><?php echo $trial_des; ?></div>
                </div>
            <?php
            }
            ?>
        </div>
        <div class="wpuf-sub-desciption">
            <?php echo wpautop( wp_kses_post( $pack->post_content ) ); ?>
        </div>
        <?php

        if ( isset( $_GET['action'] ) && $_GET['action'] == 'wpuf_pay' || $coupon_satus ) {
            return;
        }
        if ( $coupon_satus === false && is_user_logged_in() ) {
            ?>
                <div class="wpuf-sub-button"><a <?php echo ( $current_pack_id != '' ) ? ' class = "wpuf-disabled-link" ' : '' ;?> href="<?php echo ( $current_pack_id != '' ) ? 'javascript:' : add_query_arg( array('action' => 'wpuf_pay', 'type' => 'pack', 'pack_id' => $pack->ID ), $details_meta['payment_page'] ); ?>" onclick="<?php echo esc_attr( $details_meta['onclick'] ); ?>"><?php echo $button_name; ?></a></div>
            <?php
        } else {
            ?>
                <div class="wpuf-sub-button"><a <?php echo ( $current_pack_id != '' ) ? ' class = "wpuf-disabled-link" ' : '' ;?>  href="<?php echo ( $current_pack_id != '' ) ? 'javascript:' : add_query_arg( array( 'action' => 'register', 'type' => 'wpuf_sub', 'pack_id' => $pack->ID ), wp_registration_url() ); ?>" onclick="<?php echo esc_attr( $details_meta['onclick'] ); ?>"><?php echo $button_name; ?></a></div>
            <?php
            //wp_registration_url()
        }

    }

    /**
     * Show a info message when posting if payment is enabled
     */
    function add_post_info( $form_id, $form_settings ) {
        $form              = new WPUF_Form( $form_id );
        $pay_per_post      = $form->is_enabled_pay_per_post();
        $pay_per_post_cost = (float) $form->get_pay_per_post_cost();
        $force_pack        = $form->is_enabled_force_pack();
        $current_user      = wpuf_get_user();
        $current_pack      = $current_user->subscription()->current_pack();
        $payment_enabled   = $form->is_charging_enabled();

        if ( function_exists( 'wpuf_prices_include_tax' ) ) {
            $price_with_tax = wpuf_prices_include_tax();
        }

        if ( self::has_user_error( $form_settings ) || ( $payment_enabled && $pay_per_post && !$force_pack ) ) {
            ?>
            <div class="wpuf-info">
                <?php
                $form              = new WPUF_Form( $form_id );
                $pay_per_post_cost = (float) $form->get_pay_per_post_cost();

                if ( isset( $price_with_tax ) && $price_with_tax ) {
                    $pay_per_post_cost = apply_filters( 'wpuf_payment_amount', $pay_per_post_cost );
                }

                $text              = sprintf( __( 'There is a <strong>%s</strong> charge to add a new post.', 'wpuf' ), wpuf_format_price( $pay_per_post_cost ));

                echo apply_filters( 'wpuf_ppp_notice', $text, $form_id, $form_settings );
                ?>
            </div>
            <?php
        } elseif ( self::has_user_error( $form_settings ) || ( $payment_enabled && $force_pack &&  !is_wp_error( $current_pack ) && !$current_user->subscription()->has_post_count( $form_settings['post_type'] ) ) ) {
            ?>
            <div class="wpuf-info">
                <?php
                $form              = new WPUF_Form( $form_id );
                $fallback_cost     = (int )$form->get_subs_fallback_cost();

                if ( isset( $price_with_tax ) && $price_with_tax ) {
                    $fallback_cost     = apply_filters( 'wpuf_payment_amount', $fallback_cost );
                }

                $text              = sprintf( __( 'Your Subscription pack exhausted. There is a <strong>%s</strong> charge to add a new post.', 'wpuf' ), wpuf_format_price( $fallback_cost ));

                echo apply_filters( 'wpuf_ppp_notice', $text, $form_id, $form_settings );
                ?>
            </div>
            <?php
        }
    }

    public static function get_user_pack( $user_id, $status = true ) {
        return get_user_meta( $user_id, '_wpuf_subscription_pack', $status );
    }

    public function subscription_pack_users( $pack_id = '', $status = '' ) {
        global $wpdb;
        $sql = 'SELECT user_id FROM ' . $wpdb->prefix . 'wpuf_subscribers';
        $sql .= $pack_id ? ' WHERE subscribtion_id = ' . $pack_id : '';
        $sql .= $status ? ' AND subscribtion_status = ' . $status : '';

        $rows = $wpdb->get_results( $sql );

        if ( empty( $rows ) ) {
            return $rows;
        }

        $results = array();
        foreach ( $rows as $row) {
            if ( !in_array( $row->user_id, $results ) ) {
                $results[] = $row->user_id;
            }
        }

        $users = get_users( array( 'include' => $results ) );
        return $users;
    }

    function force_pack_notice( $text, $id, $form_settings ) {

        $form = new WPUF_Form( $id );

        $force_pack = $form->is_enabled_force_pack();

        if ( $force_pack && self::has_user_error($form_settings) ) {
            $pack_page = get_permalink( wpuf_get_option( 'subscription_page', 'wpuf_payment' ) );

            $text = sprintf( __( 'You must <a href="%s">purchase a pack</a> before posting', 'wpuf' ), $pack_page );
        }

        return apply_filters( 'wpuf_pack_notice', $text, $id, $form_settings );
    }

    function force_pack_permission( $perm, $id, $form_settings ) {

        $form         = new WPUF_Form( $id );
        $force_pack   = $form->is_enabled_force_pack();
        $pay_per_post = $form->is_enabled_pay_per_post();
        $fallback_enabled  = $form->is_enabled_fallback_cost();
        $fallback_cost     = $form->get_subs_fallback_cost();

        $current_user = wpuf_get_user();
        $current_pack = $current_user->subscription()->current_pack();
        $has_post_count = isset( $form_settings['post_type'] ) ? $current_user->subscription()->has_post_count( $form_settings['post_type'] ) : false;

        if ( is_user_logged_in() ) {

            if ( wpuf_get_user()->post_locked() ) {
                return 'no';
            } else {

                // if post locking not enabled
                if ( !$form->is_charging_enabled() ) {
                    return 'yes';
                } else {
                    //if charging is enabled
                    if ( $force_pack ) {
                        if ( ! is_wp_error( $current_pack ) ) {
                            // current pack has no error
                            if ( ! $fallback_enabled ) {
                                //fallback cost enabled
                                if ( !$current_user->subscription()->current_pack_id() ) {
                                    return 'no';
                                } elseif ( $current_user->subscription()->has_post_count( $form_settings['post_type'] ) ) {
                                    return 'yes';
                                }
                            } else {
                                //fallback cost disabled
                                if ( !$current_user->subscription()->current_pack_id() ) {
                                    return 'no';
                                } elseif ( $has_post_count ) {
                                    return 'yes';
                                } elseif ( $current_user->subscription()->current_pack_id() && !$has_post_count ) {
                                    return 'yes';
                                }
                            }
                        } else {
                            return 'no';
                        }
                    }
                    if ( !$force_pack && $pay_per_post ) {
                        return 'yes';
                    }
                }
            }

        }

        if ( !is_user_logged_in() && isset( $form_settings['guest_post'] ) && $form_settings['guest_post'] == 'true' ) {
            if ( $form->is_charging_enabled() ) {
                if ( $force_pack ) {
                    return 'no';
                }
                if ( !$force_pack && $pay_per_post ) {
                    return 'yes';
                } elseif ( !$force_pack && !$pay_per_post ) {
                    return 'no';
                }
            }
            else {
                return 'yes';
            }
        }

        return $perm;

    }

    /**
     * Checks against the user, if he is valid for posting new post
     *
     * @global object $userdata
     * @return bool
     */
    public static function has_user_error( $form_settings = null ) {

        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user()->subscription()->has_error( $form_settings = null );' );

        wpuf_get_user()->subscription()->has_error( $form_settings = null );
    }

    /**
     * Determine if the user has used a free pack before
     *
     * @since 2.1.8
     *
     * @param int $user_id
     * @param int $pack_id
     * @return boolean
     */
    public static function has_used_free_pack( $user_id, $pack_id ) {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user( $user_id )->subscription()->used_free_pack( $pack_id );' );

        wpuf_get_user( $user_id )->subscription()->used_free_pack( $pack_id );
    }

    /**
     * Add a free used pack to the user account
     *
     * @since 2.1.8
     *
     * @param int $user_id
     * @param int $pack_id
     */
    public static function add_free_pack( $user_id, $pack_id ) {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user( $user_id )->subscription()->add_free_pack( $pack_id );' );

        wpuf_get_user( $user_id )->subscription()->add_free_pack( $user_id, $pack_id );
    }

    function packdropdown( $packs, $selected = '' ) {
        $packs = isset( $packs ) ? $packs : array();
        foreach( $packs as $key => $pack ) {
            ?>
            <option value="<?php echo $pack->ID; ?>" <?php selected( $selected, $pack->ID ); ?>><?php echo $pack->post_title; ?></option>
            <?php
        }
    }


    /**
     * Reset the post count of a subscription of a user
     *
     * @since 2.3.11
     *
     * @param $post_id
     * @param $form_id
     * @param $form_settings
     * @param $form_vars
     */
    public function reset_user_subscription_data( $post_id, $form_id, $form_settings, $form_vars ) {
        // _deprecated_function( __FUNCTION__, '2.6.0', 'wpuf_get_user()->subscription()->reset_subscription_data( $post_id, $form_id, $form_settings, $form_vars );' );

        wpuf_get_user()->subscription()->reset_subscription_data( $post_id, $form_id, $form_settings, $form_vars );

    }

    /**
     * Returns the payment status of a post
     *
     * @since 2.5.9
     *
     * @param $post_id
     * @return string
     */

    public function get_payment_status( $post_id ) {
        return get_post_meta( $post_id, '_wpuf_payment_status', true);
    }

}

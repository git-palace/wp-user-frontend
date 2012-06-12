<?php

class WPUF_Payment {

    function __construct() {
        add_action( 'init', array($this, 'send_to_gateway') );
        add_action( 'wpuf_payment_received', array($this, 'payment_notify_admin') );

        add_filter( 'the_content', array($this, 'payment_page') );
    }

    function get_payment_gateways() {

        // default, built-in gateways
        $gateways = array(
            'paypal' => array('admin_label' => 'PayPal', 'checkout_label' => 'PayPal')
        );

        $gateways = apply_filters( 'wpuf_payment_gateways', $gateways );

        return $gateways;
    }

    /**
     * Get active payment gateways
     *
     * @return array
     */
    function get_active_gateways() {
        $gateways = $this->get_payment_gateways();

        return $gateways;
    }

    function payment_page( $content ) {
        global $post;

        if ( !is_user_logged_in() ) {
            return __( 'You are not logged in', 'wpuf' );
        }

        ob_start();

        $pay_page = intval( get_option( 'wpuf_sub_pay_page' ) );
        if ( $post->ID == $pay_page && isset( $_REQUEST['action'] ) && $_REQUEST['action'] == 'wpuf_pay' ) {

            $type = ( $_REQUEST['type'] == 'post' ) ? 'post' : 'pack';
            $post_id = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;
            $pack_id = isset( $_REQUEST['pack_id'] ) ? intval( $_REQUEST['pack_id'] ) : 0;

            $gateways = $this->get_payment_gateways();
            ?>
            <form id="wpuf-payment-gateway" action="<?php echo $_SERVER["REQUEST_URI"]; ?>" method="POST">
                <?php wp_nonce_field( 'wpuf_payment_gateway' ) ?>
                <?php do_action( 'wpuf_before_payment_gateway' ); ?>
                <p>
                    <label for="wpuf-payment-method"><?php _e( 'Choose Your Payment Method', 'wpuf' ); ?></label>
                    <select name="wpuf_payment_method" id="wpuf-payment-method">
                        <?php
                        foreach ($gateways as $gateway_id => $gateway) {
                            echo '<option value="' . $gateway_id . '">' . $gateway['checkout_label'] . '</option>';
                        }
                        ?>
                    </select>
                </p>
                <?php do_action( 'wpuf_after_payment_gateway' ); ?>
                <p>
                    <input type="hidden" name="type" value="<?php echo $type; ?>" />
                    <input type="hidden" name="action" value="wpuf_pay" />
                    <?php if ( $post_id ) { ?>
                        <input type="hidden" name="post_id" value="<?php echo $post_id; ?>" />
                    <?php } ?>

                    <?php if ( $pack_id ) { ?>
                        <input type="hidden" name="pack_id" value="<?php echo $pack_id; ?>" />
                    <?php } ?>
                    <input type="submit" name="wpuf_payment_submit" value="<?php _e( 'Proceed', 'wpuf' ); ?>"/>
                </p>
            </form>

            <?php
        }

        return ob_get_clean();
    }

    /**
     * Send payment handler to the gateway
     *
     * This function sends the payment handler mechanism to the selected
     * gateway. If 'paypal' is selected, then a particular action is being
     * called. A  listener function can be invoked for that gateway to handle
     * the request and send it to the gateway.
     *
     * Need to use `wpuf_gateway_{$gateway_name}
     */
    function send_to_gateway() {
        if ( isset( $_POST['wpuf_payment_submit'] ) && $_POST['action'] == 'wpuf_pay' && wp_verify_nonce( $_POST['_wpnonce'], 'wpuf_payment_gateway' ) ) {

            $post_id = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;
            $pack_id = isset( $_REQUEST['pack_id'] ) ? intval( $_REQUEST['pack_id'] ) : 0;
            $gateway = $_POST['wpuf_payment_method'];
            $type = $_POST['type'];

            $userdata = wp_get_current_user();

            switch ($type) {
                case 'post':
                    $post = get_post( $post_id );
                    $amount = get_option( 'wpuf_sub_amount' );
                    $item_number = get_post_meta( $post_id, 'wpuf_order_id', true );
                    $item_name = $post->post_title;
                    break;

                case 'pack':
                    $subscription = new WPUF_Subscription();
                    $pack = $subscription->get_subscription( $pack_id );

                    $amount = $pack->cost;
                    $item_name = $pack->name;
                    $item_number = $pack->id;
                    break;
            }

            $payment_vars = array(
                'email' => get_option( 'wpuf_sub_paypal_mail' ),
                'currency' => get_option( 'wpuf_sub_currency' ),
                'price' => $amount,
                'item_number' => $item_number,
                'item_name' => $item_name,
                'type' => $type,
                'user_info' => array(
                    'id' => $userdata->ID,
                    'email' => $userdata->user_email,
                    'first_name' => $userdata->first_name,
                    'last_name' => $userdata->last_name
                ),
                'date' => date( 'Y-m-d H:i:s' ),
                'post_data' => $_POST,
            );

            do_action( 'wpuf_gateway_' . $gateway, $payment_vars );
        }
    }

    /**
     * Insert payment info to database
     *
     * @global object $wpdb
     * @param array $data payment data to insert
     */
    function insert_payment( $data ) {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'wpuf_transaction', $data );

        do_action( 'wpuf_payment_received', $data );
    }

    /**
     * Send payment received mail
     *
     * @param array $info payment information
     */
    function payment_notify_admin( $info ) {
        $headers = "From: " . get_bloginfo( 'name' ) . " <" . get_bloginfo( 'admin_email' ) . ">" . "\r\n\\";
        $subject = sprintf( __( '[%s] Payment Received', 'wpuf' ), get_bloginfo( 'name' ) );
        $msg = sprintf( __( 'New payment received at %s', 'wpuf' ), get_bloginfo( 'name' ) );

        $receiver = get_bloginfo( 'admin_email' );
        wp_mail( $receiver, $subject, $msg, $headers );
    }

}

$wpuf_payment = new WPUF_Payment();
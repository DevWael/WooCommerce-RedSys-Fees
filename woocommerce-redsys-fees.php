<?php
/**
 * Plugin Name: WooCommerce RedSys Fees
 * Plugin URI: https://bbioon.com
 * Description: Add fees any orders with redsys payment gateway, supports woocommerce order deposits.
 * Version: 1.0
 * Author: Ahmad Wael
 * Author URI: https://github.com/devwael
 *
 * Text Domain: woocommerce_redsys_fees
 * WC requires at least: 3.0
 * WC tested up to: 6.1.0
 * WP tested up to: 6.0.2
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * check if woocommerce installed and our class not loaded from any other file
 */
if ( ! class_exists( 'WCRF_Fees_Control' ) ) {
	class WCRF_Fees_Control {
		public $fees_percentage = 0.02; // Percentage (2%) in float
		public $payment_gateway = 'redsys'; // payment method id
		public $fees_title = 'RedSys Fees'; // Fees title
		public static $connection;

		/**
		 * Gets an instance of our fees controller.
		 *
		 * @return WCRF_Fees_Control
		 */
		public static function get_instance() {
			if ( ! isset( self::$connection ) ) {
				self::$connection = new WCRF_Fees_Control();
			}

			return self::$connection;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			add_action( 'woocommerce_cart_calculate_fees', array( $this, 'cart_calculate_fees' ), 90000 );
			add_action( 'woocommerce_review_order_before_payment', array( $this, 'refresh_payment_method_select' ) );
			add_action( 'woocommerce_pay_order_before_submit', array(
				$this,
				'refresh_payment_method_select_order_pay'
			) );
			add_action( 'wp_enqueue_scripts', array( $this, 'localize_scripts' ) );
			add_action( 'wp_ajax_wcrf_calculate_fees', array(
				$this,
				'order_pay_calculate_fees'
			) );
			add_action( 'wp_ajax_nopriv_wcrf_calculate_fees', array( $this, 'order_pay_calculate_fees' ) );
		}

		public function localize_scripts() {
			wp_localize_script( 'jquery', 'wcrf_object', array(
				'ajax_url' => admin_url( 'admin-ajax.php' )
			) );
		}

		/**
		 * check if order has deposit or not
		 */
		public static function has_deposit( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			if ( ! $order ) {
				return false;
			}

			foreach ( $order->get_items() as $item ) {
				if ( 'line_item' === $item['type'] && ! empty( $item['_is_ph_deposits'] ) ) {
					return true;
				}
			}

			return false;
		}

		private function get_deposit_order_id( $order ) {
			if ( is_numeric( $order ) ) {
				$order = wc_get_order( $order );
			}

			if ( ! $order ) {
				return false;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				$deposit_order_id = wc_get_order_item_meta( $item_id, '_remaining_balance_order_id', true );
				if ( $deposit_order_id ) {
					return $deposit_order_id;
				}
			}

			return false;
		}

		/**
		 * Calculate cart fees and add 2% fees if the payment method is redsys
		 * @return void
		 */
		public function cart_calculate_fees( $cart ) {
			if ( ! defined( 'DOING_AJAX' ) && is_admin() ) {
				return;
			}
			$chosen_payment_method = WC()->session->get( 'chosen_payment_method' );
			if ( $this->payment_gateway === $chosen_payment_method ) {
				$percentage_fee = ( WC()->cart->get_subtotal() + WC()->cart->get_shipping_total() + WC()->cart->get_subtotal_tax() ) * $this->fees_percentage;
				WC()->cart->add_fee( $this->fees_title, $percentage_fee );
			}
		}

		/**
		 * Trigger update_checkout event on payment method select.
		 * @return void
		 */
		public function refresh_payment_method_select() {
			?>
            <script type="text/javascript">
                (function ($) {
                    $('form.checkout').on('change', 'input[name^="payment_method"]', function () {
                        $('body').trigger('update_checkout');
                    });
                })(jQuery);
            </script>
			<?php
		}

		/**
		 * Trigger update_checkout event on payment method select.
		 * @return void
		 */
		public function refresh_payment_method_select_order_pay() {
			global $wp;

			if ( isset( $wp->query_vars['order-pay'] ) && absint( $wp->query_vars['order-pay'] ) > 0 ) {
				$order_id = absint( $wp->query_vars['order-pay'] ); // The order ID
				$order    = wc_get_order( $order_id );
				?>
                <input type="hidden" id="wcrf-order-id" name="wcrf-order-id"
                       value="<?php echo esc_attr( $order_id ); ?>">
                <input type="hidden" id="wcrf-payment-type" name="wcrf-payment-type"
                       value="<?php echo esc_attr( $order->get_payment_method() ); ?>">
				<?php
			}
			?>
            <script type="text/javascript">
                (function ($) {
                    // var selected_payment = $(document).find('#wcrf-payment-type');
                    // if(selected_payment.length > 0){
                    // 	$(".payment_methods input[name='payment_method'][value='" + selected_payment.val() + "']").attr("checked", true);
                    // }

                    $('#order_review input.input-radio').on('change', function () {
                        var selected_payment = $(document).find('#wcrf-payment-type');
                        if (selected_payment.length > 0) {
                            $(".payment_methods input[name='payment_method'][value='" + selected_payment.val() + "']").attr("checked", true);
                        }
                        $('.woocommerce').block({
                            message: null,
                            overlayCSS: {
                                background: '#fff',
                                backgroundSize: '16px 16px',
                                opacity: .6
                            }
                        });

                        let data = {
                            action: 'wcrf_calculate_fees',
                            order_id: $('#wcrf-order-id').val(),
                            payment_method: $(this).attr('value'),
                        }

                        jQuery.ajax({
                            type: "POST",
                            url: wcrf_object.ajax_url,
                            dataType: 'json',
                            cache: false,
                            data: data,
                            success: function (response) {
                                if (response.success) {
                                    if (response.recalc) {
                                        $('.shop_table').replaceWith(response.payment_table);
                                        $('#payment .form-row').show();
                                        $('#payment .form-row').show();
                                    }
                                    // $(".payment_methods input[name='payment_method'][value='" + response.payment + "']").attr("checked", true)
                                } else {
                                    $('#payment .form-row').hide();
                                    alert(response.alert);
                                    $('#order_review').before(response.payment_table);
                                }
                                ;
                                // selected_payment.val(response.payment)
                            },
                            error: function (a) {
                                jQuery('#payment .form-row').hide(),
                                    alert(add_fee_vars.alert_ajax_error)
                            },
                            complete: function (a) {
                                $('.woocommerce').unblock()
                            }
                        })
                    });
                    setTimeout(function () {
                        // jQuery( '#payment' ).find( "input[name='payment_method']:checked" ).trigger( 'change' );
                    }, 500);
                })(jQuery);
            </script>
			<?php
		}

		/**
		 * Extract only the payment details table.
		 *
		 * @param $buffer
		 *
		 * @return mixed|string
		 */
		private function extract_order_template( $buffer ) {
			$start = stripos( $buffer, '<table' );

			if ( $start === false ) {
				return $buffer;
			}

			$new_buffer = substr( $buffer, $start );

			$end = stripos( $new_buffer, '</table>' );

			if ( $end === false ) {
				$ret = $new_buffer;
			} else {
				$ret = substr( $new_buffer, 0, $end );
			}

			$ret .= '</table>';

			return $ret;
		}

		/**
		 * Process the add fees request based on selected payment gateway.
		 * @return void
		 * @throws WC_Data_Exception
		 */
		public function order_pay_calculate_fees() {
			$order_id       = isset( $_REQUEST['order_id'] ) ? sanitize_text_field( $_REQUEST['order_id'] ) : 0;
			$payment_method = isset( $_REQUEST['payment_method'] ) ? sanitize_text_field( $_REQUEST['payment_method'] ) : 0;

			if ( ! $order_id ) {
				wp_send_json_error( [
					'bad request order id' //missing order id
				] );
			}

			if ( ! $payment_method ) {
				wp_send_json_error( [
					'bad request payment method' //missing payment method
				] );
			}

			$recalculate = false;

			$order = wc_get_order( $order_id );
			if ( $payment_method == $this->payment_gateway ) {
				//add fees
				$total = $order->get_total();
				$fee   = new WC_Order_Item_Fee();
				$fee->set_name( $this->fees_title );
				$total_fees = number_format( ( $total * $this->fees_percentage ), 2 );
				$fee->set_total( $total_fees );
				$order_fees = $order->get_items( 'fee' );
				if ( empty( $order_fees ) ) {
					$order->add_item( $fee );
					$order->calculate_totals();
					$order->save();
					$recalculate = true;
				} else {
					foreach ( $order_fees as $item_id => $item ) {
						if ( $item->get_name() != $this->fees_title ) {
							$order->add_item( $fee );
							$order->calculate_totals();
							$order->save();
							$recalculate = true;
						}
					}
				}
			} else { //remove fees
				$order_fees = $order->get_items( 'fee' );
				if ( ! empty( $order_fees ) ) {
					foreach ( $order_fees as $item_id => $item ) {
						if ( $item->get_name() == $this->fees_title ) {
							$order->remove_item( $item_id );
							$order->calculate_totals();
							$order->save();
							$recalculate = true;
						}
					};
				}
			}
			$order = wc_get_order( $order_id );
			ob_start();
			$template_loaded = true;
			// $allowed              = array( 'pending', 'failed' );
			// $valid_order_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment', $allowed, $order );
			if ( ! empty( $order->get_billing_country() ) ) {
				WC()->customer->set_billing_country( $order->get_billing_country() );
			}
			if ( ! empty( $order->get_billing_state() ) ) {
				WC()->customer->set_billing_state( $order->get_billing_state() );
			}
			if ( ! empty( $order->get_billing_postcode() ) ) {
				WC()->customer->set_billing_postcode( $order->get_billing_postcode() );
			}

			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
			if ( count( $available_gateways ) ) {
				current( $available_gateways )->set_current();
			}

			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( $gateway_id == $payment_method ) {
					$order->set_payment_method( $gateway_id );
					$order->set_payment_method_title( $gateway->get_title() );
				}
			}

			wc_get_template( 'checkout/form-pay.php', array(
				'order'              => $order,
				'available_gateways' => $available_gateways,
				'order_button_text'  => apply_filters( 'woocommerce_pay_order_button_text', __( 'Pay for order', 'woocommerce_redsys_fees' ) )
			) );

			$buffer = ob_get_contents();
			ob_end_clean();
			$buffer = $this->extract_order_template( $buffer );
			wp_send_json( array(
				'payment_table' => $buffer,
				'success'       => true,
				'recalc'        => $recalculate,
				'payment'       => $payment_method
			), 200 );
		}
	}

	$wcrf_fees_instance = WCRF_Fees_Control::get_instance();
}

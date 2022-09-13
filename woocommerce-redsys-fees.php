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
			add_action( 'woocommerce_thankyou', [ $this, 'add_deposits_order_fees' ], 10, 1 );
			add_action( 'woocommerce_cart_calculate_fees', [ $this, 'cart_calculate_fees' ] );
			add_action( 'woocommerce_review_order_before_payment', [ $this, 'refresh_payment_method_select' ] );
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
		 * Add fees to deposit future order.
		 *
		 * @param $order_id
		 *
		 * @return void
		 */
		public function add_deposits_order_fees( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $this->payment_gateway === $order->get_payment_method() && $this->has_deposit( $order ) ) {
				$deposit_order_id = $this->get_deposit_order_id( $order );
				if ( $deposit_order_id ) {
					$deposit_order = wc_get_order( $deposit_order_id );
					$total         = $deposit_order->get_total();
					$fee           = new WC_Order_Item_Fee();
					$fee->set_name( $this->fees_title );
					$total_fees = number_format( ( $total * $this->fees_percentage ), 2 );
					$fee->set_total( $total_fees );
					$order_fees = $deposit_order->get_items( 'fee' );
					if ( empty( $order_fees ) ) {
						$deposit_order->add_item( $fee );
						$deposit_order->calculate_totals();
						$deposit_order->save();
					} else {
						foreach ( $order_fees as $item_id => $item ) {
							if ( $item->get_name() != $this->fees_title ) {
								$deposit_order->add_item( $fee );
								$deposit_order->calculate_totals();
								$deposit_order->save();
							}
						}
					}
				}
			}
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
	}

	$wcrf_fees_instance = WCRF_Fees_Control::get_instance();
}

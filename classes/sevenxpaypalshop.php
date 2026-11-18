<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * PayPal in the built-in shop (doc/checkout.md). The buttons on the payment step call two JSON routes:
 *
 *   POST sevenx_paypal/shop/create   -> a PayPal order for the shop order of this checkout (the session's
 *                                       MyTemporaryOrderID, the current user's): the order's own total, computed by
 *                                       the shop on the server (coupons, shipping and tax included), in the order's
 *                                       currency; custom_id shop-order:<id>
 *   POST sevenx_paypal/shop/capture  -> captures the approved order, checks the capture against the shop order
 *                                       (status, custom_id, currency, amount) and marks the order paid (status 1000,
 *                                       exactly what the Stripe gateway does); the page then reloads shop/checkout and
 *                                       the payment step accepts the paid order, so activation, mails and every
 *                                       post-checkout integration run unchanged
 *
 * A cancelled or failed approval changes nothing: the order stays unpaid and the buyer can pay with Stripe or try
 * PayPal again. In sandbox mode a capture marks the order paid only with AcceptSandboxPayments=enabled.
 */
class sevenxPayPalShop
{
    const PAID_STATUS_ID = 1000;

    /** @var sevenxPayPalPayments */
    protected $payments;

    public function __construct( sevenxPayPalPayments $payments )
    {
        $this->payments = $payments;
    }

    public static function reference( $orderId )
    {
        return 'shop-order:' . (int)$orderId;
    }

    /** The order's amount in minor units and its currency (upper case), the shop's own numbers. */
    public static function amount( $order )
    {
        $cur = '';
        $collection = is_object( $order ) ? $order->attribute( 'productcollection' ) : null;
        if ( $collection )
            $cur = strtoupper( trim( (string)$collection->attribute( 'currency_code' ) ) );
        if ( !preg_match( '/^[A-Z]{3}$/D', $cur ) )
            $cur = sevenxPayPalConfig::instance()->currency();
        return array( sevenxPayPalMoney::fromDecimal( $order->attribute( 'total_inc_vat' ), $cur ), $cur );
    }

    /** The shop order of this checkout: the session's, else the posted id; the current user's and not paid. */
    public static function checkoutOrder( $postedId = 0 )
    {
        $id = (int)eZHTTPTool::instance()->sessionVariable( 'MyTemporaryOrderID' );
        if ( $id <= 0 )
            $id = (int)$postedId;
        $order = $id > 0 ? eZOrder::fetch( $id ) : null;
        if ( !$order || (int)$order->attribute( 'user_id' ) !== (int)eZUser::currentUserID() )
            return null;
        return $order;
    }

    /**
     * POST create. $o may name return_url and cancel_url (https) for an approval outside the buttons' popup (PayPal's
     * approval page sends the browser there).
     *
     * @return array ok, order_id (PayPal's), approve_url
     */
    public function create( $postedOrderId = 0, array $o = array() )
    {
        $order = self::checkoutOrder( $postedOrderId );
        if ( !$order )
            return array( 'ok' => false, 'message' => 'Your order could not be found. Please go back to the cart.' );
        if ( (int)$order->attribute( 'status_id' ) === self::PAID_STATUS_ID )
            return array( 'ok' => false, 'message' => 'This order has already been paid.', 'paid' => true );
        list( $minor, $cur ) = self::amount( $order );
        if ( $minor <= 0 )
            return array( 'ok' => false, 'message' => 'There is nothing to pay.' );
        $r = $this->payments->begin( array( 'context' => 'shop', 'reference' => self::reference( $order->attribute( 'id' ) ), 'amount_minor' => $minor, 'currency' => $cur,
                                            'intent' => 'CAPTURE', 'description' => 'Order ' . (int)$order->attribute( 'id' ), 'invoice_id' => '',
                                            'user_id' => (int)eZUser::currentUserID(), 'return_url' => isset( $o['return_url'] ) ? (string)$o['return_url'] : '',
                                            'cancel_url' => isset( $o['cancel_url'] ) ? (string)$o['cancel_url'] : '' ) );
        if ( !$r['ok'] )
            return array( 'ok' => false, 'message' => 'PayPal is not available right now. Please pay by card or try again later.' );
        return array( 'ok' => true, 'order_id' => $r['order_id'], 'approve_url' => $r['approve_url'] );
    }

    /** POST capture. @return array ok, paid (bool), message */
    public function capture( $paypalOrderId, $postedOrderId = 0 )
    {
        $order = self::checkoutOrder( $postedOrderId );
        if ( !$order )
            return array( 'ok' => false, 'message' => 'Your order could not be found. Nothing was charged; please go back to the cart.' );
        $ref = self::reference( $order->attribute( 'id' ) );
        $tx = $this->payments->transaction( (string)$paypalOrderId );
        if ( !$tx || $tx['reference'] !== $ref || (int)$tx['user_id'] !== (int)eZUser::currentUserID() )
            return array( 'ok' => false, 'message' => 'This payment does not belong to the order in your cart. Nothing was charged.' );
        // the order's total must still be what the PayPal order was created for (the cart did not change meanwhile)
        list( $minor, $cur ) = self::amount( $order );
        if ( $minor !== (int)$tx['amount_minor'] || $cur !== $tx['currency'] )
            return array( 'ok' => false, 'message' => 'Your order total changed. Nothing was charged; please pay again.', 'restart' => true );
        $r = $this->payments->capture( (string)$paypalOrderId, $ref );
        if ( !$r['ok'] )
            return array( 'ok' => false, 'message' => !empty( $r['retry_buyer'] ) ? 'PayPal declined this way to pay. Please choose another one.'
                                                     : 'The payment did not go through. Nothing was charged; please try again or pay by card.',
                          'restart' => !empty( $r['retry_buyer'] ) );
        if ( !empty( $r['pending'] ) )
            return array( 'ok' => true, 'paid' => false, 'message' => 'PayPal is still processing your payment. Your order is completed as soon as PayPal confirms it.' );
        return self::markPaid( $order, $r['transaction'], sevenxPayPalConfig::instance( $tx['mode'] ) );
    }

    /** Marks a shop order paid for a completed capture (the buttons, or the webhook). */
    public static function markPaid( $order, array $tx, sevenxPayPalConfig $cfg )
    {
        if ( !$cfg->acceptsPayment() )
        {
            sevenxPayPalPayments::log( 'sandbox capture not accepted for order=' . (int)$order->attribute( 'id' ) . ' (AcceptSandboxPayments=disabled)' );
            return array( 'ok' => true, 'paid' => false, 'message' => 'A sandbox payment was verified. Sandbox payments do not complete orders here.' );
        }
        if ( (int)$order->attribute( 'status_id' ) !== self::PAID_STATUS_ID )
        {
            $order->modifyStatus( self::PAID_STATUS_ID );
            sevenxPayPalPayments::log( 'paid order=' . (int)$order->attribute( 'id' ) . ' paypal_order=' . $tx['paypal_order_id'] . ' capture=' . $tx['capture_id'] );
        }
        return array( 'ok' => true, 'paid' => true, 'message' => 'Thank you: your payment was received.' );
    }

    /** The webhook's part: a completed capture of a shop order marks it paid if the buttons did not. */
    public static function paidFromWebhook( ?array $tx, sevenxPayPalConfig $cfg )
    {
        if ( !$tx || $tx['status'] !== 'COMPLETED' || strncmp( $tx['reference'], 'shop-order:', 11 ) !== 0 || !class_exists( 'eZOrder' ) )
            return 'ignored';
        $order = eZOrder::fetch( (int)substr( $tx['reference'], 11 ) );
        if ( !$order )
            return 'unknown';
        list( $minor, $cur ) = self::amount( $order );
        if ( $minor !== (int)$tx['amount_minor'] || $cur !== $tx['currency'] )
            return 'mismatch';
        $was = (int)$order->attribute( 'status_id' ) === self::PAID_STATUS_ID;
        $r = self::markPaid( $order, $tx, $cfg );
        return $was ? 'already' : ( $r['paid'] ? 'paid' : 'sandbox' );
    }

    /** The PayPal payment of a shop order (for other extensions: the capture id of a paid order), or null. */
    public static function paymentOf( $shopOrderId, ?sevenxPayPalStore $store = null )
    {
        $store = $store ?: new sevenxPayPalDbStore();
        foreach ( $store->find( sevenxPayPalPayments::T, array( 'reference' => self::reference( $shopOrderId ) ), 'id DESC', 10 ) as $r )
            if ( in_array( $r['status'], array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ), true ) )
                return $r;
        return null;
    }
}

<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The shop payment gateway "Sevenx_PayPal" (doc/checkout.md "PayPal as the only gateway"): for a shop whose payment
 * workflow event offers PayPal on its own. The kernel loads this file because it is named after the extension
 * (classes/sevenx_paypalgateway.php). A shop that offers a card gateway as well keeps that one in the workflow event
 * and shows the PayPal buttons next to its form (design:sevenx_paypal/shop_buttons.tpl): the kernel's own choice
 * between several gateways asks the buyer in an extra step.
 *
 *   1. execute() / loadForm(): an unpaid order shows design:workflow/eventtype/result/sevenx_paypal_form.tpl, the buttons;
 *   2. the buttons create and capture through sevenx_paypal/shop/* and mark the order paid (1000);
 *   3. the page reloads shop/checkout: the order is paid, the event answers STATUS_ACCEPTED and the kernel activates it.
 * A free order (a 100 % coupon) is accepted without PayPal.
 */
class sevenx_paypalGateway extends eZPaymentGateway
{
    const GATEWAY_TYPE = 'Sevenx_PayPal';

    public function __construct()
    {
    }

    function execute( $process, $event )
    {
        $params = $process->attribute( 'parameter_list' );
        $order = eZOrder::fetch( isset( $params['order_id'] ) ? (int)$params['order_id'] : 0 );
        if ( $order && (int)$order->attribute( 'status_id' ) === sevenxPayPalShop::PAID_STATUS_ID )
            return eZWorkflowEventType::STATUS_ACCEPTED;
        if ( $order && round( (float)$order->attribute( 'total_inc_vat' ), 2 ) <= 0 )
            return eZWorkflowEventType::STATUS_ACCEPTED;
        $process->Template = array( 'templateName' => 'design:workflow/eventtype/result/sevenx_paypal_form.tpl',
                                    'templateVars' => array( 'order_id' => $order ? (int)$order->attribute( 'id' ) : 0 ),
                                    'path' => array( array( 'url' => false, 'text' => 'Payment' ) ) );
        return eZWorkflowEventType::STATUS_FETCH_TEMPLATE_REPEAT;
    }

    function needCleanup()
    {
        return false;
    }
}

eZPaymentGatewayType::registerGateway( sevenx_paypalGateway::GATEWAY_TYPE, 'sevenx_paypalGateway', 'PayPal' );

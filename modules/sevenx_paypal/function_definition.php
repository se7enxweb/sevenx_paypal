<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * fetch( 'sevenx_paypal', 'checkout', hash( 'intent', 'capture'|'authorize', 'currency', 'USD' ) ): what a page needs to
 * show the PayPal buttons to the current user (doc/checkout.md "Templates").
 */
$FunctionList = array();
$FunctionList['checkout'] = array( 'name' => 'checkout', 'operation_types' => array( 'read' ),
    'call_method' => array( 'class' => 'sevenxPayPalFunctionCollection', 'method' => 'checkout' ),
    'parameter_type' => 'standard',
    'parameters' => array( array( 'name' => 'intent', 'type' => 'string', 'required' => false, 'default' => 'capture' ),
                           array( 'name' => 'currency', 'type' => 'string', 'required' => false, 'default' => '' ),
                           array( 'name' => 'context', 'type' => 'string', 'required' => false, 'default' => 'shop' ) ) );

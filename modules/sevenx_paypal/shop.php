<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * sevenx_paypal/shop/create and sevenx_paypal/shop/capture: the JSON routes of the shop's PayPal buttons
 * (doc/checkout.md). POST only; a signed-in buyer's request carries the form token (X-CSRF-Token, ezformtoken).
 * The amount is never read from the request: create prices the session's order on the server.
 */

$Module = $Params['Module'];
$action = isset( $Params['Action'] ) ? (string)$Params['Action'] : '';
$send = function ( $status, array $doc ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    http_response_code( (int)$status );
    echo json_encode( $doc, JSON_UNESCAPED_SLASHES );
    eZExecution::cleanExit();
};
if ( !isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' )
    $send( 405, array( 'ok' => false, 'message' => 'POST only' ) );
$in = json_decode( (string)file_get_contents( 'php://input', false, null, 0, 8192 ), true );
$in = is_array( $in ) ? $in : array();
$cfg = sevenxPayPalConfig::instance();
if ( !$cfg->enabledForCurrentUser() || strtolower( (string)$cfg->value( 'ShopCheckout', 'disabled' ) ) !== 'enabled' )
    $send( 403, array( 'ok' => false, 'message' => 'PayPal is not available here.' ) );
try
{
    $shop = new sevenxPayPalShop( sevenxPayPalPayments::instance() );
    $posted = isset( $in['orderId'] ) ? (int)$in['orderId'] : 0;
    if ( $action === 'create' )
        $r = $shop->create( $posted );
    else if ( $action === 'capture' )
        $r = $shop->capture( isset( $in['paypalOrderId'] ) && is_string( $in['paypalOrderId'] ) ? $in['paypalOrderId'] : '', $posted );
    else
        $send( 404, array( 'ok' => false, 'message' => 'not found' ) );
}
catch ( sevenxPayPalError $e )
{
    sevenxPayPalPayments::log( 'shop ' . $action . ' failed: ' . $e->name );
    $send( 503, array( 'ok' => false, 'message' => 'PayPal is not available right now. Please pay by card or try again later.' ) );
}
$send( $r['ok'] ? 200 : 422, $r );

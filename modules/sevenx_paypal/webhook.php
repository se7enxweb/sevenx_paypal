<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * sevenx_paypal/webhook: PayPal's webhook deliveries (doc/webhooks.md). POST JSON only. The delivery is verified,
 * stored once by event id and handed to the handlers; the answer is JSON with a status PayPal understands (2xx: done,
 * anything else: PayPal delivers again later).
 */

$send = function ( $status, array $doc ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    http_response_code( (int)$status );
    echo json_encode( $doc, JSON_UNESCAPED_SLASHES );
    eZExecution::cleanExit();
};

if ( !isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' )
    $send( 405, array( 'ok' => false, 'error' => 'POST only' ) );
$raw = (string)file_get_contents( 'php://input', false, null, 0, 1048577 );
if ( $raw === '' || strlen( $raw ) > 1048576 )
    $send( 400, array( 'ok' => false, 'error' => 'no event' ) );
$headers = array();
foreach ( $_SERVER as $k => $v )
    if ( strncmp( $k, 'HTTP_PAYPAL_', 12 ) === 0 )
        $headers[substr( $k, 5 )] = (string)$v;

// the event is of the mode whose webhook signed it: the configured mode first, then the other one (a site that
// moves from sandbox to live keeps receiving the sandbox app's late deliveries for a while)
$cfg = sevenxPayPalConfig::instance();
$modes = array_unique( array( $cfg->mode, $cfg->mode === 'live' ? 'sandbox' : 'live' ) );
$last = array( 'status' => 503, 'result' => 'not configured' );
foreach ( $modes as $mode )
{
    $c = sevenxPayPalConfig::instance( $mode );
    if ( !$c->ok() || $c->webhookId === '' )
        continue;
    $hook = new sevenxPayPalWebhook( new sevenxPayPalPayments( new sevenxPayPalClient( $c ) ) );
    $last = $hook->receive( $headers, $raw );
    if ( $last['status'] !== 401 )
        break;
}
$send( $last['status'], array( 'ok' => $last['status'] < 300, 'result' => $last['status'] === 401 ? 'signature' : $last['result'] ) );

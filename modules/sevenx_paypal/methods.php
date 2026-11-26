<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * sevenx_paypal/methods (doc/vault.md "The buyer's page"): the signed-in user's saved PayPal methods. GET lists them;
 * POST RemoveMethodButton + MethodID removes one at PayPal and here; POST JSON methods/setup creates a setup token for
 * the PayPal buttons (createVaultSetupToken) and methods/finish turns the approved one into a saved method. Each user
 * sees and changes only their own methods. Only ids, the method and a masked label are shown.
 */

$Module = $Params['Module'];
$action = isset( $Params['Action'] ) ? (string)$Params['Action'] : '';
$user = eZUser::currentUser();
$cfg = sevenxPayPalConfig::instance();
$on = $user->isRegistered() && $cfg->enabledForCurrentUser() && sevenxPayPalVault::enabled( $cfg );
$json = function ( $status, array $doc ) {
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    http_response_code( (int)$status );
    echo json_encode( $doc, JSON_UNESCAPED_SLASHES );
    eZExecution::cleanExit();
};
if ( $action === 'setup' || $action === 'finish' )
{
    if ( !isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' )
        $json( 405, array( 'ok' => false, 'message' => 'POST only' ) );
    if ( !$on )
        $json( 403, array( 'ok' => false, 'message' => 'Saving PayPal is not available here.' ) );
    $in = json_decode( (string)file_get_contents( 'php://input', false, null, 0, 4096 ), true );
    $vault = sevenxPayPalVault::instance();
    if ( $action === 'setup' )
    {
        $r = $vault->startSetup( (int)$user->id() );
        $json( $r['ok'] ? 200 : 422, $r['ok'] ? array( 'ok' => true, 'order_id' => $r['setup_token'] ) : array( 'ok' => false, 'message' => 'PayPal could not start saving the account. Please try again later.' ) );
    }
    $token = is_array( $in ) && isset( $in['vaultSetupToken'] ) && is_string( $in['vaultSetupToken'] ) ? $in['vaultSetupToken'] : '';
    $r = $vault->finishSetup( (int)$user->id(), $token, 'account' );
    $json( $r['ok'] ? 200 : 422, $r['ok'] ? array( 'ok' => true, 'paid' => false, 'message' => 'Your PayPal account was saved.', 'redirect' => '' )
                                          : array( 'ok' => false, 'message' => 'PayPal did not save the account. Nothing was changed.' ) );
}
if ( !$user->isRegistered() )
    return $Module->handleError( eZError::KERNEL_ACCESS_DENIED, 'kernel' );
$messages = array();
$errors = array();
$vault = $on ? sevenxPayPalVault::instance() : null;
if ( $on && $Module->isCurrentAction( 'Remove' ) )
{
    $row = $vault->method( (int)$user->id(), (int)$Module->actionParameter( 'MethodID' ) );
    $r = $row ? $vault->delete( $row ) : array( 'ok' => false, 'message' => 'not found' );
    $r['ok'] ? $messages[] = 'The saved PayPal method was removed.' : $errors[] = 'The method could not be removed. Please try again later.';
}
$tpl = eZTemplate::factory();
$tpl->setVariable( 'enabled', $on );
$tpl->setVariable( 'methods', $on ? $vault->methods( (int)$user->id() ) : array() );
$tpl->setVariable( 'messages', $messages );
$tpl->setVariable( 'errors', $errors );
$Result = array( 'content' => $tpl->fetch( 'design:sevenx_paypal/methods.tpl' ),
                 'path' => array( array( 'url' => false, 'text' => 'Saved PayPal' ) ) );

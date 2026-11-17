<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * sevenx_paypal/status in the administration (doc/operations.md "The status page"): the mode, whether PayPal is
 * offered and to whom, which credentials are present (names only, never a value), the webhook of this site in the
 * mode's PayPal app, the last events and payments (ids, types, states and amounts; no payer data). Two actions:
 * "Check the connection" (a token and the webhook list) and "Create the webhook" (only when the app has none for
 * this site's URL; existing webhooks of other systems are never changed).
 */

$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$tpl = eZTemplate::factory();
$cfg = sevenxPayPalConfig::instance();
$messages = array();
$errors = array();
$check = null;
$hookUrl = sevenxPayPalSetup::webhookUrl( $cfg );

if ( $Module->isCurrentAction( 'CheckConnection' ) || $Module->isCurrentAction( 'CreateWebhook' ) )
{
    if ( !$cfg->ok() )
        $errors[] = 'PayPal is not set up: ' . $cfg->problem;
    else
    {
        $client = new sevenxPayPalClient( $cfg );
        try
        {
            if ( $Module->isCurrentAction( 'CreateWebhook' ) )
            {
                $r = sevenxPayPalSetup::ensureWebhook( $client, $hookUrl );
                $r['ok'] ? $messages[] = $r['message'] : $errors[] = $r['message'];
            }
            $check = sevenxPayPalSetup::check( $client, $hookUrl );
            $messages[] = 'Connected to the PayPal ' . $cfg->mode . ' API.';
        }
        catch ( sevenxPayPalError $e )
        {
            $errors[] = 'PayPal answered: ' . $e->getMessage() . ( $e->debugId !== '' ? ' (debug id ' . $e->debugId . ')' : '' );
        }
    }
}

$events = array();
$payments = array();
if ( sevenxPayPalDbStore::installed() )
{
    $store = new sevenxPayPalDbStore();
    $events = $store->find( 'sevenx_paypal_event', array(), 'id DESC', 20 );
    foreach ( $store->find( 'sevenx_paypal_transaction', array(), 'id DESC', 20 ) as $t )
    {
        $t['amount'] = sevenxPayPalMoney::toValue( (int)$t['amount_minor'], $t['currency'] ) . ' ' . $t['currency'];
        $t['refunded'] = (int)$t['refunded_minor'] > 0 ? sevenxPayPalMoney::toValue( (int)$t['refunded_minor'], $t['currency'] ) . ' ' . $t['currency'] : '';
        $payments[] = $t;
    }
}
else
    $errors[] = 'The tables of sevenx_paypal are missing: run the schema of sql/<database>/schema.sql (doc/install.md).';

$tpl->setVariable( 'state', $cfg->describe() );
$tpl->setVariable( 'enabled', (string)$cfg->value( 'Enabled', 'disabled' ) );
$tpl->setVariable( 'shop', (string)$cfg->value( 'ShopCheckout', 'disabled' ) );
$tpl->setVariable( 'testers', count( $cfg->listValue( 'TestUsers' ) ) );
$tpl->setVariable( 'sites', $cfg->listValue( 'EnabledSiteAccesses' ) );
$tpl->setVariable( 'accept_sandbox', $cfg->acceptsPayment() );
$tpl->setVariable( 'hook_url', $hookUrl );
$tpl->setVariable( 'check', $check );
$tpl->setVariable( 'events', $events );
$tpl->setVariable( 'payments', $payments );
$tpl->setVariable( 'messages', $messages );
$tpl->setVariable( 'errors', $errors );

$Result = array( 'content' => $tpl->fetch( 'design:sevenx_paypal/status.tpl' ),
                 'path' => array( array( 'url' => false, 'text' => 'PayPal' ) ) );

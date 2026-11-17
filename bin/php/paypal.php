#!/usr/bin/env php
<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 *
 * The console of sevenx_paypal (doc/operations.md "Console"), from the Exponential root:
 *
 *   php extension/sevenx_paypal/bin/php/paypal.php --status [--mode=sandbox|live]
 *       the credentials (present or not; never a value), a token, the app id and the app's webhooks
 *   php extension/sevenx_paypal/bin/php/paypal.php --create-webhook [--mode=...] [--url=https://...]
 *       creates this site's webhook in the mode's app if it has none for the URL, and stores its id in the secret
 *       store; webhooks of other URLs are never touched
 *   php extension/sevenx_paypal/bin/php/paypal.php --replay=<PayPal event id> [--mode=...]
 *       reads the event from PayPal's API (no signature needed: it comes from PayPal over the API) and runs the
 *       handlers again for it
 */
require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => 'sevenx_paypal console', 'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[status][create-webhook][url:][mode:][replay:]', '', array( 'status' => 'Connection and webhooks', 'create-webhook' => 'Create this site\'s webhook',
                                'url' => 'The webhook URL', 'mode' => 'sandbox or live (default: [PayPalSettings] Mode)', 'replay' => 'Apply an event again' ) );
$script->initialize();

$mode = $options['mode'] ? (string)$options['mode'] : null;
$cfg = sevenxPayPalConfig::instance( $mode );
$cli->output( 'mode: ' . $cfg->mode . ', api ' . $cfg->apiBase() );
$d = $cfg->describe();
$cli->output( 'client id: ' . ( $d['client_id'] ? 'present' : 'missing' ) . ' (' . $d['names']['client_id'] . '), secret: ' . ( $d['secret'] ? 'present' : 'missing' )
              . ' (' . $d['names']['secret'] . '), webhook id: ' . ( $cfg->webhookId !== '' ? $cfg->webhookId : 'none' ) );
if ( !$cfg->ok() )
{
    $cli->error( 'FAIL: ' . $cfg->problem );
    $script->shutdown( 1 );
}
$client = new sevenxPayPalClient( $cfg );
$url = $options['url'] ? (string)$options['url'] : sevenxPayPalSetup::webhookUrl( $cfg );
$rc = 0;
try
{
    if ( $options['create-webhook'] )
    {
        $r = sevenxPayPalSetup::ensureWebhook( $client, $url );
        $cli->output( ( $r['ok'] ? '' : 'FAIL: ' ) . $r['message'] );
        $rc = $r['ok'] ? 0 : 1;
    }
    if ( $options['replay'] )
    {
        $id = (string)$options['replay'];
        if ( !preg_match( '/^WH-[A-Z0-9-]{8,60}$/D', $id ) )
            throw new sevenxPayPalError( 'not a PayPal event id (WH-...)', 'invalid_id' );
        $event = $client->call( 'GET', '/v1/notifications/webhooks-events/' . rawurlencode( $id ) );
        $hook = new sevenxPayPalWebhook( new sevenxPayPalPayments( $client ) );
        $store = new sevenxPayPalDbStore();
        $row = $store->find( 'sevenx_paypal_event', array( 'event_id' => $id ), 'id', 1 );
        $rowId = $row ? (int)$row[0]['id'] : $store->insert( 'sevenx_paypal_event', sevenxPayPalWebhook::summary( $event ) + array( 'mode' => $cfg->mode, 'verified' => 1,
                                                                                                                                    'received' => time(), 'processed' => 0, 'result' => '', 'attempts' => 0 ) );
        $r = $hook->apply( $rowId, $event );
        $cli->output( 'replayed ' . $id . ': ' . $r['result'] );
        $rc = $r['status'] < 300 ? $rc : 1;
    }
    if ( $options['status'] || ( !$options['create-webhook'] && !$options['replay'] ) )
    {
        $c = sevenxPayPalSetup::check( $client, $url );
        $cli->output( 'token: ok, app ' . $c['app_id'] . ', ' . $c['webhooks'] . ' webhook(s) in the app' );
        foreach ( $client->listWebhooks() as $w )
            $cli->output( '  ' . $w['id'] . '  ' . $w['url'] . '  (' . count( isset( $w['event_types'] ) ? $w['event_types'] : array() ) . ' events)' );
        $cli->output( 'this site (' . $url . '): ' . ( $c['webhook_found'] ? 'webhook ' . $c['webhook_id'] . ( $c['stored_id_matches'] ? ', id stored' : ', the stored id differs' )
                                                                         . ( $c['missing_events'] ? ', missing events ' . implode( ' ', $c['missing_events'] ) : '' ) : 'no webhook' ) );
    }
}
catch ( sevenxPayPalError $e )
{
    $cli->error( 'FAIL: ' . $e->getMessage() . ( $e->debugId !== '' ? ' (debug id ' . $e->debugId . ')' : '' ) );
    $rc = 1;
}
$cli->output( $rc ? 'FAIL' : 'PASS' );
$script->shutdown( $rc );

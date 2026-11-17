<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

$Module = array( 'name' => 'sevenx_paypal', 'variable_params' => true );

// PayPal's webhook deliveries (doc/webhooks.md): POST only, no sign-in; the signature is the credential (PolicyOmitList)
$ViewList['webhook'] = array( 'script' => 'webhook.php', 'functions' => array(), 'params' => array() );

// the shop's PayPal buttons (doc/checkout.md): POST JSON create and capture for the order of the current checkout;
// the order must be the session's and the user's, so they are open like the shop's own checkout (PolicyOmitList)
$ViewList['shop'] = array( 'script' => 'shop.php', 'functions' => array(), 'params' => array( 'Action' ) );

// the administration page (doc/operations.md): mode, credentials (present or not), webhook state, last events and payments
$ViewList['status'] = array( 'script' => 'status.php', 'functions' => array( 'admin' ), 'default_navigation_part' => 'ezsetupnavigationpart',
                             'single_post_actions' => array( 'CheckConnectionButton' => 'CheckConnection', 'CreateWebhookButton' => 'CreateWebhook' ),
                             'params' => array() );

$FunctionList = array();
$FunctionList['admin'] = array();

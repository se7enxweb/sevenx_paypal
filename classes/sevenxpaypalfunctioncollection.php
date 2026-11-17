<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/** The template fetch functions of sevenx_paypal (modules/sevenx_paypal/function_definition.php). */
class sevenxPayPalFunctionCollection
{
    /**
     * Whether the current user gets the PayPal buttons here, and the SDK URL to load (public values only: the client id
     * is public by design). context shop also needs ShopCheckout=enabled.
     *
     * @return array result: enabled, sdk_url, mode, currency, sandbox
     */
    public static function checkout( $intent = 'capture', $currency = '', $context = 'shop' )
    {
        $cfg = sevenxPayPalConfig::instance();
        $on = $cfg->enabledForCurrentUser() && ( $context !== 'shop' || strtolower( (string)$cfg->value( 'ShopCheckout', 'disabled' ) ) === 'enabled' );
        $cur = $currency !== '' ? strtoupper( (string)$currency ) : $cfg->currency();
        return array( 'result' => array( 'enabled' => $on, 'sdk_url' => $on ? $cfg->sdkUrl( $intent, $cur ) : '', 'mode' => $cfg->mode,
                                         'sandbox' => $cfg->mode !== 'live', 'currency' => $cur ) );
    }
}

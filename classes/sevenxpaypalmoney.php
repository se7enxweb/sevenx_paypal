<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * Amount math (pure). Amounts are kept as integers of the currency's minor unit everywhere ("cents"); PayPal takes and
 * gives decimal strings ("12.34", "1000" for JPY). Never a float in between.
 */
class sevenxPayPalMoney
{
    /** Currencies PayPal accepts without decimals (PayPal's currency codes reference). */
    const ZERO_DECIMAL = array( 'HUF', 'JPY', 'TWD' );

    public static function decimals( $currency )
    {
        return in_array( strtoupper( (string)$currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
    }

    /** Minor units to PayPal's value string: 1234 USD -> "12.34", 1000 JPY -> "1000". */
    public static function toValue( $minor, $currency )
    {
        $minor = (int)$minor;
        if ( self::decimals( $currency ) === 0 )
            return (string)$minor;
        $neg = $minor < 0;
        $a = abs( $minor );
        return ( $neg ? '-' : '' ) . intdiv( $a, 100 ) . '.' . str_pad( (string)( $a % 100 ), 2, '0', STR_PAD_LEFT );
    }

    /**
     * PayPal's value string to minor units: "12.34" USD -> 1234, "12.3" -> 1230, "1000" JPY -> 1000. A value with more
     * decimals than the currency has, or anything that is not a plain decimal, is refused (null): a payment is never
     * rounded into matching.
     */
    public static function toMinor( $value, $currency )
    {
        $v = trim( (string)$value );
        $d = self::decimals( $currency );
        if ( !preg_match( '/^(-?)(\d{1,12})(?:\.(\d{1,' . max( 1, $d ) . '}))?$/D', $v, $m ) )
            return null;
        if ( $d === 0 && isset( $m[3] ) && $m[3] !== '' && (int)$m[3] !== 0 )
            return null;
        $frac = $d === 0 ? 0 : (int)str_pad( isset( $m[3] ) ? $m[3] : '', 2, '0' );
        $n = (int)$m[2] * ( $d === 0 ? 1 : 100 ) + $frac;
        return $m[1] === '-' ? -$n : $n;
    }

    /** A shop amount (a decimal number of the kernel, e.g. total_inc_vat) to minor units, as the Stripe gateway does it. */
    public static function fromDecimal( $amount, $currency = 'USD' )
    {
        $f = round( (float)$amount, self::decimals( $currency ) );
        return (int)round( $f * ( self::decimals( $currency ) === 0 ? 1 : 100 ) );
    }

    /** PayPal's amount object of minor units. */
    public static function amount( $minor, $currency )
    {
        return array( 'currency_code' => strtoupper( (string)$currency ), 'value' => self::toValue( $minor, $currency ) );
    }
}

<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The parts of a webhook delivery's signature (pure), and PayPal's own algorithm for checking it without an API call
 * (doc/webhooks.md "Signature verification"): the signed string is
 *
 *     <transmission id>|<transmission time>|<webhook id>|<CRC32 of the raw body, unsigned decimal>
 *
 * signed SHA256withRSA by the key of the certificate at PAYPAL-CERT-URL. The default verification asks PayPal
 * (verify-webhook-signature); this local check is the one the tests use with recorded samples, and the setting
 * [WebhookSettings] Verification=local|both switches it on.
 */
class sevenxPayPalWebhookSignature
{
    /** The hosts a certificate may come from. */
    const CERT_HOSTS = array( 'api.paypal.com', 'api-m.paypal.com', 'api.sandbox.paypal.com', 'api-m.sandbox.paypal.com' );

    /**
     * The signature headers of a delivery, any case (PAYPAL-TRANSMISSION-ID or HTTP_PAYPAL_TRANSMISSION_ID), or null
     * when one is missing or malformed.
     *
     * @return array|null transmission_id, transmission_time, transmission_sig, cert_url, auth_algo
     */
    public static function headers( array $in )
    {
        $h = array();
        foreach ( $in as $k => $v )
        {
            $k = strtolower( str_replace( '_', '-', (string)$k ) );
            if ( strncmp( $k, 'http-', 5 ) === 0 )
                $k = substr( $k, 5 );
            $h[$k] = is_array( $v ) ? (string)reset( $v ) : (string)$v;
        }
        $map = array( 'transmission_id' => 'paypal-transmission-id', 'transmission_time' => 'paypal-transmission-time',
                      'transmission_sig' => 'paypal-transmission-sig', 'cert_url' => 'paypal-cert-url', 'auth_algo' => 'paypal-auth-algo' );
        $out = array();
        foreach ( $map as $name => $header )
        {
            if ( !isset( $h[$header] ) || trim( $h[$header] ) === '' )
                return null;
            $out[$name] = trim( $h[$header] );
        }
        if ( !preg_match( '/^[A-Za-z0-9-]{8,64}$/D', $out['transmission_id'] ) || !preg_match( '/^[A-Za-z0-9+\/=]{16,1024}$/D', $out['transmission_sig'] )
             || !preg_match( '/^[A-Za-z0-9-]{3,32}$/D', $out['auth_algo'] ) || strlen( $out['transmission_time'] ) > 40 || !self::certUrlOk( $out['cert_url'] ) )
            return null;
        return $out;
    }

    /** A certificate URL is https on one of PayPal's API hosts. */
    public static function certUrlOk( $url )
    {
        $p = parse_url( (string)$url );
        return is_array( $p ) && isset( $p['scheme'], $p['host'] ) && $p['scheme'] === 'https' && in_array( strtolower( $p['host'] ), self::CERT_HOSTS, true )
               && !isset( $p['user'] ) && !isset( $p['port'] );
    }

    /** The string PayPal signs. */
    public static function signedString( $transmissionId, $transmissionTime, $webhookId, $rawBody )
    {
        return $transmissionId . '|' . $transmissionTime . '|' . $webhookId . '|' . sprintf( '%u', crc32( (string)$rawBody ) );
    }

    /**
     * Checks a delivery against a certificate (PEM) without asking PayPal: true when the signature is the
     * certificate key's signature of the signed string. The certificate must be valid now ($now for the tests).
     */
    public static function verifyLocal( array $headers, $rawBody, $webhookId, $certPem, $now = null )
    {
        $h = self::headers( $headers );
        if ( $h === null || (string)$webhookId === '' || !function_exists( 'openssl_verify' ) )
            return false;
        if ( strtoupper( $h['auth_algo'] ) !== 'SHA256WITHRSA' )
            return false;
        $cert = @openssl_x509_read( (string)$certPem );
        if ( !$cert )
            return false;
        $info = openssl_x509_parse( $cert );
        $t = $now !== null ? (int)$now : time();
        if ( !$info || $t < (int)$info['validFrom_time_t'] || $t > (int)$info['validTo_time_t'] )
            return false;
        $key = openssl_pkey_get_public( $cert );
        if ( !$key )
            return false;
        $sig = base64_decode( $h['transmission_sig'], true );
        if ( $sig === false )
            return false;
        return openssl_verify( self::signedString( $h['transmission_id'], $h['transmission_time'], (string)$webhookId, (string)$rawBody ), $sig, $key,
                               OPENSSL_ALGO_SHA256 ) === 1;
    }

    /** The certificate of a delivery, fetched from PayPal (https, PayPal's API hosts only). */
    public static function fetchCert( $url )
    {
        if ( !self::certUrlOk( $url ) )
            return '';
        $c = curl_init( $url );
        curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
                                      CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false ) );
        $pem = curl_exec( $c );
        $ok = (int)curl_getinfo( $c, CURLINFO_HTTP_CODE ) === 200;
        return $ok && is_string( $pem ) && strpos( $pem, 'BEGIN CERTIFICATE' ) !== false ? $pem : '';
    }
}

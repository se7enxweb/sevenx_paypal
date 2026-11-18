<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * PayPal's current REST APIs (doc/overview.md): OAuth 2 client credentials, Orders v2 (create, show, capture,
 * authorize), Payments v2 (authorizations: show, capture, void; captures: show, refund; refunds: show) and
 * Webhooks v1 (list, create, verify a signature). JSON over HTTPS; every call that creates something carries a
 * PayPal-Request-Id, so a repeated call gets PayPal's first answer back instead of a second payment.
 *
 * Not used and never used: the classic NVP/SOAP APIs, IPN and the "Website Payments Standard" redirect of ezpaypal.
 */
class sevenxPayPalClient
{
    /** The events the webhook subscribes to (doc/webhooks.md). */
    const WEBHOOK_EVENTS = array( 'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING',
                                  'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED',
                                  'PAYMENT.AUTHORIZATION.CREATED', 'PAYMENT.AUTHORIZATION.VOIDED' );

    /** @var sevenxPayPalConfig */
    protected $config;
    /** @var sevenxPayPalHttp */
    protected $http;
    /** @var array mode|client id hash => array( token, expires ) */
    protected static $tokens = array();
    /** @var string the app id of the last token answer (shown on the status page; not a secret) */
    public $appId = '';

    public function __construct( sevenxPayPalConfig $config, ?sevenxPayPalHttp $http = null )
    {
        $this->config = $config;
        $this->http = $http ?: new sevenxPayPalCurlHttp( (int)$config->value( 'ApiTimeout', 60 ) );
    }

    /**
     * The client of the configured mode.
     *
     * @throws sevenxPayPalError when the credentials are missing or unusable
     */
    public static function fromSettings( $mode = null, ?sevenxPayPalHttp $http = null )
    {
        $c = sevenxPayPalConfig::instance( $mode );
        if ( !$c->ok() && !$http )
            throw new sevenxPayPalError( 'PayPal is not set up: ' . $c->problem, 'not_configured' );
        return new self( $c, $http );
    }

    public function config()
    {
        return $this->config;
    }

    public function mode()
    {
        return $this->config->mode;
    }

    /** Forgets cached tokens (tests). */
    public static function resetTokens()
    {
        self::$tokens = array();
    }

    // ------------------------------------------------------------------------------------------- transport

    /** An access token (client credentials), kept in this process until a minute before it expires. */
    public function token( $fresh = false )
    {
        $key = $this->config->mode . '|' . hash( 'sha256', $this->config->clientId );
        if ( !$fresh && isset( self::$tokens[$key] ) && self::$tokens[$key][1] > time() + 60 )
            return self::$tokens[$key][0];
        $r = $this->http->request( 'POST', $this->config->apiBase() . '/v1/oauth2/token',
                                   array( 'Authorization' => 'Basic ' . base64_encode( $this->config->clientId . ':' . $this->config->secret ),
                                          'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json' ),
                                   'grant_type=client_credentials' );
        if ( $r['status'] !== 200 || empty( $r['body']['access_token'] ) )
            throw sevenxPayPalError::fromResponse( $r );
        $this->appId = isset( $r['body']['app_id'] ) ? (string)$r['body']['app_id'] : '';
        self::$tokens[$key] = array( (string)$r['body']['access_token'], time() + (int)( isset( $r['body']['expires_in'] ) ? $r['body']['expires_in'] : 300 ) );
        return self::$tokens[$key][0];
    }

    /**
     * One JSON call. A 401 (an expired token) is retried once with a new token; any other non-2xx becomes a
     * sevenxPayPalError with PayPal's name, issue and debug id.
     *
     * @param array|string|null $body an array is encoded as JSON (an empty array as {}); a string is sent as it is
     */
    public function call( $method, $path, $body = null, $requestId = '', array $headers = array() )
    {
        $raw = is_string( $body ) ? $body : ( $body === null ? '' : ( $body === array() ? '{}' : json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
        for ( $try = 0; $try < 2; $try++ )
        {
            $h = array( 'Authorization' => 'Bearer ' . $this->token( $try > 0 ), 'Accept' => 'application/json', 'Prefer' => 'return=representation' ) + $headers;
            if ( $raw !== '' || $method === 'POST' )
                $h['Content-Type'] = 'application/json';
            if ( $requestId !== '' )
                $h['PayPal-Request-Id'] = substr( (string)$requestId, 0, 108 );
            $r = $this->http->request( $method, $this->config->apiBase() . $path, $h, $raw );
            if ( $r['status'] === 401 && $try === 0 )
                continue;
            break;
        }
        if ( $r['status'] >= 200 && $r['status'] < 300 )
            return is_array( $r['body'] ) ? $r['body'] : array();
        throw sevenxPayPalError::fromResponse( $r );
    }

    protected static function id( $id )
    {
        if ( !preg_match( '/^[A-Z0-9-]{3,64}$/Di', (string)$id ) )
            throw new sevenxPayPalError( 'not a PayPal id', 'invalid_id' );
        return rawurlencode( (string)$id );
    }

    // ------------------------------------------------------------------------------------------- Orders v2

    /**
     * Pure: the body of a new order of one purchase unit.
     *
     * @param array $o intent (CAPTURE|AUTHORIZE), amount_minor, currency, reference_id, custom_id, invoice_id,
     *                 description, brand_name, return_url, cancel_url, shipping (bool, default false), locale,
     *                 payment_source (array: a vaulted source, or a sandbox test card; never a real card number)
     */
    public static function orderBody( array $o )
    {
        $cur = strtoupper( (string)$o['currency'] );
        $unit = array( 'amount' => sevenxPayPalMoney::amount( (int)$o['amount_minor'], $cur ) );
        foreach ( array( 'reference_id' => 256, 'custom_id' => 127, 'invoice_id' => 127, 'description' => 127 ) as $k => $max )
            if ( isset( $o[$k] ) && (string)$o[$k] !== '' )
                $unit[$k] = mb_substr( (string)$o[$k], 0, $max );
        $ctx = array( 'shipping_preference' => !empty( $o['shipping'] ) ? 'GET_FROM_FILE' : 'NO_SHIPPING', 'user_action' => 'PAY_NOW' );
        if ( isset( $o['brand_name'] ) && (string)$o['brand_name'] !== '' )
            $ctx['brand_name'] = mb_substr( (string)$o['brand_name'], 0, 127 );
        foreach ( array( 'return_url', 'cancel_url' ) as $k )
            if ( isset( $o[$k] ) && preg_match( '#^https://#', (string)$o[$k] ) )
                $ctx[$k] = (string)$o[$k];
        if ( isset( $o['locale'] ) && preg_match( '/^[a-z]{2}-[A-Z]{2}$/D', (string)$o['locale'] ) )
            $ctx['locale'] = (string)$o['locale'];
        $body = array( 'intent' => isset( $o['intent'] ) && strtoupper( $o['intent'] ) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE',
                       'purchase_units' => array( $unit ), 'application_context' => $ctx );
        // a payment source given by the server (a vaulted PayPal account, or the sandbox's test card in the end-to-end
        // tests): no buyer approval step, so no application_context
        if ( isset( $o['payment_source'] ) && is_array( $o['payment_source'] ) && $o['payment_source'] )
        {
            $body['payment_source'] = $o['payment_source'];
            unset( $body['application_context'] );
        }
        return $body;
    }

    /** POST /v2/checkout/orders. @return array the order (id, status CREATED, links: approve) */
    public function createOrder( array $o, $requestId )
    {
        if ( (int)$o['amount_minor'] <= 0 )
            throw new sevenxPayPalError( 'an order needs an amount of more than 0', 'invalid_amount' );
        return $this->call( 'POST', '/v2/checkout/orders', self::orderBody( $o ), $requestId );
    }

    public function getOrder( $id )
    {
        return $this->call( 'GET', '/v2/checkout/orders/' . self::id( $id ) );
    }

    /** POST /v2/checkout/orders/{id}/capture (an approved CAPTURE order). */
    public function captureOrder( $id, $requestId = '' )
    {
        return $this->call( 'POST', '/v2/checkout/orders/' . self::id( $id ) . '/capture', array(), $requestId );
    }

    /** POST /v2/checkout/orders/{id}/authorize (an approved AUTHORIZE order): the money is held, not taken. */
    public function authorizeOrder( $id, $requestId = '' )
    {
        return $this->call( 'POST', '/v2/checkout/orders/' . self::id( $id ) . '/authorize', array(), $requestId );
    }

    /** The approval link of a created order (for flows without the JS buttons: the app, the end-to-end test). */
    public static function approveUrl( array $order )
    {
        foreach ( isset( $order['links'] ) ? (array)$order['links'] : array() as $l )
            if ( isset( $l['rel'] ) && in_array( $l['rel'], array( 'approve', 'payer-action' ), true ) )
                return (string)$l['href'];
        return '';
    }

    // ------------------------------------------------------------------------------------------- Payments v2

    public function getAuthorization( $id )
    {
        return $this->call( 'GET', '/v2/payments/authorizations/' . self::id( $id ) );
    }

    /** Captures a held amount (all of it, or $amountMinor of it); final_capture true. */
    public function captureAuthorization( $id, $amountMinor = null, $currency = '', $invoiceId = '', $requestId = '' )
    {
        $body = array( 'final_capture' => true );
        if ( $amountMinor !== null )
            $body['amount'] = sevenxPayPalMoney::amount( (int)$amountMinor, $currency );
        if ( $invoiceId !== '' )
            $body['invoice_id'] = substr( (string)$invoiceId, 0, 127 );
        return $this->call( 'POST', '/v2/payments/authorizations/' . self::id( $id ) . '/capture', $body, $requestId );
    }

    /** Releases a held amount (PayPal answers 204 or the voided authorization). */
    public function voidAuthorization( $id, $requestId = '' )
    {
        return $this->call( 'POST', '/v2/payments/authorizations/' . self::id( $id ) . '/void', null, $requestId );
    }

    public function getCapture( $id )
    {
        return $this->call( 'GET', '/v2/payments/captures/' . self::id( $id ) );
    }

    /** Refunds a capture: all of what is left ($amountMinor null) or a part. */
    public function refundCapture( $captureId, $amountMinor = null, $currency = '', $note = '', $invoiceId = '', $requestId = '' )
    {
        $body = array();
        if ( $amountMinor !== null )
            $body['amount'] = sevenxPayPalMoney::amount( (int)$amountMinor, $currency );
        if ( $note !== '' )
            $body['note_to_payer'] = mb_substr( (string)$note, 0, 255 );
        if ( $invoiceId !== '' )
            $body['invoice_id'] = substr( (string)$invoiceId, 0, 127 );
        return $this->call( 'POST', '/v2/payments/captures/' . self::id( $captureId ) . '/refund', $body, $requestId );
    }

    public function getRefund( $id )
    {
        return $this->call( 'GET', '/v2/payments/refunds/' . self::id( $id ) );
    }

    // ------------------------------------------------------------------------------------------- Webhooks v1

    public function listWebhooks()
    {
        $r = $this->call( 'GET', '/v1/notifications/webhooks' );
        return isset( $r['webhooks'] ) ? (array)$r['webhooks'] : array();
    }

    /** Creates a webhook for $url with the events (there is deliberately no delete here: other apps share accounts). */
    public function createWebhook( $url, array $events = array() )
    {
        if ( !preg_match( '#^https://[^\s]+$#D', (string)$url ) )
            throw new sevenxPayPalError( 'a webhook URL must be https', 'invalid_url' );
        $types = array();
        foreach ( $events ?: self::WEBHOOK_EVENTS as $e )
            $types[] = array( 'name' => (string)$e );
        return $this->call( 'POST', '/v1/notifications/webhooks', array( 'url' => (string)$url, 'event_types' => $types ) );
    }

    /** The webhook of an app that points at $url, or null. */
    public function findWebhook( $url )
    {
        foreach ( $this->listWebhooks() as $w )
            if ( isset( $w['url'] ) && rtrim( (string)$w['url'], '/' ) === rtrim( (string)$url, '/' ) )
                return $w;
        return null;
    }

    /**
     * Asks PayPal whether a delivery is genuine (POST /v1/notifications/verify-webhook-signature). The event goes
     * into the request as the raw bytes PayPal sent, never re-encoded, so the CRC PayPal computes matches.
     *
     * @param array  $headers the delivery's headers (any case)
     * @return bool true only for verification_status SUCCESS
     */
    public function verifyWebhookSignature( array $headers, $rawBody, $webhookId )
    {
        $h = sevenxPayPalWebhookSignature::headers( $headers );
        if ( $h === null || (string)$webhookId === '' || json_decode( (string)$rawBody ) === null )
            return false;
        $body = '{"auth_algo":' . json_encode( $h['auth_algo'] ) . ',"cert_url":' . json_encode( $h['cert_url'], JSON_UNESCAPED_SLASHES )
              . ',"transmission_id":' . json_encode( $h['transmission_id'] ) . ',"transmission_sig":' . json_encode( $h['transmission_sig'], JSON_UNESCAPED_SLASHES )
              . ',"transmission_time":' . json_encode( $h['transmission_time'] ) . ',"webhook_id":' . json_encode( (string)$webhookId )
              . ',"webhook_event":' . trim( (string)$rawBody ) . '}';
        $r = $this->call( 'POST', '/v1/notifications/verify-webhook-signature', $body );
        return isset( $r['verification_status'] ) && $r['verification_status'] === 'SUCCESS';
    }

    // ------------------------------------------------------------------------------------------- reading answers (pure)

    /** The first purchase unit of an order. */
    public static function unit( array $order )
    {
        return isset( $order['purchase_units'][0] ) && is_array( $order['purchase_units'][0] ) ? $order['purchase_units'][0] : array();
    }

    /** The first capture of a captured order (or the capture itself when $order is a capture), or null. */
    public static function captureOf( array $order )
    {
        return self::paymentOf( $order, 'captures' );
    }

    /** The first authorization of an authorized order, or null. */
    public static function authorizationOf( array $order )
    {
        return self::paymentOf( $order, 'authorizations' );
    }

    /** A payment of the first purchase unit; its custom_id and invoice_id taken from the unit when it has none itself. */
    protected static function paymentOf( array $order, $kind )
    {
        $u = self::unit( $order );
        if ( !isset( $u['payments'][$kind][0] ) || !is_array( $u['payments'][$kind][0] ) )
            return null;
        $p = $u['payments'][$kind][0];
        foreach ( array( 'custom_id', 'invoice_id' ) as $k )
            if ( !isset( $p[$k] ) && isset( $u[$k] ) )
                $p[$k] = $u[$k];
        return $p;
    }

    /**
     * Whether a capture (or authorization) pays exactly what is expected (pure; the same rule as the Stripe
     * gateway's amount check): its status, the custom_id naming the payment, the currency and the amount.
     *
     * @param array  $payment       a capture or an authorization object
     * @param string $customId      the custom_id the order was created with
     * @param array  $okStatus      the statuses that count (COMPLETED for a capture; CREATED for an authorization)
     * @return string '' or a reason code
     */
    public static function mismatch( ?array $payment, $customId, $expectedMinor, $expectedCurrency, array $okStatus = array( 'COMPLETED' ) )
    {
        if ( !$payment )
            return 'no_payment';
        if ( !in_array( isset( $payment['status'] ) ? (string)$payment['status'] : '', $okStatus, true ) )
            return 'status_not_completed';
        if ( (string)$customId === '' || ( isset( $payment['custom_id'] ) ? (string)$payment['custom_id'] : '' ) !== (string)$customId )
            return 'order_mismatch';
        $cur = isset( $payment['amount']['currency_code'] ) ? strtoupper( (string)$payment['amount']['currency_code'] ) : '';
        if ( $cur !== strtoupper( (string)$expectedCurrency ) )
            return 'currency_mismatch';
        if ( (int)$expectedMinor <= 0 )
            return 'order_amount_invalid';
        $got = sevenxPayPalMoney::toMinor( isset( $payment['amount']['value'] ) ? $payment['amount']['value'] : '', $cur );
        if ( $got === null || $got !== (int)$expectedMinor )
            return 'amount_mismatch';
        return '';
    }
}

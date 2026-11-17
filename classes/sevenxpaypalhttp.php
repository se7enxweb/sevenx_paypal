<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * The HTTP transport of the PayPal client: one request to the REST API, answered as status + decoded JSON body.
 * The real one is curl (sevenxPayPalCurlHttp); the tests give a recording mock.
 */
interface sevenxPayPalHttp
{
    /**
     * @param string $method  GET, POST, PATCH, DELETE
     * @param string $url     the full URL
     * @param array  $headers name => value
     * @param string $body    the raw body ('' for none)
     * @return array status (int), body (array, the decoded JSON; empty when there is none), headers (array, lower case names)
     */
    public function request( $method, $url, array $headers = array(), $body = '' );
}

/** curl to api-m.paypal.com / api-m.sandbox.paypal.com. Never logs a header or a body. */
class sevenxPayPalCurlHttp implements sevenxPayPalHttp
{
    protected $timeout;

    public function __construct( $timeout = 60 )
    {
        $this->timeout = max( 5, (int)$timeout );
    }

    public function request( $method, $url, array $headers = array(), $body = '' )
    {
        $h = array();
        foreach ( $headers as $k => $v )
            $h[] = $k . ': ' . $v;
        $h[] = 'User-Agent: sevenx_paypal/1.0 (Exponential)';
        $responseHeaders = array();
        $c = curl_init( $url );
        curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h,
                                      CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $this->timeout, CURLOPT_SSL_VERIFYPEER => true,
                                      CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                                      CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$responseHeaders ) {
                                          $p = strpos( $line, ':' );
                                          if ( $p !== false )
                                              $responseHeaders[strtolower( trim( substr( $line, 0, $p ) ) )] = trim( substr( $line, $p + 1 ) );
                                          return strlen( $line );
                                      } ) );
        if ( $method !== 'GET' && $body !== '' )
            curl_setopt( $c, CURLOPT_POSTFIELDS, (string)$body );
        $raw = curl_exec( $c );
        $status = (int)curl_getinfo( $c, CURLINFO_HTTP_CODE );
        $err = curl_error( $c );
        if ( PHP_VERSION_ID < 80000 )
            curl_close( $c );
        if ( $raw === false )
            throw new sevenxPayPalError( 'PayPal could not be reached: ' . substr( $err, 0, 120 ), 'api_connection_error', 0 );
        $doc = $raw === '' ? array() : json_decode( (string)$raw, true );
        return array( 'status' => $status, 'body' => is_array( $doc ) ? $doc : array(), 'headers' => $responseHeaders );
    }
}

/**
 * A PayPal API error: PayPal's error name, the first issue code, the debug id (for PayPal support), never a credential
 * or a payer's data.
 */
class sevenxPayPalError extends RuntimeException
{
    /** @var string PayPal's error name (UNPROCESSABLE_ENTITY, RESOURCE_NOT_FOUND, invalid_client ...) */
    public $name;
    /** @var string the first details[].issue (INSTRUMENT_DECLINED, ORDER_NOT_APPROVED, ORDER_ALREADY_CAPTURED ...) */
    public $issue;
    /** @var int */
    public $httpStatus;
    /** @var string PayPal's debug_id */
    public $debugId;

    public function __construct( $message, $name = '', $status = 0, $issue = '', $debugId = '' )
    {
        parent::__construct( (string)$message );
        $this->name = (string)$name;
        $this->issue = (string)$issue;
        $this->httpStatus = (int)$status;
        $this->debugId = (string)$debugId;
    }

    /** Built from a non-2xx answer of the API. */
    public static function fromResponse( array $r )
    {
        $b = isset( $r['body'] ) && is_array( $r['body'] ) ? $r['body'] : array();
        $name = isset( $b['name'] ) ? (string)$b['name'] : ( isset( $b['error'] ) ? (string)$b['error'] : 'api_error' );
        $issue = isset( $b['details'][0]['issue'] ) ? (string)$b['details'][0]['issue'] : '';
        $msg = isset( $b['message'] ) ? (string)$b['message'] : ( isset( $b['error_description'] ) ? (string)$b['error_description'] : 'PayPal answered ' . (int)$r['status'] );
        if ( isset( $b['details'][0]['description'] ) )
            $msg .= ' (' . $b['details'][0]['description'] . ')';
        $msg = preg_replace( '/\d{12,19}/', '[redacted]', $msg );
        return new self( substr( $msg, 0, 240 ), $name, (int)$r['status'], $issue, isset( $b['debug_id'] ) ? (string)$b['debug_id'] : '' );
    }

    /** Whether the buyer has to choose another way to pay (the order can be restarted in the buttons). */
    public function buyerCanRetry()
    {
        return in_array( $this->issue, array( 'INSTRUMENT_DECLINED', 'PAYER_ACTION_REQUIRED' ), true );
    }
}

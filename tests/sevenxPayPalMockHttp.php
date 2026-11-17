<?php
/**
 * A recording stand-in for PayPal's REST API (tests): routes "METHOD path-regex" to an answer (status, body) or a
 * callable (method, url, headers, body, matches) => answer; every request is kept in $requests.
 */
class sevenxPayPalMockHttp implements sevenxPayPalHttp
{
    public $routes = array();
    public $requests = array();

    public function __construct()
    {
        $this->on( 'POST', '#^/v1/oauth2/token$#', array( 'status' => 200, 'body' => array( 'access_token' => 'A21-test-token', 'expires_in' => 32400, 'app_id' => 'APP-TEST' ) ) );
    }

    public function on( $method, $regex, $answer )
    {
        array_unshift( $this->routes, array( $method, $regex, $answer ) );
        return $this;
    }

    public function request( $method, $url, array $headers = array(), $body = '' )
    {
        $path = (string)parse_url( $url, PHP_URL_PATH );
        $this->requests[] = array( 'method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body );
        foreach ( $this->routes as $r )
            if ( $r[0] === $method && preg_match( $r[1], $path, $m ) )
            {
                $a = is_callable( $r[2] ) ? call_user_func( $r[2], $method, $url, $headers, $body, $m ) : $r[2];
                return $a + array( 'body' => array(), 'headers' => array() );
            }
        return array( 'status' => 404, 'body' => array( 'name' => 'RESOURCE_NOT_FOUND', 'message' => 'no mock for ' . $method . ' ' . $path ), 'headers' => array() );
    }

    /** The requests to a path (regex). */
    public function to( $regex )
    {
        return array_values( array_filter( $this->requests, function ( $q ) use ( $regex ) { return (bool)preg_match( $regex, $q['path'] ); } ) );
    }

    /** A PayPal order answer (pure helper). */
    public static function order( $id, $status, $custom, $value, $currency = 'USD', array $payments = array() )
    {
        $unit = array( 'reference_id' => $custom, 'custom_id' => $custom, 'amount' => array( 'currency_code' => $currency, 'value' => $value ) );
        if ( $payments )
            $unit['payments'] = $payments;
        return array( 'id' => $id, 'status' => $status, 'purchase_units' => array( $unit ),
                      'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id ) ) );
    }
}

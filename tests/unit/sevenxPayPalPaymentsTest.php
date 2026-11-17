<?php
use PHPUnit\Framework\TestCase;

/** The client and the payment flows against a mock of PayPal's API and an in-memory store. */
class sevenxPayPalPaymentsTest extends TestCase
{
    /** @var sevenxPayPalMockHttp */
    protected $http;
    /** @var sevenxPayPalPayments */
    protected $p;
    protected $n = 0;

    protected function setUp(): void
    {
        sevenxPayPalClient::resetTokens();
        $this->http = new sevenxPayPalMockHttp();
        $cfg = new sevenxPayPalConfig( array( 'BrandName' => 'Test Shop' ), function () { return str_repeat( 'k', 80 ); } );
        $this->p = new sevenxPayPalPayments( new sevenxPayPalClient( $cfg, $this->http ), new sevenxPayPalMemoryStore() );
        $this->p->now = 1800000000;
        $this->http->on( 'POST', '#^/v2/checkout/orders$#', function ( $m, $u, $h, $body ) {
            $b = json_decode( $body, true );
            $u0 = $b['purchase_units'][0];
            return array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'ORDER' . ( ++$this->n ), 'CREATED', $u0['custom_id'], $u0['amount']['value'], $u0['amount']['currency_code'] ) );
        } );
    }

    protected function captureAnswer( $id, $custom, $value, $status = 'COMPLETED', $currency = 'USD' )
    {
        return array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( $id, 'COMPLETED', $custom, $value, $currency, array( 'captures' => array(
            array( 'id' => 'CAP-' . $id, 'status' => $status, 'amount' => array( 'currency_code' => $currency, 'value' => $value ), 'custom_id' => $custom ) ) ) ) );
    }

    public function testTokenIsCachedAndA401IsRetriedOnce()
    {
        $calls = 0;
        $this->http->on( 'GET', '#^/v2/checkout/orders/X1A$#', function () use ( &$calls ) {
            return ++$calls === 1 ? array( 'status' => 401, 'body' => array( 'error' => 'invalid_token' ) ) : array( 'status' => 200, 'body' => array( 'id' => 'X1A' ) );
        } );
        $o = $this->p->client()->getOrder( 'X1A' );
        $this->assertSame( 'X1A', $o['id'] );
        $this->assertCount( 2, $this->http->to( '#oauth2/token#' ), 'a fresh token after the 401' );
        $this->p->client()->getOrder( 'X1A' );
        $this->assertCount( 2, $this->http->to( '#oauth2/token#' ), 'the token is kept' );
        $this->assertSame( 'APP-TEST', $this->p->client()->appId );
    }

    public function testErrorsCarryIssueAndDebugIdButNoCardNumber()
    {
        $this->http->on( 'POST', '#/capture$#', array( 'status' => 422, 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'debug_id' => 'dbg1',
            'message' => 'The requested action could not be performed 4111111111111111', 'details' => array( array( 'issue' => 'INSTRUMENT_DECLINED' ) ) ) ) );
        try
        {
            $this->p->client()->captureOrder( 'ABC123' );
            $this->fail( 'no exception' );
        }
        catch ( sevenxPayPalError $e )
        {
            $this->assertSame( 'INSTRUMENT_DECLINED', $e->issue );
            $this->assertSame( 'dbg1', $e->debugId );
            $this->assertTrue( $e->buyerCanRetry() );
            $this->assertStringNotContainsString( '4111111111111111', $e->getMessage() );
        }
    }

    public function testBeginPricesOnTheServerAndReusesAnOpenOrder()
    {
        $r = $this->p->begin( array( 'context' => 'shop', 'reference' => 'shop-order:7', 'amount_minor' => 1999, 'currency' => 'usd', 'description' => 'Order 7', 'user_id' => 3 ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORDER1', $r['order_id'] );
        $this->assertStringContainsString( 'checkoutnow', $r['approve_url'] );
        $sent = $this->http->to( '#^/v2/checkout/orders$#' );
        $body = json_decode( $sent[0]['body'], true );
        $this->assertSame( 'CAPTURE', $body['intent'] );
        $this->assertSame( array( 'currency_code' => 'USD', 'value' => '19.99' ), $body['purchase_units'][0]['amount'] );
        $this->assertSame( 'shop-order:7', $body['purchase_units'][0]['custom_id'] );
        $this->assertSame( 'NO_SHIPPING', $body['application_context']['shipping_preference'] );
        $this->assertSame( 'Test Shop', $body['application_context']['brand_name'] );
        $this->assertArrayHasKey( 'PayPal-Request-Id', $sent[0]['headers'] );
        $again = $this->p->begin( array( 'context' => 'shop', 'reference' => 'shop-order:7', 'amount_minor' => 1999, 'currency' => 'USD', 'user_id' => 3 ) );
        $this->assertTrue( $again['reused'] );
        $this->assertSame( 'ORDER1', $again['order_id'] );
        $this->assertCount( 1, $this->http->to( '#^/v2/checkout/orders$#' ), 'a reload makes no second order' );
        $other = $this->p->begin( array( 'context' => 'shop', 'reference' => 'shop-order:7', 'amount_minor' => 2999, 'currency' => 'USD', 'user_id' => 3 ) );
        $this->assertFalse( $other['reused'], 'a changed total is a new order' );
        $this->assertFalse( $this->p->begin( array( 'reference' => 'x', 'amount_minor' => 0 ) )['ok'], 'nothing to pay' );
    }

    public function testCaptureChecksTheAmountAndIsIdempotent()
    {
        $this->p->begin( array( 'context' => 'shop', 'reference' => 'shop-order:8', 'amount_minor' => 1234, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#^/v2/checkout/orders/ORDER1/capture$#', $this->captureAnswer( 'ORDER1', 'shop-order:8', '12.34' ) );
        $this->assertFalse( $this->p->capture( 'ORDER1', 'shop-order:9' )['ok'], 'another reference: not found' );
        $r = $this->p->capture( 'ORDER1', 'shop-order:8' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'CAP-ORDER1', $r['capture_id'] );
        $this->assertFalse( $r['already'] );
        $again = $this->p->capture( 'ORDER1', 'shop-order:8' );
        $this->assertTrue( $again['already'] );
        $this->assertCount( 1, $this->http->to( '#/capture$#' ), 'a second capture asks PayPal nothing' );
        $this->assertSame( 'COMPLETED', $this->p->transaction( 'ORDER1' )['status'] );
    }

    public function testACaptureOfAnotherAmountIsAMismatchNotAPayment()
    {
        $this->p->begin( array( 'context' => 'shop', 'reference' => 'shop-order:9', 'amount_minor' => 5000, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#/capture$#', $this->captureAnswer( 'ORDER1', 'shop-order:9', '0.50' ) );
        $r = $this->p->capture( 'ORDER1' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'amount_mismatch', $r['mismatch'] );
        $this->assertSame( 'MISMATCH', $this->p->transaction( 'ORDER1' )['status'] );
        $this->assertSame( 'currency_mismatch', sevenxPayPalClient::mismatch( array( 'status' => 'COMPLETED', 'custom_id' => 'a', 'amount' => array( 'currency_code' => 'EUR', 'value' => '50.00' ) ), 'a', 5000, 'USD' ) );
        $this->assertSame( 'order_mismatch', sevenxPayPalClient::mismatch( array( 'status' => 'COMPLETED', 'custom_id' => 'b', 'amount' => array( 'currency_code' => 'USD', 'value' => '50.00' ) ), 'a', 5000, 'USD' ) );
        $this->assertSame( 'status_not_completed', sevenxPayPalClient::mismatch( array( 'status' => 'DECLINED', 'custom_id' => 'a', 'amount' => array( 'currency_code' => 'USD', 'value' => '50.00' ) ), 'a', 5000, 'USD' ) );
        $this->assertSame( '', sevenxPayPalClient::mismatch( array( 'status' => 'COMPLETED', 'custom_id' => 'a', 'amount' => array( 'currency_code' => 'USD', 'value' => '50.00' ) ), 'a', 5000, 'usd' ) );
    }

    public function testAlreadyCapturedReadsTheOrderAndDeclinedPaysNothing()
    {
        $this->p->begin( array( 'reference' => 'k:1', 'amount_minor' => 100, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#/capture$#', array( 'status' => 422, 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'ORDER_ALREADY_CAPTURED' ) ) ) ) );
        $this->http->on( 'GET', '#^/v2/checkout/orders/ORDER1$#', $this->captureAnswer( 'ORDER1', 'k:1', '1.00' ) );
        $this->assertTrue( $this->p->capture( 'ORDER1' )['ok'] );
        $this->p->begin( array( 'reference' => 'k:2', 'amount_minor' => 100, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#ORDER2/capture$#', array( 'status' => 422, 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'INSTRUMENT_DECLINED' ) ) ) ) );
        $r = $this->p->capture( 'ORDER2' );
        $this->assertFalse( $r['ok'] );
        $this->assertTrue( $r['retry_buyer'] );
        $this->assertSame( 'CREATED', $this->p->transaction( 'ORDER2' )['status'], 'a declined capture leaves the order unpaid' );
        $this->p->begin( array( 'reference' => 'k:3', 'amount_minor' => 100, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#ORDER3/capture$#', $this->captureAnswer( 'ORDER3', 'k:3', '1.00', 'PENDING' ) );
        $p = $this->p->capture( 'ORDER3' );
        $this->assertTrue( $p['pending'] );
        $this->assertSame( 'PENDING', $this->p->transaction( 'ORDER3' )['status'], 'pending is not paid' );
    }

    public function testAuthorizeCaptureAndVoid()
    {
        $this->p->begin( array( 'context' => 'domain', 'reference' => 'domain:5', 'amount_minor' => 1500, 'currency' => 'USD', 'intent' => 'AUTHORIZE' ) );
        $this->assertFalse( $this->p->capture( 'ORDER1' )['ok'], 'an AUTHORIZE order is never captured directly' );
        $this->http->on( 'POST', '#ORDER1/authorize$#', array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'ORDER1', 'COMPLETED', 'domain:5', '15.00', 'USD',
            array( 'authorizations' => array( array( 'id' => 'AUTH1', 'status' => 'CREATED', 'amount' => array( 'currency_code' => 'USD', 'value' => '15.00' ), 'expiration_time' => '2026-12-17T00:00:00Z' ) ) ) ) ) );
        $a = $this->p->authorize( 'ORDER1', 'domain:5' );
        $this->assertTrue( $a['ok'] );
        $this->assertSame( 'AUTH1', $a['authorization_id'] );
        $this->assertSame( 'AUTHORIZED', $this->p->transaction( 'ORDER1' )['status'] );
        $this->assertTrue( $this->p->authorize( 'ORDER1' )['already'] );
        $this->http->on( 'POST', '#authorizations/AUTH1/capture$#', array( 'status' => 201, 'body' => array( 'id' => 'CAPA', 'status' => 'COMPLETED',
            'amount' => array( 'currency_code' => 'USD', 'value' => '15.00' ) ) ) );
        $c = $this->p->captureAuthorization( 'AUTH1', 'D-5' );
        $this->assertTrue( $c['ok'] );
        $this->assertSame( 'CAPA', $c['capture_id'] );
        $body = json_decode( $this->http->to( '#AUTH1/capture$#' )[0]['body'], true );
        $this->assertTrue( $body['final_capture'] );
        // a second hold, released
        $this->p->begin( array( 'reference' => 'domain:6', 'amount_minor' => 900, 'currency' => 'USD', 'intent' => 'AUTHORIZE' ) );
        $this->http->on( 'POST', '#ORDER2/authorize$#', array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'ORDER2', 'COMPLETED', 'domain:6', '9.00', 'USD',
            array( 'authorizations' => array( array( 'id' => 'AUTH2', 'status' => 'CREATED', 'amount' => array( 'currency_code' => 'USD', 'value' => '9.00' ) ) ) ) ) ) );
        $this->p->authorize( 'ORDER2' );
        $this->http->on( 'POST', '#AUTH2/void$#', array( 'status' => 204, 'body' => array() ) );
        $this->assertTrue( $this->p->void( 'AUTH2' )['released'] );
        $this->assertSame( 'VOIDED', $this->p->transaction( 'ORDER2' )['status'] );
    }

    public function testAnAuthorizationOfAnotherAmountIsVoided()
    {
        $this->p->begin( array( 'reference' => 'domain:7', 'amount_minor' => 1500, 'currency' => 'USD', 'intent' => 'AUTHORIZE' ) );
        $this->http->on( 'POST', '#ORDER1/authorize$#', array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'ORDER1', 'COMPLETED', 'domain:7', '1.00', 'USD',
            array( 'authorizations' => array( array( 'id' => 'AUTHX', 'status' => 'CREATED', 'amount' => array( 'currency_code' => 'USD', 'value' => '1.00' ) ) ) ) ) ) );
        $this->http->on( 'POST', '#AUTHX/void$#', array( 'status' => 204, 'body' => array() ) );
        $r = $this->p->authorize( 'ORDER1' );
        $this->assertFalse( $r['ok'] );
        $this->assertCount( 1, $this->http->to( '#AUTHX/void$#' ), 'the wrong hold is released at once' );
    }

    public function testRefundsPartialThenTheRestNeverMore()
    {
        $this->p->begin( array( 'reference' => 'k:9', 'amount_minor' => 1000, 'currency' => 'USD' ) );
        $this->http->on( 'POST', '#/capture$#', $this->captureAnswer( 'ORDER1', 'k:9', '10.00' ) );
        $this->p->capture( 'ORDER1' );
        $this->http->on( 'POST', '#captures/CAP-ORDER1/refund$#', function ( $m, $u, $h, $body ) {
            $b = json_decode( $body, true );
            return array( 'status' => 201, 'body' => array( 'id' => 'R' . md5( $body ), 'status' => 'COMPLETED', 'amount' => isset( $b['amount'] ) ? $b['amount'] : array( 'currency_code' => 'USD', 'value' => '10.00' ) ) );
        } );
        $r = $this->p->refund( 'CAP-ORDER1', 400, '', 'Partial refund' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 400, $r['amount_minor'] );
        $this->assertSame( 'PARTIALLY_REFUNDED', $this->p->transaction( 'ORDER1' )['status'] );
        $this->assertFalse( $this->p->refund( 'CAP-ORDER1', 700 )['ok'], 'never more than what is left' );
        $rest = $this->p->refund( 'CAP-ORDER1' );
        $this->assertTrue( $rest['ok'] );
        $this->assertSame( 600, $rest['amount_minor'] );
        $this->assertSame( 'REFUNDED', $this->p->transaction( 'ORDER1' )['status'] );
        $keys = array_map( function ( $q ) { return $q['headers']['PayPal-Request-Id']; }, $this->http->to( '#/refund$#' ) );
        $this->assertCount( 2, array_unique( $keys ), 'each refund has its own request id' );
    }
}

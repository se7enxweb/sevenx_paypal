<?php
use PHPUnit\Framework\TestCase;

/** The webhook: verification gate, idempotent storage, the built-in handler, failing handlers. */
class sevenxPayPalWebhookTest extends TestCase
{
    protected $http;
    protected $p;

    protected function setUp(): void
    {
        sevenxPayPalClient::resetTokens();
        $this->http = new sevenxPayPalMockHttp();
        $cfg = new sevenxPayPalConfig( array( 'SandboxWebhookId' => 'HOOK1' ), function () { return str_repeat( 'k', 80 ); } );
        $this->p = new sevenxPayPalPayments( new sevenxPayPalClient( $cfg, $this->http ), new sevenxPayPalMemoryStore() );
        $this->http->on( 'POST', '#^/v2/checkout/orders$#', array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'ORD1', 'CREATED', 'k:1', '10.00' ) ) );
        $this->p->begin( array( 'context' => 'katalyst', 'reference' => 'k:1', 'amount_minor' => 1000, 'currency' => 'USD' ) );
    }

    protected function hook( array $handlers = array(), $valid = true )
    {
        $h = new sevenxPayPalWebhook( $this->p, array_merge( array( new sevenxPayPalCoreWebhookHandler() ), $handlers ) );
        $h->verifier = function () use ( $valid ) { return $valid; };
        return $h;
    }

    protected static function event( $id, $type, array $resource, $rtype = 'capture' )
    {
        return json_encode( array( 'id' => $id, 'event_type' => $type, 'resource_type' => $rtype, 'resource' => $resource ) );
    }

    protected static function capture( $status = 'COMPLETED', $value = '10.00' )
    {
        return array( 'id' => 'CAP1', 'status' => $status, 'custom_id' => 'k:1', 'amount' => array( 'currency_code' => 'USD', 'value' => $value ),
                      'payer' => array( 'email_address' => 'buyer@example.com' ), 'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'ORD1' ) ) );
    }

    public function testRefusedAndMalformed()
    {
        $this->assertSame( 400, $this->hook()->receive( array(), 'not json' )['status'] );
        $this->assertSame( 401, $this->hook( array(), false )->receive( array(), self::event( 'WH-1', 'PAYMENT.CAPTURE.COMPLETED', self::capture() ) )['status'] );
        $this->assertSame( array(), $this->p->store()->find( 'sevenx_paypal_event', array() ), 'nothing is stored for a refused delivery' );
    }

    public function testCaptureCompletedOnceAndReplaysAreDuplicates()
    {
        $raw = self::event( 'WH-2', 'PAYMENT.CAPTURE.COMPLETED', self::capture() );
        $r = $this->hook()->receive( array(), $raw );
        $this->assertSame( 200, $r['status'] );
        $this->assertStringContainsString( 'paid', $r['result'] );
        $this->assertSame( 'COMPLETED', $this->p->transaction( 'ORD1' )['status'] );
        $this->assertSame( 'CAP1', $this->p->transaction( 'ORD1' )['capture_id'] );
        $again = $this->hook()->receive( array(), $raw );
        $this->assertTrue( $again['duplicate'] );
        $rows = $this->p->store()->find( 'sevenx_paypal_event', array() );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'ORD1', $rows[0]['order_id'] );
        $this->assertStringNotContainsString( 'buyer@example.com', json_encode( $rows ), 'no payer data is kept' );
    }

    public function testAWrongAmountIsNotPaid()
    {
        $r = $this->hook()->receive( array(), self::event( 'WH-3', 'PAYMENT.CAPTURE.COMPLETED', self::capture( 'COMPLETED', '1.00' ) ) );
        $this->assertStringContainsString( 'mismatch', $r['result'] );
        $this->assertSame( 'MISMATCH', $this->p->transaction( 'ORD1' )['status'] );
    }

    public function testRefundFromPayPalsDashboardIsFollowed()
    {
        $this->hook()->receive( array(), self::event( 'WH-4', 'PAYMENT.CAPTURE.COMPLETED', self::capture() ) );
        $refund = array( 'id' => 'REF1', 'status' => 'COMPLETED', 'amount' => array( 'currency_code' => 'USD', 'value' => '4.00' ),
                         'seller_payable_breakdown' => array( 'total_refunded_amount' => array( 'currency_code' => 'USD', 'value' => '4.00' ) ),
                         'links' => array( array( 'rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAP1' ) ) );
        $r = $this->hook()->receive( array(), self::event( 'WH-5', 'PAYMENT.CAPTURE.REFUNDED', $refund, 'refund' ) );
        $this->assertStringContainsString( 'refunded', $r['result'] );
        $this->assertSame( 400, (int)$this->p->transaction( 'ORD1' )['refunded_minor'] );
        $this->assertSame( 'PARTIALLY_REFUNDED', $this->p->transaction( 'ORD1' )['status'] );
    }

    public function testAFailingHandlerMakesPayPalDeliverAgain()
    {
        $bad = new class implements sevenxPayPalWebhookHandler {
            public $fail = true;
            public function handle( array $event, ?array $tx, sevenxPayPalPayments $p ) { if ( $this->fail ) throw new RuntimeException( 'database away' ); return 'ok'; }
        };
        $raw = self::event( 'WH-6', 'PAYMENT.CAPTURE.COMPLETED', self::capture() );
        $r = $this->hook( array( $bad ) )->receive( array(), $raw );
        $this->assertSame( 500, $r['status'] );
        $bad->fail = false;
        $r2 = $this->hook( array( $bad ) )->receive( array(), $raw );
        $this->assertSame( 200, $r2['status'], 'the redelivery runs the handlers again' );
        $this->assertFalse( $r2['duplicate'] );
        $this->assertTrue( $this->hook( array( $bad ) )->receive( array(), $raw )['duplicate'] );
    }

    public function testUnknownEventsAreAcknowledged()
    {
        $r = $this->hook()->receive( array(), self::event( 'WH-7', 'PAYMENT.CAPTURE.COMPLETED', array( 'id' => 'NOPE', 'status' => 'COMPLETED' ) ) );
        $this->assertSame( 200, $r['status'] );
        $this->assertStringContainsString( 'unknown', $r['result'] );
    }
}

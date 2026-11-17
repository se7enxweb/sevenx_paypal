<?php
use PHPUnit\Framework\TestCase;

/** Webhook signatures: header parsing and PayPal's local verification rule with a recorded sample (tests/fixtures). */
class sevenxPayPalSignatureTest extends TestCase
{
    protected function sample()
    {
        return json_decode( file_get_contents( __DIR__ . '/../fixtures/signature.json' ), true );
    }

    public function testHeadersAnyCase()
    {
        $s = $this->sample();
        $h = sevenxPayPalWebhookSignature::headers( $s['headers'] );
        $this->assertSame( 'SHA256withRSA', $h['auth_algo'] );
        $server = array();
        foreach ( $s['headers'] as $k => $v )
            $server['HTTP_' . str_replace( '-', '_', $k )] = $v;
        $this->assertSame( $h, sevenxPayPalWebhookSignature::headers( $server ), 'the $_SERVER form reads the same' );
        $missing = $s['headers'];
        unset( $missing['PAYPAL-TRANSMISSION-SIG'] );
        $this->assertNull( sevenxPayPalWebhookSignature::headers( $missing ) );
        $evil = $s['headers'];
        $evil['PAYPAL-CERT-URL'] = 'https://attacker.example.com/cert.pem';
        $this->assertNull( sevenxPayPalWebhookSignature::headers( $evil ), 'a certificate off PayPal\'s hosts is refused' );
        $this->assertFalse( sevenxPayPalWebhookSignature::certUrlOk( 'http://api.paypal.com/x' ) );
        $this->assertFalse( sevenxPayPalWebhookSignature::certUrlOk( 'https://api.paypal.com.evil.example/x' ) );
        $this->assertTrue( sevenxPayPalWebhookSignature::certUrlOk( 'https://api.paypal.com/v1/notifications/certs/CERT-1' ) );
    }

    public function testSignedStringUsesUnsignedCrc()
    {
        $this->assertSame( 'T|2026|H|' . sprintf( '%u', crc32( '{}' ) ), sevenxPayPalWebhookSignature::signedString( 'T', '2026', 'H', '{}' ) );
    }

    public function testRecordedSampleVerifies()
    {
        $s = $this->sample();
        $now = strtotime( '2027-01-01T00:00:00Z' );
        $this->assertTrue( sevenxPayPalWebhookSignature::verifyLocal( $s['headers'], $s['body'], $s['webhook_id'], $s['cert_pem'], $now ) );
        $this->assertFalse( sevenxPayPalWebhookSignature::verifyLocal( $s['headers'], str_replace( '12.34', '1.34', $s['body'] ), $s['webhook_id'], $s['cert_pem'], $now ),
                            'a changed body fails' );
        $this->assertFalse( sevenxPayPalWebhookSignature::verifyLocal( $s['headers'], $s['body'], 'OTHERWEBHOOK', $s['cert_pem'], $now ), 'another webhook id fails' );
        $this->assertFalse( sevenxPayPalWebhookSignature::verifyLocal( $s['headers'], $s['body'], $s['webhook_id'], $s['cert_pem'], strtotime( '2040-01-01' ) ),
                            'an expired certificate fails' );
        $h = $s['headers'];
        $h['PAYPAL-TRANSMISSION-ID'] = 'b2384410-f8d2-11ea-ad5b-1f2e2a3a1e01';
        $this->assertFalse( sevenxPayPalWebhookSignature::verifyLocal( $h, $s['body'], $s['webhook_id'], $s['cert_pem'], $now ), 'another transmission fails' );
        $this->assertFalse( sevenxPayPalWebhookSignature::verifyLocal( $s['headers'], $s['body'], $s['webhook_id'], 'not a certificate', $now ) );
    }

    public function testApiVerificationSendsTheRawBody()
    {
        $s = $this->sample();
        $http = new sevenxPayPalMockHttp();
        $http->on( 'POST', '#^/v1/notifications/verify-webhook-signature$#', function ( $m, $u, $h, $body ) {
            $doc = json_decode( $body, true );
            return array( 'status' => 200, 'body' => array( 'verification_status' => isset( $doc['webhook_event']['id'] ) && $doc['webhook_id'] === '1JE4291016473214C' ? 'SUCCESS' : 'FAILURE' ) );
        } );
        sevenxPayPalClient::resetTokens();
        $c = new sevenxPayPalClient( new sevenxPayPalConfig( array(), function () { return str_repeat( 'k', 80 ); } ), $http );
        $this->assertTrue( $c->verifyWebhookSignature( $s['headers'], $s['body'], $s['webhook_id'] ) );
        $sent = $http->to( '#verify-webhook-signature#' );
        $this->assertStringContainsString( '"webhook_event":' . $s['body'] . '}', $sent[0]['body'], 'the event is embedded byte for byte' );
        $this->assertFalse( $c->verifyWebhookSignature( $s['headers'], $s['body'], 'OTHER' ) );
        $this->assertFalse( $c->verifyWebhookSignature( array(), $s['body'], $s['webhook_id'] ), 'no headers: no call, refused' );
    }
}

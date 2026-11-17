<?php
use PHPUnit\Framework\TestCase;

/** Amount math and the settings rules (pure). */
class sevenxPayPalMoneyConfigTest extends TestCase
{
    public function testValuesAndMinorUnits()
    {
        $this->assertSame( '12.34', sevenxPayPalMoney::toValue( 1234, 'USD' ) );
        $this->assertSame( '0.05', sevenxPayPalMoney::toValue( 5, 'usd' ) );
        $this->assertSame( '1000', sevenxPayPalMoney::toValue( 1000, 'JPY' ) );
        $this->assertSame( '-1.50', sevenxPayPalMoney::toValue( -150, 'EUR' ) );
        $this->assertSame( 1234, sevenxPayPalMoney::toMinor( '12.34', 'USD' ) );
        $this->assertSame( 1230, sevenxPayPalMoney::toMinor( '12.3', 'USD' ) );
        $this->assertSame( 1200, sevenxPayPalMoney::toMinor( '12', 'USD' ) );
        $this->assertSame( 1000, sevenxPayPalMoney::toMinor( '1000', 'JPY' ) );
        $this->assertNull( sevenxPayPalMoney::toMinor( '12.345', 'USD' ), 'more decimals than the currency has are refused, never rounded' );
        $this->assertNull( sevenxPayPalMoney::toMinor( '1000.5', 'JPY' ) );
        $this->assertNull( sevenxPayPalMoney::toMinor( '1e3', 'USD' ) );
        $this->assertNull( sevenxPayPalMoney::toMinor( '', 'USD' ) );
        $this->assertSame( 1999, sevenxPayPalMoney::fromDecimal( 19.99, 'USD' ) );
        $this->assertSame( 1001, sevenxPayPalMoney::fromDecimal( 10.005, 'USD' ) );
        $this->assertSame( array( 'currency_code' => 'USD', 'value' => '0.99' ), sevenxPayPalMoney::amount( 99, 'usd' ) );
        foreach ( array( 1, 99, 100, 1234567, 50 ) as $n )
            $this->assertSame( $n, sevenxPayPalMoney::toMinor( sevenxPayPalMoney::toValue( $n, 'USD' ), 'USD' ), "round trip $n" );
    }

    protected function cfg( array $v, $mode = null )
    {
        $secrets = array( 'paypal-sandbox-client-id' => str_repeat( 'A', 80 ), 'paypal-sandbox-secret' => str_repeat( 'B', 80 ),
                          'paypal-live-client-id' => str_repeat( 'C', 80 ), 'paypal-live-secret' => str_repeat( 'D', 80 ) );
        return new sevenxPayPalConfig( $v, function ( $n ) use ( $secrets ) { return isset( $secrets[$n] ) ? $secrets[$n] : ''; }, $mode );
    }

    public function testCredentialsChecks()
    {
        $this->assertSame( '', sevenxPayPalConfig::check( 'sandbox', str_repeat( 'a', 80 ), str_repeat( 'b', 80 ) ) );
        $this->assertStringContainsString( 'refused while tests run', sevenxPayPalConfig::check( 'live', str_repeat( 'a', 80 ), str_repeat( 'b', 80 ), true ) );
        $this->assertStringContainsString( 'no sandbox client id', sevenxPayPalConfig::check( 'sandbox', '', 'x' ) );
        $this->assertStringContainsString( 'does not look like', sevenxPayPalConfig::check( 'sandbox', 'with space and more characters here', str_repeat( 'b', 80 ) ) );
        $this->assertStringContainsString( 'Mode must be', sevenxPayPalConfig::check( 'test', 'a', 'b' ) );
        $c = $this->cfg( array( 'Mode' => 'sandbox' ) );
        $this->assertTrue( $c->ok() );
        $this->assertSame( sevenxPayPalConfig::SANDBOX_API, $c->apiBase() );
        $live = $this->cfg( array( 'Mode' => 'live' ) );
        $this->assertFalse( $live->ok(), 'the live mode is refused under SEVENX_PAYPAL_TESTS' );
        $names = $this->cfg( array( 'Mode' => 'sandbox', 'SandboxClientIdSecret' => 'myshop-paypal-sandbox-client-id' ) )->secretNames();
        $this->assertSame( 'myshop-paypal-sandbox-client-id', $names['client_id'] );
        $d = $c->describe();
        $this->assertTrue( $d['client_id'] );
        $this->assertStringNotContainsString( str_repeat( 'A', 20 ), json_encode( $d ), 'describe() never carries a credential' );
    }

    public function testWhoIsOffered()
    {
        $this->assertFalse( $this->cfg( array( 'Enabled' => 'disabled' ) )->enabledFor( 5, 'store' ) );
        $this->assertTrue( $this->cfg( array( 'Enabled' => 'enabled' ) )->enabledFor( 0, 'store' ) );
        $t = $this->cfg( array( 'Enabled' => 'testers', 'TestUsers' => array( '14', '99' ) ) );
        $this->assertTrue( $t->enabledFor( 14, 'store' ) );
        $this->assertFalse( $t->enabledFor( 15, 'store' ) );
        $this->assertFalse( $t->enabledFor( 0, 'store' ) );
        $s = $this->cfg( array( 'Enabled' => 'enabled', 'EnabledSiteAccesses' => array( 'store', 'se7enx' ) ) );
        $this->assertTrue( $s->enabledFor( 1, 'store' ) );
        $this->assertFalse( $s->enabledFor( 1, 'admin7x' ) );
        $this->assertFalse( $this->cfg( array( 'Enabled' => 'enabled', 'Mode' => 'nonsense' ) )->enabledFor( 1, 'store' ), 'unusable settings offer nothing' );
        $this->assertFalse( $this->cfg( array() )->acceptsPayment(), 'a sandbox capture completes nothing by default' );
        $this->assertTrue( $this->cfg( array( 'AcceptSandboxPayments' => 'enabled' ) )->acceptsPayment() );
    }

    public function testSdkUrlCarriesOnlyPublicValues()
    {
        $c = $this->cfg( array( 'Currency' => 'eur', 'EnableFunding' => array( 'paylater' ), 'DisableFunding' => array( 'venmo' ), 'Locale' => 'en_US' ) );
        $u = $c->sdkUrl( 'authorize' );
        $this->assertStringStartsWith( 'https://www.paypal.com/sdk/js?', $u );
        $this->assertStringContainsString( 'client-id=' . str_repeat( 'A', 80 ), $u );
        $this->assertStringContainsString( 'currency=EUR', $u );
        $this->assertStringContainsString( 'intent=authorize', $u );
        $this->assertStringContainsString( 'enable-funding=paylater', $u );
        $this->assertStringContainsString( 'disable-funding=venmo', $u );
        $this->assertStringNotContainsString( str_repeat( 'B', 20 ), $u, 'never the secret' );
    }
}

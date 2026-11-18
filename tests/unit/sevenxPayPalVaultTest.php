<?php
use PHPUnit\Framework\TestCase;

/** Vault v3: save during purchase, save without a purchase, charge a saved method (merchant-initiated), remove, webhooks. */
class sevenxPayPalVaultTest extends TestCase
{
    protected $http;
    protected $p;
    protected $v;

    protected function setUp(): void
    {
        sevenxPayPalClient::resetTokens();
        $this->http = new sevenxPayPalMockHttp();
        $cfg = new sevenxPayPalConfig( array( 'Vault' => 'enabled', 'BrandName' => 'Test Shop' ), function () { return str_repeat( 'k', 80 ); } );
        $this->p = new sevenxPayPalPayments( new sevenxPayPalClient( $cfg, $this->http ), new sevenxPayPalMemoryStore() );
        $this->v = new sevenxPayPalVault( $this->p );
    }

    public function testPureParts()
    {
        $this->assertSame( 'b***@example.com', sevenxPayPalVault::maskEmail( 'buyer@example.com' ) );
        $this->assertSame( '', sevenxPayPalVault::maskEmail( 'nonsense' ) );
        $this->assertSame( array( 'method' => 'card', 'label' => 'Visa 1881' ),
                           sevenxPayPalVault::describeSource( array( 'card' => array( 'brand' => 'VISA', 'last_digits' => '1881', 'expiry' => '2030-01', 'name' => 'Some Body' ) ) ) );
        $d = sevenxPayPalVault::describeSource( array( 'paypal' => array( 'email_address' => 'buyer@example.com', 'name' => array( 'given_name' => 'Some' ) ) ) );
        $this->assertSame( 'PayPal b***@example.com', $d['label'], 'only a masked e-mail, no name' );
        $src = sevenxPayPalVault::vaultSource( array( 'customer_id' => 'CUST1', 'return_url' => 'https://shop.example.com/r' ) );
        $this->assertSame( 'ON_SUCCESS', $src['paypal']['attributes']['vault']['store_in_vault'] );
        $this->assertSame( 'MERCHANT', $src['paypal']['attributes']['vault']['usage_type'] );
        $this->assertSame( 'CUST1', $src['paypal']['attributes']['customer']['id'] );
        $this->assertTrue( sevenxPayPalVault::enabled( $this->p->client()->config() ) );
        $c = sevenxPayPalVault::chargeSource( array( 'method' => 'paypal', 'payment_token_id' => 'TOK1' ) );
        $this->assertSame( 'TOK1', $c['paypal']['vault_id'] );
        $this->assertSame( 'MERCHANT', $c['paypal']['stored_credential']['payment_initiator'] );
        $c = sevenxPayPalVault::chargeSource( array( 'method' => 'card', 'payment_token_id' => 'TOK2' ), 'RECURRING' );
        $this->assertSame( 'RECURRING', $c['card']['stored_credential']['payment_type'] );
    }

    public function testSaveDuringPurchaseKeepsOnlyTheToken()
    {
        $order = array( 'id' => 'O1', 'status' => 'COMPLETED', 'payment_source' => array( 'paypal' => array( 'email_address' => 'buyer@example.com', 'account_id' => 'PAYERID',
            'name' => array( 'given_name' => 'Some', 'surname' => 'Body' ), 'attributes' => array( 'vault' => array( 'id' => 'TOKEN1', 'status' => 'VAULTED',
            'customer' => array( 'id' => 'CUST1' ) ) ) ) ) );
        $r = $this->v->rememberFromOrder( $order, 77, 'shop' );
        $this->assertTrue( $r['ok'] );
        $rows = $this->v->methods( 77 );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'TOKEN1', $rows[0]['payment_token_id'] );
        $this->assertSame( 'CUST1', $this->v->customerId( 77 ) );
        $this->assertStringNotContainsString( 'buyer@example.com', json_encode( $rows ) );
        $this->assertStringNotContainsString( 'Body', json_encode( $rows ) );
        $this->assertFalse( $this->v->rememberFromOrder( $order, 78 )['ok'], 'another user cannot take the token' );
        $this->assertFalse( $this->v->rememberFromOrder( array( 'id' => 'O2' ), 77 )['ok'] );
    }

    public function testSaveWithoutAPurchase()
    {
        $this->http->on( 'POST', '#^/v3/vault/setup-tokens$#', function ( $m, $u, $h, $body ) {
            $b = json_decode( $body, true );
            return array( 'status' => 201, 'body' => array( 'id' => 'SETUP1', 'status' => 'PAYER_ACTION_REQUIRED', 'echo' => $b,
                'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=SETUP1' ) ) ) );
        } );
        $s = $this->v->startSetup( 77, array( 'return_url' => 'https://shop.example.com/back' ) );
        $this->assertTrue( $s['ok'] );
        $this->assertSame( 'SETUP1', $s['setup_token'] );
        $this->assertStringContainsString( 'approve', $s['approve_url'] );
        $sent = json_decode( $this->http->to( '#setup-tokens$#' )[0]['body'], true );
        $this->assertSame( 'MERCHANT', $sent['payment_source']['paypal']['usage_type'] );
        $this->assertSame( 'Test Shop', $sent['payment_source']['paypal']['experience_context']['brand_name'] );
        $this->http->on( 'POST', '#^/v3/vault/payment-tokens$#', array( 'status' => 201, 'body' => array( 'id' => 'TOKEN2', 'customer' => array( 'id' => 'CUST2' ),
            'payment_source' => array( 'paypal' => array( 'email_address' => 'other@example.com' ) ) ) ) );
        $f = $this->v->finishSetup( 77, 'SETUP1', 'account' );
        $this->assertTrue( $f['ok'] );
        $this->assertSame( 'PayPal o***@example.com', $f['label'] );
        $this->assertSame( 'SETUP_TOKEN', json_decode( $this->http->to( '#payment-tokens$#' )[0]['body'], true )['payment_source']['token']['type'] );
    }

    public function testACardSetupIsSandboxOnlyAndNeedsNoApproval()
    {
        $this->http->on( 'POST', '#^/v3/vault/setup-tokens$#', array( 'status' => 201, 'body' => array( 'id' => 'SETUP2', 'status' => 'APPROVED' ) ) );
        $s = $this->v->startSetup( 77, array( 'card' => array( 'number' => '4012000033330026', 'expiry' => '2030-01' ) ) );
        $this->assertTrue( $s['ok'] );
        $this->assertSame( 'APPROVED', $s['status'] );
    }

    public function testChargeASavedMethodMerchantInitiated()
    {
        $this->v->remember( 77, 'TOKEN1', 'CUST1', 'paypal', 'PayPal b***@example.com' );
        $row = $this->v->methods( 77 )[0];
        $this->http->on( 'POST', '#^/v2/checkout/orders$#', function ( $m, $u, $h, $body ) {
            $b = json_decode( $body, true );
            $u0 = $b['purchase_units'][0];
            return array( 'status' => 201, 'body' => sevenxPayPalMockHttp::order( 'VORDER1', 'COMPLETED', $u0['custom_id'], $u0['amount']['value'], 'USD',
                array( 'captures' => array( array( 'id' => 'VCAP1', 'status' => 'COMPLETED', 'custom_id' => $u0['custom_id'], 'amount' => $u0['amount'] ) ) ) ) );
        } );
        $this->http->on( 'POST', '#VORDER1/capture$#', array( 'status' => 422, 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'ORDER_ALREADY_CAPTURED' ) ) ) ) );
        $this->http->on( 'GET', '#^/v2/checkout/orders/VORDER1$#', function () {
            return array( 'status' => 200, 'body' => sevenxPayPalMockHttp::order( 'VORDER1', 'COMPLETED', 'renew:1', '9.99', 'USD',
                array( 'captures' => array( array( 'id' => 'VCAP1', 'status' => 'COMPLETED', 'custom_id' => 'renew:1', 'amount' => array( 'currency_code' => 'USD', 'value' => '9.99' ) ) ) ) ) );
        } );
        $r = $this->v->charge( $row, array( 'reference' => 'renew:1', 'amount_minor' => 999, 'currency' => 'USD', 'description' => 'Renewal' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'VCAP1', $r['capture_id'] );
        $body = json_decode( $this->http->to( '#^/v2/checkout/orders$#' )[0]['body'], true );
        $this->assertSame( 'TOKEN1', $body['payment_source']['paypal']['vault_id'] );
        $this->assertSame( 'MERCHANT', $body['payment_source']['paypal']['stored_credential']['payment_initiator'] );
        $this->assertArrayNotHasKey( 'application_context', $body, 'no buyer approval' );
        $this->assertSame( '9.99', $body['purchase_units'][0]['amount']['value'] );
    }

    public function testADeclinedSavedMethodIsReported()
    {
        $this->v->remember( 77, 'TOKEN1', 'CUST1', 'paypal', 'PayPal' );
        $this->http->on( 'POST', '#^/v2/checkout/orders$#', array( 'status' => 422, 'body' => array( 'name' => 'UNPROCESSABLE_ENTITY', 'details' => array( array( 'issue' => 'INSTRUMENT_DECLINED' ) ) ) ) );
        $r = $this->v->charge( $this->v->methods( 77 )[0], array( 'reference' => 'renew:2', 'amount_minor' => 500, 'currency' => 'USD' ) );
        $this->assertFalse( $r['ok'] );
        $this->assertTrue( $r['declined'] );
    }

    public function testRemoveAndTheWebhooks()
    {
        $this->v->remember( 77, 'TOKEN1', 'CUST1', 'paypal', 'PayPal' );
        $row = $this->v->methods( 77 )[0];
        $this->assertNull( $this->v->method( 78, $row['id'] ), 'another user cannot remove it' );
        $this->http->on( 'DELETE', '#^/v3/vault/payment-tokens/TOKEN1$#', array( 'status' => 204, 'body' => array() ) );
        $this->assertTrue( $this->v->delete( $row )['ok'] );
        $this->assertCount( 0, $this->v->methods( 77 ) );
        $h = new sevenxPayPalCoreWebhookHandler();
        $created = array( 'id' => 'WH-V1', 'event_type' => 'VAULT.PAYMENT-TOKEN.CREATED', 'resource' => array( 'id' => 'TOKEN9', 'customer' => array( 'id' => 'CUST1' ),
                          'payment_source' => array( 'card' => array( 'brand' => 'VISA', 'last_digits' => '1881' ) ) ) );
        $this->assertSame( 'saved', $h->handle( $created, null, $this->p ), 'a token of a known customer is kept for that user' );
        $this->assertSame( 'already', $h->handle( $created, null, $this->p ) );
        $this->assertSame( 'Visa 1881', $this->v->methods( 77 )[0]['label'] );
        $this->assertSame( 'unknown', $h->handle( array( 'event_type' => 'VAULT.PAYMENT-TOKEN.CREATED', 'resource' => array( 'id' => 'X', 'customer' => array( 'id' => 'NOBODY' ) ) ), null, $this->p ) );
        $this->assertSame( 'deleted', $h->handle( array( 'event_type' => 'VAULT.PAYMENT-TOKEN.DELETED', 'resource' => array( 'id' => 'TOKEN9' ) ), null, $this->p ) );
        $this->assertSame( 'already', $h->handle( array( 'event_type' => 'VAULT.PAYMENT-TOKEN.DELETED', 'resource' => array( 'id' => 'TOKEN9' ) ), null, $this->p ) );
    }

    public function testTheIdTokenOfAReturningBuyer()
    {
        $this->http->on( 'POST', '#^/v1/oauth2/token$#', function ( $m, $u, $h, $body ) {
            return array( 'status' => 200, 'body' => strpos( $body, 'response_type=id_token' ) !== false
                ? array( 'access_token' => 'A', 'id_token' => 'IDTOKEN.' . ( strpos( $body, 'target_customer_id=CUST1' ) !== false ? 'CUST1' : 'none' ), 'expires_in' => 900 )
                : array( 'access_token' => 'A', 'expires_in' => 900 ) );
        } );
        $this->v->remember( 77, 'TOKEN1', 'CUST1', 'paypal', 'PayPal' );
        $this->assertSame( 'IDTOKEN.CUST1', $this->v->idTokenFor( 77 ) );
    }

    public function testTheSchemaDefinesTheVaultTableForEveryDatabase()
    {
        require_once __DIR__ . '/../../classes/sevenxpaypalschema.php';
        $d = sevenxPayPalSchema::definition();
        $this->assertSame( sevenxPayPalSchema::TABLES, array_keys( $d ) );
        foreach ( $d as $t => $def )
            foreach ( array_keys( $def['indexes'] ) as $i )
                $this->assertLessThanOrEqual( 30, strlen( $i ), "$t index $i fits Oracle" );
        $this->assertArrayNotHasKey( 'number', $d['sevenx_paypal_vault']['fields'], 'only a token, never card data' );
        foreach ( array( 'postgresql', 'mysql', 'sqlite' ) as $e )
            $this->assertStringContainsString( 'CREATE TABLE sevenx_paypal_vault', file_get_contents( __DIR__ . "/../../sql/$e/schema.sql" ) );
        foreach ( array_keys( $d['sevenx_paypal_vault']['fields'] ) as $f )
            $this->assertStringContainsString( $f, file_get_contents( __DIR__ . '/../../sql/postgresql/schema.sql' ) );
        $m = sevenxPayPalSchema::mongoDescription();
        $this->assertCount( 3, $m['collections'] );
    }
}

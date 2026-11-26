<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * Saved payment methods with PayPal's Vault v3 (payment method tokens; doc/vault.md):
 *
 *   during a checkout  begin() with vaultSource(): payment_source.paypal.attributes.vault (store_in_vault ON_SUCCESS,
 *                      usage_type MERCHANT, the buyer's PayPal customer id when there is one); after the capture
 *                      rememberFromOrder() keeps the token the order answers
 *   without a purchase startSetup() (POST /v3/vault/setup-tokens; the buyer approves), finishSetup() (POST
 *                      /v3/vault/payment-tokens)
 *   later              charge(): an Orders v2 order with payment_source.paypal.vault_id (or card.vault_id) and
 *                      stored_credential, merchant-initiated; captured and checked like every payment
 *   remove             delete(): DELETE /v3/vault/payment-tokens/{id}, the row marked deleted
 *   webhooks           VAULT.PAYMENT-TOKEN.CREATED / DELETED keep the rows right (sevenxPayPalCoreWebhookHandler)
 *
 * sevenx_paypal_vault keeps per Exponential user only PayPal's payment token id and customer id, the method (paypal,
 * card) and a masked label for display (b***@example.com, Visa 1881); never a card number, expiry or address.
 */
class sevenxPayPalVault
{
    const T = 'sevenx_paypal_vault';

    /** @var sevenxPayPalPayments */
    protected $payments;
    public $now = null;

    public function __construct( sevenxPayPalPayments $payments )
    {
        $this->payments = $payments;
    }

    public static function instance( $mode = null )
    {
        return new self( sevenxPayPalPayments::instance( $mode ) );
    }

    protected function client()
    {
        return $this->payments->client();
    }

    protected function store()
    {
        return $this->payments->store();
    }

    protected function now()
    {
        return $this->now !== null ? (int)$this->now : time();
    }

    protected static function ok( $m, array $x = array() )
    {
        return array( 'ok' => true, 'message' => (string)$m ) + $x;
    }

    protected static function fail( $m, array $x = array() )
    {
        return array( 'ok' => false, 'message' => (string)$m ) + $x;
    }

    /** Whether saved payment methods are switched on ([PayPalSettings] Vault=enabled). */
    public static function enabled( sevenxPayPalConfig $cfg )
    {
        return strtolower( (string)$cfg->value( 'Vault', 'disabled' ) ) === 'enabled';
    }

    // ------------------------------------------------------------------------------------------- pure parts

    /** A masked e-mail address for display: b***@example.com. */
    public static function maskEmail( $email )
    {
        $email = (string)$email;
        $at = strrpos( $email, '@' );
        if ( $at === false || $at < 1 )
            return '';
        return mb_substr( $email, 0, 1 ) . '***' . mb_substr( $email, $at );
    }

    /**
     * Pure: the method and display label of a payment token's payment_source (PayPal: the masked e-mail; card: brand
     * and last digits). Nothing else of the source is kept.
     */
    public static function describeSource( array $source )
    {
        if ( isset( $source['paypal'] ) )
        {
            $p = (array)$source['paypal'];
            $mail = isset( $p['email_address'] ) ? self::maskEmail( $p['email_address'] ) : '';
            return array( 'method' => 'paypal', 'label' => 'PayPal' . ( $mail !== '' ? ' ' . $mail : '' ) );
        }
        if ( isset( $source['card'] ) )
        {
            $c = (array)$source['card'];
            $brand = isset( $c['brand'] ) ? ucfirst( strtolower( (string)$c['brand'] ) ) : 'Card';
            $last = isset( $c['last_digits'] ) && preg_match( '/^\d{2,4}$/D', (string)$c['last_digits'] ) ? ' ' . $c['last_digits'] : '';
            return array( 'method' => 'card', 'label' => $brand . $last );
        }
        if ( isset( $source['venmo'] ) )
            return array( 'method' => 'venmo', 'label' => 'Venmo' );
        return array( 'method' => 'other', 'label' => 'Saved payment method' );
    }

    /**
     * Pure: the vault answer of a captured or authorized order (payment_source.<kind>.attributes.vault), or null:
     * id (the payment token), status (VAULTED, APPROVED ...), customer id, method, label.
     */
    public static function vaultOfOrder( array $order )
    {
        $src = isset( $order['payment_source'] ) && is_array( $order['payment_source'] ) ? $order['payment_source'] : array();
        foreach ( $src as $kind => $s )
        {
            $v = isset( $s['attributes']['vault'] ) && is_array( $s['attributes']['vault'] ) ? $s['attributes']['vault'] : null;
            if ( !$v || empty( $v['id'] ) )
                continue;
            $d = self::describeSource( array( $kind => $s ) );
            return array( 'id' => (string)$v['id'], 'status' => isset( $v['status'] ) ? (string)$v['status'] : '',
                          'customer_id' => isset( $v['customer']['id'] ) ? (string)$v['customer']['id'] : '', 'method' => $d['method'], 'label' => $d['label'] );
        }
        return null;
    }

    /**
     * Pure: the payment source of an order that also saves the method (save during purchase).
     *
     * @param array $o customer_id, return_url, cancel_url, brand_name, kind (paypal, default)
     */
    public static function vaultSource( array $o = array() )
    {
        $attrs = array( 'vault' => array( 'store_in_vault' => 'ON_SUCCESS', 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER' ) );
        if ( !empty( $o['customer_id'] ) )
            $attrs['customer'] = array( 'id' => (string)$o['customer_id'] );
        $ctx = array( 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW' );
        foreach ( array( 'return_url', 'cancel_url', 'brand_name' ) as $k )
            if ( !empty( $o[$k] ) )
                $ctx[$k] = (string)$o[$k];
        return array( 'paypal' => array( 'attributes' => $attrs, 'experience_context' => $ctx ) );
    }

    /**
     * Pure: the payment source of a merchant-initiated charge of a saved method. stored_credential tells PayPal (and
     * the card networks) the buyer is not present and agreed to it before.
     *
     * @param string $usage UNSCHEDULED (the default: a renewal of a varying amount) or RECURRING
     */
    public static function chargeSource( array $row, $usage = 'UNSCHEDULED' )
    {
        $pattern = $usage === 'RECURRING' ? 'RECURRING_POSTPAID' : 'UNSCHEDULED_POSTPAID';
        if ( $row['method'] === 'card' )
            return array( 'card' => array( 'vault_id' => (string)$row['payment_token_id'],
                                           'stored_credential' => array( 'payment_initiator' => 'MERCHANT', 'payment_type' => $usage === 'RECURRING' ? 'RECURRING' : 'UNSCHEDULED',
                                                                         'usage' => 'SUBSEQUENT' ) ) );
        return array( 'paypal' => array( 'vault_id' => (string)$row['payment_token_id'],
                                         'stored_credential' => array( 'payment_initiator' => 'MERCHANT', 'charge_pattern' => $pattern, 'usage' => 'SUBSEQUENT' ) ) );
    }

    // ------------------------------------------------------------------------------------------- rows

    /** The active saved methods of a user (this mode). */
    public function methods( $userId )
    {
        return $this->store()->find( self::T, array( 'user_id' => (int)$userId, 'status' => 'active', 'mode' => $this->client()->mode() ), 'id DESC', 20 );
    }

    /** One active row of the user, or null. */
    public function method( $userId, $rowId )
    {
        $r = $this->store()->get( self::T, (int)$rowId );
        return $r && (int)$r['user_id'] === (int)$userId && $r['status'] === 'active' ? $r : null;
    }

    /** The user's PayPal customer id in this mode ('' when none). */
    public function customerId( $userId )
    {
        foreach ( $this->store()->find( self::T, array( 'user_id' => (int)$userId, 'mode' => $this->client()->mode() ), 'id DESC', 5 ) as $r )
            if ( $r['customer_id'] !== '' )
                return (string)$r['customer_id'];
        return '';
    }

    /** Writes (or revives) the row of a payment token. */
    public function remember( $userId, $tokenId, $customerId, $method, $label, $context = '' )
    {
        $old = $this->store()->find( self::T, array( 'payment_token_id' => (string)$tokenId ), 'id', 1 );
        if ( $old )
        {
            if ( (int)$old[0]['user_id'] !== (int)$userId && (int)$userId > 0 && (int)$old[0]['user_id'] > 0 )
                return self::fail( 'the saved method belongs to another user' );
            $this->store()->update( self::T, $old[0]['id'], array( 'status' => 'active', 'customer_id' => (string)$customerId !== '' ? (string)$customerId : $old[0]['customer_id'],
                                                                   'label' => substr( (string)$label, 0, 128 ) ?: $old[0]['label'], 'modified' => $this->now() ) );
            return self::ok( 'saved already', array( 'id' => (int)$old[0]['id'], 'changed' => false ) );
        }
        $id = $this->store()->insert( self::T, array( 'user_id' => (int)$userId, 'mode' => $this->client()->mode(), 'customer_id' => substr( (string)$customerId, 0, 64 ),
            'payment_token_id' => substr( (string)$tokenId, 0, 64 ), 'method' => substr( (string)$method, 0, 16 ), 'label' => substr( (string)$label, 0, 128 ),
            'status' => 'active', 'usage_type' => 'MERCHANT', 'context' => substr( (string)$context, 0, 32 ), 'created' => $this->now(), 'modified' => $this->now() ) );
        sevenxPayPalPayments::log( 'vault saved token=' . $tokenId . ' user=' . (int)$userId . ' method=' . $method );
        return self::ok( 'saved', array( 'id' => $id, 'changed' => true ) );
    }

    /** After a capture of an order that saved the method: keeps its token for the user. */
    public function rememberFromOrder( array $order, $userId, $context = '' )
    {
        $v = self::vaultOfOrder( $order );
        if ( !$v )
            return self::fail( 'the order saved no payment method' );
        if ( !in_array( $v['status'], array( 'VAULTED', 'APPROVED', 'CREATED', '' ), true ) )
            return self::fail( 'the payment method was not saved (' . $v['status'] . ')' );
        return $this->remember( $userId, $v['id'], $v['customer_id'], $v['method'], $v['label'], $context );
    }

    // ------------------------------------------------------------------------------------------- without a purchase

    /**
     * A setup token for the user to approve (PayPal: the approval link or the JS SDK's createVaultSetupToken; a card:
     * only in the sandbox, the tests' card, which needs no approval).
     *
     * @param array $o return_url, cancel_url, brand_name, card (sandbox test card fields)
     * @return array ok, setup_token, approve_url, status
     */
    public function startSetup( $userId, array $o = array() )
    {
        if ( !empty( $o['card'] ) )
        {
            if ( $this->client()->mode() === 'live' )
                return self::fail( 'a card is saved through PayPal\'s own fields, never through this server' );
            $source = array( 'card' => (array)$o['card'] + array( 'attributes' => array( 'vault' => array( 'usage_type' => 'MERCHANT' ) ) ) );
        }
        else
        {
            $ctx = array( 'shipping_preference' => 'NO_SHIPPING', 'vault_instruction' => 'ON_PAYER_APPROVAL' );
            foreach ( array( 'return_url', 'cancel_url', 'brand_name' ) as $k )
                if ( !empty( $o[$k] ) )
                    $ctx[$k] = (string)$o[$k];
            if ( empty( $ctx['brand_name'] ) && $this->client()->config()->brandName() !== '' )
                $ctx['brand_name'] = $this->client()->config()->brandName();
            $source = array( 'paypal' => array( 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER', 'experience_context' => $ctx ) );
        }
        try
        {
            $t = $this->client()->createSetupToken( $source, $this->customerId( $userId ), 'sxpp-setup-' . (int)$userId . '-' . bin2hex( random_bytes( 6 ) ) );
        }
        catch ( sevenxPayPalError $e )
        {
            sevenxPayPalPayments::log( 'vault setup refused user=' . (int)$userId . ' issue=' . $e->issue . ' debug=' . $e->debugId );
            return self::fail( 'PayPal did not start saving the method: ' . $e->getMessage(), array( 'issue' => $e->issue !== '' ? $e->issue : $e->name ) );
        }
        return self::ok( 'setup ready', array( 'setup_token' => (string)$t['id'], 'status' => isset( $t['status'] ) ? (string)$t['status'] : '',
                                               'approve_url' => sevenxPayPalClient::approveUrl( $t ) ) );
    }

    /** After the approval: the payment token, kept for the user. @return array ok, id (row), label */
    public function finishSetup( $userId, $setupTokenId, $context = '' )
    {
        try
        {
            $tok = $this->client()->createPaymentToken( $setupTokenId, 'sxpp-token-' . $setupTokenId );
        }
        catch ( sevenxPayPalError $e )
        {
            return self::fail( 'PayPal did not save the method: ' . $e->getMessage(), array( 'issue' => $e->issue !== '' ? $e->issue : $e->name ) );
        }
        $d = self::describeSource( isset( $tok['payment_source'] ) ? (array)$tok['payment_source'] : array() );
        $r = $this->remember( $userId, (string)$tok['id'], isset( $tok['customer']['id'] ) ? (string)$tok['customer']['id'] : '', $d['method'], $d['label'], $context );
        return $r + array( 'label' => $d['label'], 'token' => (string)$tok['id'] );
    }

    // ------------------------------------------------------------------------------------------- use and remove

    /**
     * A merchant-initiated charge of a saved method (a renewal): an order with the vault id and stored_credential,
     * captured and checked like every payment (sevenxPayPalPayments).
     *
     * @param array $o reference, custom_id, amount_minor, currency, description, context, usage (UNSCHEDULED|RECURRING), request_id
     * @return array ok, status, capture_id, order_id, declined (bool)
     */
    public function charge( array $row, array $o )
    {
        if ( $row['status'] !== 'active' || $row['mode'] !== $this->client()->mode() )
            return self::fail( 'the saved method cannot be used' );
        $b = $this->payments->begin( array( 'context' => isset( $o['context'] ) ? $o['context'] : 'vault', 'reference' => (string)$o['reference'],
                                            'custom_id' => isset( $o['custom_id'] ) ? (string)$o['custom_id'] : '', 'amount_minor' => (int)$o['amount_minor'],
                                            'currency' => (string)$o['currency'], 'intent' => 'CAPTURE', 'description' => isset( $o['description'] ) ? (string)$o['description'] : '',
                                            'user_id' => (int)$row['user_id'], 'request_id' => isset( $o['request_id'] ) ? (string)$o['request_id'] : '',
                                            'payment_source' => self::chargeSource( $row, isset( $o['usage'] ) ? (string)$o['usage'] : 'UNSCHEDULED' ),
                                            'meta' => array( 'vault_row' => (int)$row['id'] ) ) );
        if ( !$b['ok'] )
            return self::fail( $b['message'], array( 'declined' => in_array( isset( $b['issue'] ) ? $b['issue'] : '', array( 'INSTRUMENT_DECLINED', 'PAYER_ACTION_REQUIRED', 'PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING' ), true ),
                                                    'issue' => isset( $b['issue'] ) ? $b['issue'] : '' ) );
        // PayPal completes a vaulted charge at once; capture() reads that capture and checks it
        $c = $this->payments->capture( $b['order_id'] );
        return $c + array( 'order_id' => $b['order_id'], 'declined' => !$c['ok'] && !empty( $c['retry_buyer'] ) );
    }

    /** Removes a saved method at PayPal and here (a token PayPal no longer knows is removed here all the same). */
    public function delete( array $row )
    {
        try
        {
            $this->client()->deletePaymentToken( $row['payment_token_id'] );
        }
        catch ( sevenxPayPalError $e )
        {
            if ( $e->httpStatus !== 404 )
                return self::fail( 'PayPal did not remove the method: ' . $e->getMessage() );
        }
        $this->store()->update( self::T, $row['id'], array( 'status' => 'deleted', 'modified' => $this->now() ) );
        sevenxPayPalPayments::log( 'vault deleted token=' . $row['payment_token_id'] . ' user=' . (int)$row['user_id'] );
        return self::ok( 'removed' );
    }

    /** The JS SDK's data-user-id-token for a returning buyer with saved PayPal ('' when none or PayPal refused). */
    public function idTokenFor( $userId )
    {
        try
        {
            return $this->client()->idToken( $this->customerId( $userId ) );
        }
        catch ( sevenxPayPalError $e )
        {
            return '';
        }
    }

    /** The webhook's part: VAULT.PAYMENT-TOKEN.CREATED keeps a token of a known customer, DELETED marks it deleted. */
    public function fromWebhook( array $event )
    {
        $r = isset( $event['resource'] ) ? (array)$event['resource'] : array();
        $id = isset( $r['id'] ) ? (string)$r['id'] : '';
        if ( $id === '' )
            return 'unknown';
        $rows = $this->store()->find( self::T, array( 'payment_token_id' => $id ), 'id', 1 );
        if ( $event['event_type'] === 'VAULT.PAYMENT-TOKEN.DELETED' )
        {
            if ( !$rows )
                return 'unknown';
            if ( $rows[0]['status'] === 'deleted' )
                return 'already';
            $this->store()->update( self::T, $rows[0]['id'], array( 'status' => 'deleted', 'modified' => $this->now() ) );
            return 'deleted';
        }
        if ( $rows )
            return 'already';
        $cust = isset( $r['customer']['id'] ) ? (string)$r['customer']['id'] : '';
        $known = $cust !== '' ? $this->store()->find( self::T, array( 'customer_id' => $cust ), 'id DESC', 1 ) : array();
        if ( !$known )
            return 'unknown';
        $d = self::describeSource( isset( $r['payment_source'] ) ? (array)$r['payment_source'] : array() );
        $this->remember( (int)$known[0]['user_id'], $id, $cust, $d['method'], $d['label'], 'webhook' );
        return 'saved';
    }
}

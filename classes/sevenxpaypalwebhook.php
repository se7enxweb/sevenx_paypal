<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * A handler of webhook events (paypal.ini [WebhookSettings] Handlers[]): the built-in one keeps the transaction rows
 * and the shop's orders right; an extension (Katalyst) adds its own for its ledger. Each one is called once per new
 * event, after the signature checked out, and answers a short result word (paid, refunded, ignored, ...).
 */
interface sevenxPayPalWebhookHandler
{
    /**
     * @param array      $event       the event (id, event_type, resource_type, resource)
     * @param array|null $transaction the sevenx_paypal_transaction row the event is about, or null
     * @return string a short result ('ignored' when the event is not this handler's)
     */
    public function handle( array $event, ?array $transaction, sevenxPayPalPayments $payments );
}

/**
 * The webhook endpoint's work (doc/webhooks.md): the delivery is verified (PayPal's verify-webhook-signature by
 * default, the local certificate check with Verification=local, both with Verification=both), stored once by event
 * id (a replay is acknowledged and changes nothing) and handed to the handlers. Only ids, types, states and amounts
 * are stored, never the payer's name, e-mail or address.
 */
class sevenxPayPalWebhook
{
    const T = 'sevenx_paypal_event';

    /** @var sevenxPayPalPayments */
    protected $payments;
    /** @var sevenxPayPalWebhookHandler[] */
    protected $handlers;
    /** @var callable|null tests: (headers, raw, webhook id) => bool instead of asking PayPal */
    public $verifier = null;

    public function __construct( sevenxPayPalPayments $payments, ?array $handlers = null )
    {
        $this->payments = $payments;
        $this->handlers = $handlers !== null ? $handlers : self::configuredHandlers( $payments->client()->config() );
    }

    /** The handlers of [WebhookSettings] Handlers[] (classes implementing sevenxPayPalWebhookHandler). */
    public static function configuredHandlers( sevenxPayPalConfig $config )
    {
        $names = array( 'sevenxPayPalCoreWebhookHandler' );
        if ( class_exists( 'eZINI' ) )
        {
            $ini = eZINI::instance( 'paypal.ini' );
            if ( $ini->hasVariable( 'WebhookSettings', 'Handlers' ) )
                $names = array_merge( $names, (array)$ini->variable( 'WebhookSettings', 'Handlers' ) );
        }
        $out = array();
        foreach ( array_unique( array_filter( array_map( 'strval', $names ) ) ) as $n )
            if ( class_exists( $n ) && is_subclass_of( $n, 'sevenxPayPalWebhookHandler' ) )
                $out[] = new $n();
        return $out;
    }

    protected static function setting( $name, $default )
    {
        if ( !class_exists( 'eZINI' ) )
            return $default;
        $ini = eZINI::instance( 'paypal.ini' );
        return $ini->hasVariable( 'WebhookSettings', $name ) ? $ini->variable( 'WebhookSettings', $name ) : $default;
    }

    /** Whether a delivery is genuine. */
    public function verify( array $headers, $raw )
    {
        $hookId = $this->payments->client()->config()->webhookId;
        if ( $this->verifier )
            return (bool)call_user_func( $this->verifier, $headers, $raw, $hookId );
        if ( $hookId === '' )
            return false;
        $how = strtolower( (string)self::setting( 'Verification', 'api' ) );
        $local = null;
        if ( $how === 'local' || $how === 'both' )
        {
            $h = sevenxPayPalWebhookSignature::headers( $headers );
            $pem = $h ? sevenxPayPalWebhookSignature::fetchCert( $h['cert_url'] ) : '';
            $local = $pem !== '' && sevenxPayPalWebhookSignature::verifyLocal( $headers, $raw, $hookId, $pem );
            if ( $how === 'local' || !$local )
                return $local;
        }
        try
        {
            return $this->payments->client()->verifyWebhookSignature( $headers, $raw, $hookId );
        }
        catch ( sevenxPayPalError $e )
        {
            sevenxPayPalPayments::log( 'webhook verification could not ask PayPal: ' . $e->name . ' ' . $e->debugId );
            return false;
        }
    }

    /**
     * Pure: what is kept of an event (no personal data): ids, type, resource type and id, the PayPal order id it
     * belongs to, status, amount and custom_id.
     */
    public static function summary( array $event )
    {
        $r = isset( $event['resource'] ) && is_array( $event['resource'] ) ? $event['resource'] : array();
        $order = '';
        if ( isset( $r['supplementary_data']['related_ids']['order_id'] ) )
            $order = (string)$r['supplementary_data']['related_ids']['order_id'];
        elseif ( isset( $event['resource_type'] ) && $event['resource_type'] === 'checkout-order' && isset( $r['id'] ) )
            $order = (string)$r['id'];
        $amount = '';
        if ( isset( $r['amount']['value'], $r['amount']['currency_code'] ) )
            $amount = $r['amount']['value'] . ' ' . $r['amount']['currency_code'];
        elseif ( isset( $r['purchase_units'][0]['amount']['value'] ) )
            $amount = $r['purchase_units'][0]['amount']['value'] . ' ' . $r['purchase_units'][0]['amount']['currency_code'];
        $custom = isset( $r['custom_id'] ) ? (string)$r['custom_id'] : ( isset( $r['purchase_units'][0]['custom_id'] ) ? (string)$r['purchase_units'][0]['custom_id'] : '' );
        return array( 'event_id' => isset( $event['id'] ) ? substr( (string)$event['id'], 0, 64 ) : '',
                      'event_type' => isset( $event['event_type'] ) ? substr( (string)$event['event_type'], 0, 64 ) : '',
                      'resource_type' => isset( $event['resource_type'] ) ? substr( (string)$event['resource_type'], 0, 32 ) : '',
                      'resource_id' => isset( $r['id'] ) ? substr( (string)$r['id'], 0, 64 ) : '', 'order_id' => substr( $order, 0, 64 ),
                      'summary' => substr( trim( ( isset( $r['status'] ) ? $r['status'] : '' ) . ' ' . $amount . ( $custom !== '' ? ' ' . $custom : '' ) ), 0, 255 ) );
    }

    /**
     * Handles one delivery: 400 for a malformed one, 401 when the signature does not check out, 200 for a stored
     * (or replayed) event. PayPal retries anything that is not 2xx.
     *
     * @return array status (HTTP), result, duplicate
     */
    public function receive( array $headers, $raw )
    {
        $event = json_decode( (string)$raw, true );
        if ( !is_array( $event ) || empty( $event['id'] ) || empty( $event['event_type'] ) || strlen( (string)$raw ) > 1048576 )
            return array( 'status' => 400, 'result' => 'malformed', 'duplicate' => false );
        if ( !$this->verify( $headers, $raw ) )
        {
            sevenxPayPalPayments::log( 'webhook refused: signature did not verify, event=' . substr( (string)$event['id'], 0, 64 ) );
            return array( 'status' => 401, 'result' => 'signature', 'duplicate' => false );
        }
        $s = self::summary( $event );
        $store = $this->payments->store();
        if ( $old = $store->find( self::T, array( 'event_id' => $s['event_id'] ), 'id', 1 ) )
        {
            if ( (int)$old[0]['processed'] > 0 )
                return array( 'status' => 200, 'result' => 'duplicate', 'duplicate' => true );
            $rowId = (int)$old[0]['id'];
        }
        else
        {
            try
            {
                $rowId = $store->insert( self::T, $s + array( 'mode' => $this->payments->client()->mode(), 'verified' => 1, 'received' => time(), 'processed' => 0,
                                                              'result' => '', 'attempts' => 0 ) );
            }
            catch ( RuntimeException $e )
            {
                // a concurrent delivery of the same event won the unique index
                return array( 'status' => 200, 'result' => 'duplicate', 'duplicate' => true );
            }
        }
        return $this->apply( $rowId, $event );
    }

    /** Runs the handlers for a stored event (also: replay from the console). */
    public function apply( $rowId, array $event )
    {
        $store = $this->payments->store();
        $row = $store->get( self::T, $rowId );
        $s = self::summary( $event );
        $tx = null;
        if ( $s['order_id'] !== '' )
            $tx = $this->payments->transaction( $s['order_id'] );
        if ( !$tx && $s['resource_id'] !== '' )
            $tx = $this->payments->transactionOfPayment( $s['resource_id'] );
        if ( !$tx && isset( $event['resource']['links'] ) )
            foreach ( (array)$event['resource']['links'] as $l )
                if ( isset( $l['rel'] ) && $l['rel'] === 'up' && preg_match( '#/v2/payments/captures/([A-Z0-9]+)$#', (string)$l['href'], $m ) )
                    $tx = $this->payments->transactionOfPayment( $m[1] );
        $results = array();
        $error = false;
        foreach ( $this->handlers as $h )
        {
            try
            {
                $results[] = get_class( $h ) . ':' . $h->handle( $event, $tx, $this->payments );
            }
            catch ( Exception $e )
            {
                $error = true;
                $results[] = get_class( $h ) . ':error';
                sevenxPayPalPayments::log( 'webhook handler ' . get_class( $h ) . ' failed on event ' . $s['event_id'] . ': ' . substr( $e->getMessage(), 0, 160 ) );
            }
        }
        $result = substr( implode( ' ', $results ), 0, 255 );
        $store->update( self::T, $rowId, array( 'processed' => $error ? 0 : time(), 'result' => $result, 'attempts' => (int)( $row ? $row['attempts'] : 0 ) + 1 ) );
        // a handler that failed makes PayPal deliver again (it retries non-2xx answers for three days)
        return array( 'status' => $error ? 500 : 200, 'result' => $result, 'duplicate' => false );
    }
}

/**
 * The built-in handler: the transaction rows follow PayPal (a capture completed or denied, a refund made in PayPal's
 * dashboard, an authorization voided), and a shop order whose capture completed is marked paid the way the buttons
 * do it (when the buyer's browser closed before it came back).
 */
class sevenxPayPalCoreWebhookHandler implements sevenxPayPalWebhookHandler
{
    public function handle( array $event, ?array $tx, sevenxPayPalPayments $payments )
    {
        if ( !$tx )
            return 'unknown';
        $type = (string)$event['event_type'];
        $r = isset( $event['resource'] ) ? (array)$event['resource'] : array();
        $store = $payments->store();
        switch ( $type )
        {
            case 'PAYMENT.CAPTURE.COMPLETED':
                if ( $tx['intent'] === 'AUTHORIZE' && $tx['status'] !== 'COMPLETED' && !isset( $r['custom_id'] ) )
                    $r['custom_id'] = $tx['custom_id'];
                $res = $payments->applyCapture( $tx, $r );
                if ( !$res['ok'] )
                    return 'mismatch';
                if ( $tx['context'] === 'shop' && class_exists( 'sevenxPayPalShop' ) )
                    return sevenxPayPalShop::paidFromWebhook( $payments->store()->get( sevenxPayPalPayments::T, $tx['id'] ), $payments->client()->config() );
                return !empty( $res['already'] ) ? 'already' : 'paid';
            case 'PAYMENT.CAPTURE.PENDING':
                if ( $tx['status'] === 'CREATED' || $tx['status'] === 'APPROVED' )
                    $store->update( sevenxPayPalPayments::T, $tx['id'], array( 'status' => 'PENDING', 'capture_id' => isset( $r['id'] ) ? (string)$r['id'] : '', 'modified' => time() ) );
                return 'pending';
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.DECLINED':
                if ( $tx['status'] !== 'COMPLETED' )
                    $store->update( sevenxPayPalPayments::T, $tx['id'], array( 'status' => 'FAILED', 'failure' => strtolower( substr( $type, 16 ) ), 'modified' => time() ) );
                return 'denied';
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                // the refund object: its amount; the total refunded is the capture's (seller_payable_breakdown.total_refunded_amount)
                $total = isset( $r['seller_payable_breakdown']['total_refunded_amount']['value'] )
                       ? sevenxPayPalMoney::toMinor( $r['seller_payable_breakdown']['total_refunded_amount']['value'], $tx['currency'] ) : null;
                if ( $total === null )
                    $total = (int)$tx['refunded_minor'] + (int)sevenxPayPalMoney::toMinor( isset( $r['amount']['value'] ) ? $r['amount']['value'] : '0', $tx['currency'] );
                if ( $total > (int)$tx['refunded_minor'] )
                    $store->update( sevenxPayPalPayments::T, $tx['id'], array( 'refunded_minor' => $total, 'modified' => time(),
                                                                                'status' => $total >= (int)$tx['amount_minor'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED' ) );
                return $type === 'PAYMENT.CAPTURE.REVERSED' ? 'reversed' : 'refunded';
            case 'PAYMENT.AUTHORIZATION.VOIDED':
                if ( $tx['status'] === 'AUTHORIZED' )
                    $store->update( sevenxPayPalPayments::T, $tx['id'], array( 'status' => 'VOIDED', 'modified' => time() ) );
                return 'voided';
            case 'CHECKOUT.ORDER.APPROVED':
                if ( $tx['status'] === 'CREATED' )
                    $store->update( sevenxPayPalPayments::T, $tx['id'], array( 'status' => 'APPROVED', 'modified' => time() ) );
                return 'approved';
        }
        return 'ignored';
    }
}

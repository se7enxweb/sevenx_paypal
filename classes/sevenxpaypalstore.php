<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package sevenx_paypal
 */

/**
 * Where the extension keeps its two tables (sql/<engine>/schema.sql):
 *
 *   sevenx_paypal_transaction  one row per PayPal order this site created: who it pays (context, reference,
 *                              custom_id), the amount in minor units, the intent, the state, the capture and
 *                              authorization ids, what was refunded. Ids and amounts only, never a payer's data.
 *   sevenx_paypal_event        one row per webhook event received (unique by PayPal's event id): its type, the
 *                              resource id, whether the signature checked out, what the handlers did.
 *   sevenx_paypal_vault        one row per saved payment method (Vault v3): the user, PayPal's payment token id and
 *                              customer id, a masked label; never card data.
 *
 * The database one goes through eZDB with escaped values; the memory one serves the tests.
 */
interface sevenxPayPalStore
{
    /** @return int the new row's id */
    public function insert( $table, array $row );

    public function update( $table, $id, array $fields );

    /** @return array|null */
    public function get( $table, $id );

    /**
     * @param array $where column => value (equality, all of them)
     * @return array[] rows
     */
    public function find( $table, array $where, $order = 'id', $limit = 0 );
}

/** The tables in the site's database. */
class sevenxPayPalDbStore implements sevenxPayPalStore
{
    const TABLES = array( 'sevenx_paypal_transaction', 'sevenx_paypal_event', 'sevenx_paypal_vault' );

    protected static function table( $t )
    {
        if ( !in_array( $t, self::TABLES, true ) )
            throw new InvalidArgumentException( 'unknown table ' . $t );
        return $t;
    }

    protected static function column( $c )
    {
        if ( !preg_match( '/^[a-z_]{1,40}$/D', (string)$c ) )
            throw new InvalidArgumentException( 'bad column name' );
        return $c;
    }

    protected static function value( $db, $v )
    {
        if ( is_int( $v ) || is_bool( $v ) )
            return (string)(int)$v;
        return "'" . $db->escapeString( (string)$v ) . "'";
    }

    protected static function where( $db, array $where )
    {
        $parts = array();
        foreach ( $where as $c => $v )
        {
            if ( is_array( $v ) )
                $parts[] = self::column( $c ) . ( $v ? ' IN (' . implode( ',', array_map( function ( $x ) use ( $db ) { return self::value( $db, $x ); }, $v ) ) . ')' : ' IS NULL AND 1=0' );
            else
                $parts[] = self::column( $c ) . ' = ' . self::value( $db, $v );
        }
        return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
    }

    public function insert( $table, array $row )
    {
        $db = eZDB::instance();
        $cols = array();
        $vals = array();
        foreach ( $row as $c => $v )
        {
            $cols[] = self::column( $c );
            $vals[] = self::value( $db, $v );
        }
        $db->begin();
        $ok = $db->query( 'INSERT INTO ' . self::table( $table ) . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
        $id = (int)$db->lastSerialID( $table, 'id' );
        // a database layer that cannot tell the new id (another sequence naming): the row by its unique key
        $key = $table === 'sevenx_paypal_event' ? 'event_id' : ( $table === 'sevenx_paypal_vault' ? 'payment_token_id' : 'paypal_order_id' );
        if ( $ok !== false && $id <= 0 && isset( $row[$key] ) )
        {
            $found = $db->arrayQuery( 'SELECT id FROM ' . $table . ' WHERE ' . $key . ' = ' . self::value( $db, $row[$key] ) );
            $id = is_array( $found ) && $found ? (int)$found[0]['id'] : 0;
        }
        $db->commit();
        if ( $ok === false || $id <= 0 )
            throw new RuntimeException( 'the row could not be written to ' . $table );
        return $id;
    }

    public function update( $table, $id, array $fields )
    {
        if ( !$fields )
            return;
        $db = eZDB::instance();
        $set = array();
        foreach ( $fields as $c => $v )
            $set[] = self::column( $c ) . ' = ' . self::value( $db, $v );
        $db->query( 'UPDATE ' . self::table( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE id = ' . (int)$id );
    }

    public function get( $table, $id )
    {
        $r = $this->find( $table, array( 'id' => (int)$id ), 'id', 1 );
        return $r ? $r[0] : null;
    }

    public function find( $table, array $where, $order = 'id', $limit = 0 )
    {
        $db = eZDB::instance();
        if ( !preg_match( '/^[a-z_]+( (ASC|DESC))?(, ?[a-z_]+( (ASC|DESC))?)*$/D', (string)$order ) )
            $order = 'id';
        $rows = $db->arrayQuery( 'SELECT * FROM ' . self::table( $table ) . self::where( $db, $where ) . ' ORDER BY ' . $order,
                                 $limit > 0 ? array( 'limit' => (int)$limit, 'offset' => 0 ) : array() );
        return is_array( $rows ) ? $rows : array();
    }

    /** Whether the tables are there (the status page says so instead of failing). */
    public static function installed()
    {
        return !array_diff( self::TABLES, sevenxPayPalSetup::tables() );
    }
}

/** The same in memory (tests). */
class sevenxPayPalMemoryStore implements sevenxPayPalStore
{
    public $rows = array();
    protected $next = 1;
    /** @var array table => unique columns (a duplicate throws, like the database's unique index) */
    public $unique = array( 'sevenx_paypal_transaction' => array( 'paypal_order_id' ), 'sevenx_paypal_event' => array( 'event_id' ),
                            'sevenx_paypal_vault' => array( 'payment_token_id' ) );

    public function insert( $table, array $row )
    {
        foreach ( isset( $this->unique[$table] ) ? $this->unique[$table] : array() as $u )
            if ( isset( $row[$u] ) && $this->find( $table, array( $u => $row[$u] ) ) )
                throw new RuntimeException( 'duplicate ' . $u );
        $id = $this->next++;
        $this->rows[$table][$id] = array( 'id' => $id ) + $row;
        return $id;
    }

    public function update( $table, $id, array $fields )
    {
        if ( isset( $this->rows[$table][$id] ) )
            $this->rows[$table][$id] = $fields + $this->rows[$table][$id];
    }

    public function get( $table, $id )
    {
        return isset( $this->rows[$table][$id] ) ? $this->rows[$table][$id] : null;
    }

    public function find( $table, array $where, $order = 'id', $limit = 0 )
    {
        $out = array();
        foreach ( isset( $this->rows[$table] ) ? $this->rows[$table] : array() as $r )
        {
            $ok = true;
            foreach ( $where as $c => $v )
                if ( is_array( $v ) ? !in_array( (string)( isset( $r[$c] ) ? $r[$c] : '' ), array_map( 'strval', $v ), true ) : (string)( isset( $r[$c] ) ? $r[$c] : '' ) !== (string)$v )
                    $ok = false;
            if ( $ok )
                $out[] = $r;
        }
        if ( stripos( (string)$order, 'desc' ) !== false )
            $out = array_reverse( $out );
        return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
    }
}

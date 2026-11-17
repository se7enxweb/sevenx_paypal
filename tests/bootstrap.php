<?php
/**
 * Bootstrap of the DB-less unit tests: the pure classes and the mock of PayPal's API, no kernel.
 *   php vendor/bin/phpunit -c extension/sevenx_paypal/phpunit.xml.dist
 */
putenv( 'SEVENX_PAYPAL_TESTS=1' );
foreach ( array( 'sevenxpaypalhttp', 'sevenxpaypalconfig', 'sevenxpaypalmoney', 'sevenxpaypalwebhooksignature', 'sevenxpaypalclient', 'sevenxpaypalstore',
                 'sevenxpaypalpayments', 'sevenxpaypalwebhook' ) as $f )
    require_once __DIR__ . '/../classes/' . $f . '.php';
require_once __DIR__ . '/sevenxPayPalMockHttp.php';

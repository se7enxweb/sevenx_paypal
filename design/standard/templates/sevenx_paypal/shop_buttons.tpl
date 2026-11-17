{* The PayPal buttons on the shop's payment step, next to the card form (doc/checkout.md): include it in the payment
   template of the shop's card gateway, e.g. after the Stripe form:
     {if ezini_hasvariable( 'PayPalSettings', 'ShopCheckout', 'paypal.ini' )}{include uri='design:sevenx_paypal/shop_buttons.tpl' order_id=$order_id}{/if}
   Input: $order_id (the shop order of this checkout). *}
{include uri='design:sevenx_paypal/buttons.tpl' context='shop' id='sxpp-shop'
         create='sevenx_paypal/shop/create'|ezurl( 'no', 'full' ) capture='sevenx_paypal/shop/capture'|ezurl( 'no', 'full' ) done='shop/checkout'|ezurl( 'no', 'full' )
         body=concat( '{"orderId":', $order_id|int, '}' )}

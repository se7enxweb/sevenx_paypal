{* The PayPal buttons on the shop's payment step, next to the card form (doc/checkout.md): include it in the payment
   template of the shop's card gateway, e.g. after the Stripe form:
     {if ezini_hasvariable( 'PayPalSettings', 'ShopCheckout', 'paypal.ini' )}{include uri='design:sevenx_paypal/shop_buttons.tpl' order_id=$order_id}{/if}
   Input: $order_id (the shop order of this checkout). *}
{def $sxppS = fetch( 'sevenx_paypal', 'checkout', hash( 'context', 'shop' ) )}
{if and( $sxppS.enabled, $sxppS.vault, fetch( 'user', 'current_user' ).is_logged_in )}
<form id="sxpp-save-form" class="sxpp-save"><label><input type="checkbox" name="SavePayPal" value="1" /> {'Save my PayPal account for later payments'|i18n( 'extension/sevenx_paypal' )}</label></form>
{/if}
{undef $sxppS}
{include uri='design:sevenx_paypal/buttons.tpl' context='shop' id='sxpp-shop' form='sxpp-save-form' fields='SavePayPal:save'
         create='sevenx_paypal/shop/create'|ezurl( 'no', 'full' ) capture='sevenx_paypal/shop/capture'|ezurl( 'no', 'full' ) done='shop/checkout'|ezurl( 'no', 'full' )
         order_id=$order_id}

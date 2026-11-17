{* The payment step of the gateway Sevenx_PayPal (doc/checkout.md "PayPal as the only gateway"). Input: $order_id. *}
<div class="sxpp-step">
<h2>{'Payment'|i18n( 'extension/sevenx_paypal' )}</h2>
<p>{'Pay with PayPal: with your PayPal account, Pay Later, or a debit or credit card, where PayPal offers them.'|i18n( 'extension/sevenx_paypal' )}</p>
{include uri='design:sevenx_paypal/shop_buttons.tpl' order_id=$order_id}
<form method="post" action={'shop/checkout'|ezurl}>
    <input class="button" type="submit" name="CancelButton" value="{'Cancel'|i18n( 'design/standard/workflow' )}" />
</form>
</div>

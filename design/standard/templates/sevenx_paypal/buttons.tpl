{* The PayPal buttons (doc/checkout.md "Templates"), for any page: the PayPal JS SDK renders PayPal, Pay Later and the
   debit or credit card button where PayPal offers them; javascript/sevenx_paypal_buttons.js calls the page's own JSON
   routes. Nothing here carries an amount: the routes price what is paid on the server.
   Input: $create (URL of the POST that answers ok and order_id), $capture (URL of the POST that answers ok, paid,
   message, redirect), $done (where to go after success), optional $intent ('capture' or 'authorize'), $currency,
   $body (a JSON object string sent with both POSTs; it is passed in from PHP, as template strings cannot hold braces),
   or $order_id (the body becomes {"orderId": <id>}), $context ('shop' needs ShopCheckout=enabled), $label (the line
   above the buttons), $id (the element id), $intent 'vault' (save a PayPal account without a purchase: create answers a
   setup token, capture gets vaultSetupToken), $form and $fields (the id of a form whose current values go with both
   POSTs, and which: "FieldName:key,Other:key2"). *}
{def $sxppC = fetch( 'sevenx_paypal', 'checkout', hash( 'intent', cond( is_set( $intent ), $intent, 'capture' ), 'currency', cond( is_set( $currency ), $currency, '' ),
                                                        'context', cond( is_set( $context ), $context, 'other' ) ) )}
{if $sxppC.enabled}
<link rel="stylesheet" href={'stylesheets/sevenx_paypal.css'|ezdesign} />
<div class="sxpp-checkout" id="{cond( is_set( $id ), $id, 'sxpp-checkout' )|wash}" data-create="{$create|wash}" data-capture="{$capture|wash}" data-done="{$done|wash}"
     data-body="{if is_set( $order_id )}&#123;&quot;orderId&quot;:{$order_id|int}&#125;{elseif is_set( $body )}{$body|wash}{/if}" data-intent="{cond( is_set( $intent ), $intent, 'capture' )|wash}"{if is_set( $form )} data-form="{$form|wash}" data-fields="{cond( is_set( $fields ), $fields, '' )|wash}"{/if}>
    <p class="sxpp-label">{cond( is_set( $label ), $label, 'Or pay with PayPal'|i18n( 'extension/sevenx_paypal' ) )|wash}{if $sxppC.sandbox} <span class="sxpp-sandbox">{'Sandbox'|i18n( 'extension/sevenx_paypal' )}</span>{/if}</p>
    <div class="sxpp-buttons" data-sxpp-buttons="1"></div>
    <p class="sxpp-message" role="alert" hidden></p>
</div>
<script src="{$sxppC.sdk_url|wash}" data-namespace="sxppPayPalSdk"{if $sxppC.user_id_token} data-user-id-token="{$sxppC.user_id_token|wash}"{/if} defer></script>
<script src={'javascript/sevenx_paypal_buttons.js'|ezdesign} defer></script>
{/if}
{undef $sxppC}

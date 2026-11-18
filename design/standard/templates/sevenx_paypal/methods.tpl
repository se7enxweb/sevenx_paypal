{* sevenx_paypal/methods (doc/vault.md "The buyer's page"): the signed-in user's saved PayPal methods and the way to
   save one without a purchase. Input: $enabled, $methods (rows: id, method, label, created), $messages, $errors. *}
{def $pp = 'extension/sevenx_paypal'}
<div class="sxpp-methods">
<h1>{'Saved PayPal'|i18n( $pp )}</h1>
{foreach $messages as $m}<p class="sxpp-message" role="status">{$m|wash}</p>{/foreach}
{foreach $errors as $e}<p class="sxpp-message" role="alert">{$e|wash}</p>{/foreach}
{if $enabled|not}
<p>{'Saving PayPal for later payments is not available here.'|i18n( $pp )}</p>
{else}
<p>{'A saved PayPal account pays renewals and later orders without signing in to PayPal again. Remove it here at any time; PayPal shows it in your PayPal account settings as well.'|i18n( $pp )}</p>
{if $methods|count}
<ul class="sxpp-method-list">
{foreach $methods as $m}
<li class="sxpp-method">
    <span class="sxpp-method-label">{$m.label|wash}</span> <span class="sxpp-method-date">{'saved'|i18n( $pp )} {$m.created|l10n( 'shortdate' )}</span>
    <form method="post" action={'sevenx_paypal/methods'|ezurl} class="sxpp-method-remove">
        <input type="hidden" name="MethodID" value="{$m.id|wash}" />
        <button type="submit" name="RemoveMethodButton" class="button">{'Remove'|i18n( $pp )}</button>
    </form>
</li>
{/foreach}
</ul>
{else}
<p>{'No PayPal account is saved.'|i18n( $pp )}</p>
{/if}
{include uri='design:sevenx_paypal/buttons.tpl' id='sxpp-vault' context='vault' intent='vault' create='sevenx_paypal/methods/setup'|ezurl( 'no' )
         capture='sevenx_paypal/methods/finish'|ezurl( 'no' ) done='sevenx_paypal/methods'|ezurl( 'no' ) label='Save a PayPal account'|i18n( $pp )}
{/if}
</div>
{undef $pp}

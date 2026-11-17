{* sevenx_paypal/status in the administration (doc/operations.md "The status page"). Only the administration's shared
   components (.a4-*), no inline style. Input: $state (mode, client_id, secret, webhook_id, names, api, currency,
   problem), $enabled, $shop, $testers, $sites, $accept_sandbox, $hook_url, $check (null or app_id, webhooks,
   webhook_found, webhook_id, webhook_events, missing_events, stored_id_matches), $events, $payments, $messages, $errors.
   Never a credential: the secret store names only. *}
{def $pp = 'extension/sevenx_paypal'}
<div class="context-block a4-page" id="sxpp-status">

<div class="a4-pagehead">
    <div class="a4-pagehead-main">
        <p class="a4-pagehead-pre">{'Payments'|i18n( $pp )}</p>
        <h1 class="context-title a4-pagehead-title">{'PayPal'|i18n( $pp )}</h1>
        <p class="a4-pagehead-sub">{'PayPal Checkout through the Orders v2, Payments v2 and Webhooks APIs.'|i18n( $pp )}</p>
    </div>
    <div class="a4-pagehead-actions">
        <form method="post" action={'sevenx_paypal/status'|ezurl}>
            <button type="submit" class="a4-btn a4-btn-small" name="CheckConnectionButton" id="sxpp-check">{'Check the connection'|i18n( $pp )}</button>
            {if or( $check|not, $check.webhook_found|not )}<button type="submit" class="a4-btn a4-btn-small a4-btn-primary" name="CreateWebhookButton" id="sxpp-create-hook">{'Create the webhook'|i18n( $pp )}</button>{/if}
        </form>
    </div>
</div>

{foreach $messages as $m}<p class="a4-notice is-ok" role="status">{$m|wash}</p>{/foreach}
{foreach $errors as $e}<p class="a4-notice is-bad" role="alert">{$e|wash}</p>{/foreach}

<section class="a4-card" aria-labelledby="sxpp-setup-h" id="sxpp-setup">
    <div class="a4-card-head"><h2 class="a4-card-title a4-h3" id="sxpp-setup-h">{'Settings in use'|i18n( $pp )}</h2>
        {if $state.mode|eq( 'live' )}<span class="a4-badge is-ok" data-state="mode">{'live'|i18n( $pp )}</span>{else}<span class="a4-badge is-warn" data-state="mode">{'sandbox'|i18n( $pp )}</span>{/if}</div>
    <dl class="a4-facts is-rows">
        <div><dt>{'Offered to'|i18n( $pp )}</dt><dd>{switch match=$enabled}{case match='enabled'}{'every buyer'|i18n( $pp )}{/case}{case match='testers'}{'the test users only'|i18n( $pp )} ({$testers}){/case}{case}{'nobody (disabled)'|i18n( $pp )}{/case}{/switch}{if $sites|count}, {'on'|i18n( $pp )} {$sites|implode( ', ' )|wash}{/if}</dd></div>
        <div><dt>{'Shop checkout'|i18n( $pp )}</dt><dd>{$shop|wash}</dd></div>
        <div><dt>{'Sandbox payments complete orders'|i18n( $pp )}</dt><dd>{if $state.mode|eq( 'live' )}{'not applicable (live)'|i18n( $pp )}{elseif $accept_sandbox}{'yes'|i18n( $pp )}{else}{'no'|i18n( $pp )}{/if}</dd></div>
        <div><dt>{'Currency'|i18n( $pp )}</dt><dd>{$state.currency|wash}</dd></div>
        <div><dt>{'API'|i18n( $pp )}</dt><dd><code>{$state.api|wash}</code></dd></div>
        <div><dt>{'Client id'|i18n( $pp )}</dt><dd>{if $state.client_id}{'present'|i18n( $pp )}{else}{'missing'|i18n( $pp )}{/if} <span class="a4-muted">(<code>{$state.names.client_id|wash}</code>)</span></dd></div>
        <div><dt>{'Secret'|i18n( $pp )}</dt><dd>{if $state.secret}{'present'|i18n( $pp )}{else}{'missing'|i18n( $pp )}{/if} <span class="a4-muted">(<code>{$state.names.secret|wash}</code>)</span></dd></div>
        {if $state.problem}<div><dt>{'Problem'|i18n( $pp )}</dt><dd>{$state.problem|wash}</dd></div>{/if}
    </dl>
</section>

<section class="a4-card" aria-labelledby="sxpp-hook-h" id="sxpp-webhook">
    <div class="a4-card-head"><h2 class="a4-card-title a4-h3" id="sxpp-hook-h">{'Webhook'|i18n( $pp )}</h2>
        {if $state.webhook_id}<span class="a4-badge is-ok" data-state="webhook">{'configured'|i18n( $pp )}</span>{else}<span class="a4-badge is-bad" data-state="webhook">{'not configured'|i18n( $pp )}</span>{/if}</div>
    <dl class="a4-facts is-rows">
        <div><dt>{'URL'|i18n( $pp )}</dt><dd><code>{$hook_url|wash}</code></dd></div>
        <div><dt>{'Webhook id'|i18n( $pp )}</dt><dd>{if $state.webhook_id}<code>{$state.webhook_id|wash}</code>{else}<span class="a4-muted">&ndash;</span>{/if}</dd></div>
        {if $check}
        <div><dt>{'PayPal app'|i18n( $pp )}</dt><dd><code>{$check.app_id|wash}</code>, {$check.webhooks} {'webhooks in the app'|i18n( $pp )}</dd></div>
        <div><dt>{'This site in the app'|i18n( $pp )}</dt><dd>{if $check.webhook_found}{'found'|i18n( $pp )}, {$check.webhook_events} {'events'|i18n( $pp )}{if $check.missing_events|count}; {'missing'|i18n( $pp )}: {$check.missing_events|implode( ', ' )|wash}{/if}{if $check.stored_id_matches|not}; {'the stored id differs'|i18n( $pp )}{/if}{else}{'not found'|i18n( $pp )}{/if}</dd></div>
        {/if}
    </dl>
</section>

<section class="a4-card" aria-labelledby="sxpp-events-h" id="sxpp-events">
    <div class="a4-card-head"><h2 class="a4-card-title a4-h3" id="sxpp-events-h">{'Last events'|i18n( $pp )}</h2></div>
    {if $events|count}
    <div class="a4-table-wrap"><table class="a4-table is-compact">
        <thead><tr><th scope="col">{'Received'|i18n( $pp )}</th><th scope="col">{'Event'|i18n( $pp )}</th><th scope="col">{'Resource'|i18n( $pp )}</th><th scope="col">{'Summary'|i18n( $pp )}</th><th scope="col">{'Result'|i18n( $pp )}</th></tr></thead>
        <tbody>{foreach $events as $ev}<tr><td class="a4-nowrap">{$ev.received|l10n( 'shortdatetime' )}</td><td><code>{$ev.event_type|wash}</code> <span class="a4-muted">{$ev.mode|wash}</span></td><td><code>{$ev.resource_id|wash}</code></td><td>{$ev.summary|wash}</td><td>{if $ev.processed|gt( 0 )}{$ev.result|wash}{else}<span class="a4-badge is-warn">{'not applied'|i18n( $pp )}</span> {$ev.result|wash}{/if}</td></tr>{/foreach}</tbody>
    </table></div>
    {else}
    <p class="a4-empty">{'No event was received yet.'|i18n( $pp )}</p>
    {/if}
</section>

<section class="a4-card" aria-labelledby="sxpp-pay-h" id="sxpp-payments">
    <div class="a4-card-head"><h2 class="a4-card-title a4-h3" id="sxpp-pay-h">{'Last payments'|i18n( $pp )}</h2></div>
    {if $payments|count}
    <div class="a4-table-wrap"><table class="a4-table is-compact">
        <thead><tr><th scope="col">{'Created'|i18n( $pp )}</th><th scope="col">{'PayPal order'|i18n( $pp )}</th><th scope="col">{'Pays'|i18n( $pp )}</th><th scope="col">{'Amount'|i18n( $pp )}</th><th scope="col">{'State'|i18n( $pp )}</th><th scope="col">{'Capture or hold'|i18n( $pp )}</th></tr></thead>
        <tbody>{foreach $payments as $t}<tr><td class="a4-nowrap">{$t.created|l10n( 'shortdatetime' )}</td><td><code>{$t.paypal_order_id|wash}</code> <span class="a4-muted">{$t.mode|wash}</span></td><td>{$t.context|wash}: <code>{$t.reference|wash}</code></td><td class="a4-nowrap">{$t.amount|wash}{if $t.refunded} <span class="a4-muted">({'refunded'|i18n( $pp )} {$t.refunded|wash})</span>{/if}</td><td>{$t.status|wash}{if $t.failure} <span class="a4-muted">{$t.failure|wash}</span>{/if}</td><td><code>{if $t.capture_id}{$t.capture_id|wash}{else}{$t.authorization_id|wash}{/if}</code></td></tr>{/foreach}</tbody>
    </table></div>
    {else}
    <p class="a4-empty">{'No PayPal payment yet.'|i18n( $pp )}</p>
    {/if}
</section>

</div>
{undef $pp}

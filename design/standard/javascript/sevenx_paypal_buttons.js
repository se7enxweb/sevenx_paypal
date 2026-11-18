/*
 * sevenx_paypal: the PayPal buttons (doc/checkout.md). For every .sxpp-checkout element of the page: the PayPal JS SDK
 * (loaded by the template under the namespace sxppPayPalSdk) renders the buttons; createOrder and onApprove POST to the
 * element's own routes (data-create, data-capture) with data-body; the server prices the payment, creates the PayPal
 * order, and captures or authorizes it after the buyer approved. A signed-in session sends the form token of
 * ezformtoken (X-CSRF-Token). data-form/data-fields add the current values of a form's fields (the buyer's choices;
 * the server prices them again). On success the page goes to data-done (or the redirect the server answered).
 * Copyright (C) 1998 - 2026 Exponential Foundation and 7x. GNU General Public License v2.0 (or any later version).
 */
(function () {
    "use strict";

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute("content")) return meta.getAttribute("content");
        var field = document.querySelector('input[name="ezxform_token"]');
        return field ? field.value : "";
    }

    function post(url, body) {
        var headers = { "Content-Type": "application/json", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" };
        var token = csrfToken();
        if (token) headers["X-CSRF-Token"] = token;
        return fetch(url, { method: "POST", credentials: "same-origin", headers: headers, body: JSON.stringify(body) })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false, message: "The server did not answer. Nothing was charged; please try again." }; });
            });
    }

    function show(box, text) {
        var p = box.querySelector(".sxpp-message");
        if (!p) return;
        p.textContent = text || "";
        p.hidden = !text;
    }

    function setup(box) {
        var sdk = window.sxppPayPalSdk;
        var target = box.querySelector("[data-sxpp-buttons]");
        if (!sdk || !sdk.Buttons || !target || box.getAttribute("data-sxpp-ready")) return;
        box.setAttribute("data-sxpp-ready", "1");
        var extra = {};
        try { extra = JSON.parse(box.getAttribute("data-body") || "{}") || {}; } catch (e) { extra = {}; }
        var formValues = function () {
            var out = {}, form = document.getElementById(box.getAttribute("data-form") || ""), map = (box.getAttribute("data-fields") || "").split(",");
            if (!form) return out;
            for (var i = 0; i < map.length; i++) {
                var pair = map[i].split(":"), el = form.elements[pair[0]];
                if (!el || pair.length !== 2) continue;
                out[pair[1]] = el.type === "checkbox" ? (el.checked ? 1 : 0) : el.value;
            }
            return out;
        };
        var with_ = function (fields) {
            var out = {}, k, f = formValues();
            for (k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) out[k] = extra[k];
            for (k in f) if (Object.prototype.hasOwnProperty.call(f, k)) out[k] = f[k];
            for (k in fields) if (Object.prototype.hasOwnProperty.call(fields, k)) out[k] = fields[k];
            return out;
        };
        var vault = box.getAttribute("data-intent") === "vault";
        var options = {
            style: { layout: "vertical", shape: "rect", label: "paypal" },
            createOrder: function () {
                show(box, "");
                return post(box.getAttribute("data-create"), with_({})).then(function (r) {
                    if (!r || !r.ok || !r.order_id) throw new Error((r && r.message) || "PayPal is not available right now.");
                    return r.order_id;
                });
            },
            onApprove: function (data, actions) {
                var fields = vault ? { vaultSetupToken: data.vaultSetupToken } : { paypalOrderId: data.orderID };
                return post(box.getAttribute("data-capture"), with_(fields)).then(function (r) {
                    if (r && r.ok) {
                        show(box, r.message || "");
                        window.location.assign(r.redirect || box.getAttribute("data-done") || window.location.href);
                        return;
                    }
                    if (r && r.restart && actions && actions.restart) return actions.restart();
                    show(box, (r && r.message) || "The payment did not go through. Nothing was charged.");
                });
            },
            onCancel: function () {
                show(box, "The PayPal payment was cancelled. Nothing was charged.");
            },
            onError: function (err) {
                show(box, (err && err.message) || "PayPal is not available right now. Nothing was charged.");
            }
        };
        // saving a PayPal account without a purchase (Vault v3): the server's create answers a setup token
        if (vault) {
            options.createVaultSetupToken = options.createOrder;
            delete options.createOrder;
        }
        sdk.Buttons(options).render(target).catch(function () {
            show(box, "PayPal could not be shown. Please pay another way.");
        });
    }

    function start() {
        var boxes = document.querySelectorAll(".sxpp-checkout");
        for (var i = 0; i < boxes.length; i++) setup(boxes[i]);
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
    else start();
})();

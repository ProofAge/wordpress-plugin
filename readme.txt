=== ProofAge Age Verification ===
Contributors: proofage
Tags: age verification, age gate, woocommerce, checkout, ecommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds ProofAge-powered age verification to WordPress and WooCommerce storefronts.

== Description ==

ProofAge Age Verification integrates the hosted ProofAge verification flow with WordPress and WooCommerce.

With this plugin you can:

* protect the full site or selected WordPress pages and post categories
* protect WooCommerce products and product categories
* block add-to-cart, cart, checkout, and Store API flows until verification is complete
* show a full-page gate or a blocking overlay, depending on the protected content
* launch the hosted ProofAge flow in an iframe modal, the current window, or a new tab
* store a verification snapshot on WooCommerce orders and show it in the admin order details panel
* localize gate copy and pass the supported storefront language to ProofAge when creating a verification

The plugin uses the hosted ProofAge flow and webhooks together with browser-side return reconciliation and status polling.

Supported browser flows:

* open the hosted verification in an iframe modal on the current page
* redirect in the current window
* open the hosted verification in a new tab

This version intentionally does not support:

* a custom in-page ProofAge capture UI
* theme-specific quick-view integrations beyond the generic add-to-cart interception hooks

== External services ==

This plugin connects to two ProofAge services. A ProofAge account is required. ProofAge Privacy Policy: https://proofage.net/privacy. ProofAge Terms of Service: https://proofage.net/terms.

= ProofAge connect service (app.proofage.net) =

Used only while you connect the site to a ProofAge workspace, when you click Connect ProofAge on the settings page. The plugin sends the plugin's public client ID, a one-time device code and, once you have approved the connection in ProofAge, a one-time access token. In return it receives the workspace's public key and a secret key made for this site, which it stores in the WordPress database and uses to sign its API requests.

= ProofAge API (api.proofage.net) =

Used to create verifications, fetch verification status and set up the webhook subscription through which ProofAge sends results to this site. When a shopper starts verification, the plugin sends limited verification request data to ProofAge, such as an external identifier, callback or return URL, supported storefront language, and verification-related metadata. To set up the subscription the plugin sends this site's webhook address. Each request identifies the plugin through an X-ProofAge-Sdk header and a User-Agent carrying the plugin, WordPress, PHP, and (when active) WooCommerce versions.

= Data stored locally =

The plugin stores the connection (keys and workspace name) and limited verification state in WordPress and WooCommerce, including verification status, verification ID, external ID, return URL, timestamps, session token, and optional order verification metadata. Uninstalling the plugin removes the connection and deletes this site's webhook subscription in ProofAge.

== Installation ==

1. Upload the proofage-age-verification folder to the /wp-content/plugins/ directory, or install the plugin through WordPress.
2. Activate ProofAge Age Verification in the WordPress admin.
3. Go to Settings -> ProofAge Verification.
4. Click Connect ProofAge. The page shows a code: open the ProofAge link, sign in, enter the code, pick a workspace and click Allow.
5. Configure launch mode, gate texts, verification TTL, and protection scope.

Nothing is copied between ProofAge and WordPress: the plugin receives a key made for this site and subscribes its own webhook address, so ProofAge sends results to your site. Until the site is connected, protected pages stay locked.

The plugin automatically appends an origin query parameter so shoppers can be returned to the page where they started verification.

== Frequently Asked Questions ==

= Does this plugin support WooCommerce? =

Yes. It supports WooCommerce product protection, category protection, single-product gating, loop add-to-cart interception, cart validation, checkout validation, and Store API add-to-cart validation for block-based flows.

= Can I protect only specific products or categories? =

Yes. You can combine site-wide rules with product, product-category, page, and WordPress post-category targeting. Exclusions are also supported.

= Can I translate the gate texts? =

Yes. The Gate texts can be translated through Polylang or WPML String Translation. The plugin also passes the current storefront language to ProofAge when it matches a supported ProofAge SDK language.

= Does this plugin use an external service? =

Yes. This plugin connects to ProofAge to create verifications, fetch status updates, and process webhook callbacks. A ProofAge account is required; you connect it with the Connect ProofAge button. See the External services section for what is sent where.

= What data does the plugin send to ProofAge and store locally? =

When a shopper starts verification, the plugin sends limited verification request data to ProofAge, such as an external identifier, callback or return URL, supported storefront language, and verification-related metadata. The plugin stores limited verification state locally in WordPress and WooCommerce, including verification status, verification ID, external ID, return URL, timestamps, session token, and optional order verification metadata.

= Where can I review the ProofAge legal documents? =

The ProofAge Privacy Policy is available at https://proofage.net/privacy and the Terms of Service are available at https://proofage.net/terms.

= Does the plugin store verification data on orders? =

Yes. For WooCommerce orders the plugin stores a ProofAge verification snapshot in order meta and displays the verification result in the admin order details view.

= Does this plugin embed ProofAge in an iframe or modal? =

Yes. Hosted verification can open in an iframe modal, in the current window, or in a new tab, depending on the selected launch mode.

== Changelog ==

= 0.4.0 =

* Every request to ProofAge now carries a timestamp that is part of its signature, so a captured request cannot be replayed later.
* Creating a verification or the webhook subscription now carries an idempotency key, so a repeated request cannot create a duplicate.

= 0.3.0 =

* Connect ProofAge: a button on the settings page connects the site to your ProofAge workspace. The API key and secret key fields are gone, and nothing is copied between ProofAge and WordPress.
* The plugin subscribes its own webhook address in ProofAge, so you no longer paste the webhook URL into the workspace settings.
* The settings page shows which workspace is connected and whether it is a test or live workspace.
* If the key is deleted in ProofAge, the settings page says so and protected pages stay locked until you connect again.
* The webhook endpoint answers 410 to a delivery it cannot verify, so ProofAge removes subscriptions this site no longer holds a key for.
* Uninstalling the plugin removes its webhook subscription in ProofAge.

= 0.2.2 =

* Links to the ProofAge site, Privacy Policy and Terms of Service point at proofage.net.

= 0.2.1 =

* The plugin calls the ProofAge API at its new address, api.proofage.net. The previous address keeps working, so nothing needs to change on your site.

= 0.2.0 =

* Each ProofAge API request now identifies the plugin through an X-ProofAge-Sdk header and a User-Agent with the plugin, WordPress, PHP and (when active) WooCommerce versions.
* A data.updated webhook (a correction of document fields, not a decision) is acknowledged without changing the verification session.

= 0.1.0 =

* Initial release.
* Added WordPress and WooCommerce protection rules.
* Added hosted verification launch flows for iframe modal, redirect, and new tab.
* Added WooCommerce order verification snapshot in admin order details.
* Added multilingual gate text support through Polylang and WPML String Translation.
* Added storefront language passthrough for supported ProofAge SDK languages.

== Upgrade Notice ==

= 0.4.0 =

Requests to ProofAge are now signed with a timestamp and creating a verification is idempotent. No action is needed; the site stays connected.

= 0.3.0 =

The API key and secret key fields are replaced by a Connect ProofAge button. After updating, open Settings -> ProofAge Verification and click Connect ProofAge. Until then protected pages stay locked.

= 0.2.2 =

Links point at proofage.net. No settings change.

= 0.2.1 =

Uses ProofAge's new API address. No settings change.

= 0.2.0 =

Handles ProofAge's new data.updated webhook correctly and identifies the plugin to the ProofAge API.

= 0.1.0 =

 Initial release.

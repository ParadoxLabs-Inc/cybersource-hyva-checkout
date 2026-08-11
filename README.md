[![Latest Stable Version](https://poser.pugx.org/paradoxlabs/cybersource-hyva-checkout/v/stable)](https://packagist.org/packages/paradoxlabs/cybersource-hyva-checkout)
[![License](https://poser.pugx.org/paradoxlabs/cybersource-hyva-checkout/license)](https://packagist.org/packages/paradoxlabs/cybersource-hyva-checkout)
[![Total Downloads](https://poser.pugx.org/paradoxlabs/cybersource-hyva-checkout/downloads)](https://packagist.org/packages/paradoxlabs/cybersource-hyva-checkout)

<p align="center">
    <a href="https://www.paradoxlabs.com"><img alt="ParadoxLabs" src="https://paradoxlabs.com/wp-content/uploads/2020/02/pl-logo-canva-2.png" width="250"></a>
</p>

This module adds support for Hyva Checkout to
our [CyberSource payment method for Magento 2](https://github.com/ParadoxLabs-Inc/cybersource).

Requirements
============

* Adobe Commerce / Magento Open Source 2.4.6 – 2.4.9 (or equivalent version of Adobe Commerce Cloud), or Mage-OS 2.0 – 3.0
* PHP 8.1, 8.2, 8.3, 8.4, or 8.5
* Hyva Checkout (separate product and license), `hyva-themes/magento2-hyva-checkout` >= 1.3
* `hyva-themes/magento2-theme-module` >= 1.3.11
* `hyva-themes/magento2-payment-icons` >= 2.0
* `paradoxlabs/cybersource` ^4.0
* `paradoxlabs/tokenbase-hyva-checkout` ^1.0

Features
========

* Place orders via Hyva Checkout, with CyberSource payment
* Embedded CyberSource Unified Checkout drop-in card form, running entirely on the CyberSource REST API
* Handles native CyberSource Payer Authentication (3D Secure 2) inline during place order, without leaving the
  checkout page — device data collection, the Cardinal-hosted step-up challenge, and the issuer ACS fallback all
  run in hidden frames on the checkout
* Supports stored cards (vault) via ParadoxLabs_TokenBase
* Customer-account payment options (paymentinfo): list, add, edit, and delete stored cards on Hyva themes, via the
  shared ParadoxLabs_TokenBaseHyvaCheckout module
* Strict CSP and Alpine CSP compliant, for Hyva Checkout 1.3+ nonce-based CSP

All gateway behavior — Unified Checkout, Payer Authentication, Decision Manager, fraud settings, and admin
configuration — is provided by the base `paradoxlabs/cybersource` module. This module supplies only the Hyva
Checkout frontend; configure the payment method in the CyberSource section under
**Stores → Configuration → Sales → Payment Methods** as documented in the base module.

Installation and Usage
======================

In SSH at your Magento base directory, run:

    composer require paradoxlabs/cybersource-hyva-checkout
    php bin/magento module:enable ParadoxLabs_CyberSourceHyvaCheckout
    php bin/magento setup:upgrade

## Applying Updates

In SSH at your Magento base directory, run:

    composer update paradoxlabs/cybersource-hyva-checkout
    php bin/magento setup:upgrade

These commands will download and apply any available updates to the module.

If you have any integrations or custom functionality based on this extension, we strongly recommend testing to ensure
they are not affected.

Content Security Policy
=======================

There is nothing to configure here. All CyberSource hosts (Unified Checkout, Decision Manager device
fingerprinting) and the Payer Authentication grants are declared in the base module's frontend-global
`csp_whitelist.xml`, so they apply on the Hyva Checkout page as well. The two grants that specifically enable the
3-D Secure flow:

* The device data collection form is POSTed from the checkout page into a hidden iframe targeting
  `*.cardinalcommerce.com`. `form-action` governs the submitting document, so the base module whitelists that host
  for `form-action`; without it, an enforced checkout policy silently blocks the POST and device data collection
  times out.
* The step-up challenge runs inside the base module's own `payerauth/challenge` wrapper page, whose per-route
  policy is relaxed by `Controller/Payerauth/Challenge::modifyCsp()`. Production ACS challenge URLs are
  issuer-controlled and cannot be host-pinned.

Error Handling and 3-D Secure Recovery
======================================

Place-order failures are handled inside `Magewire\Payment\PlaceOrderService` rather than being
rethrown. Hyva's default `AbstractPlaceOrderService::handleException()` rethrows the exception, which
aborts the Magewire request before the `order:place:paradoxlabs_cybersource:error` browser event the
processor queued can reach the response. That event is what drives this module's inline re-verify recovery
(`scripts.phtml`: `handleOrderError()`) and the single-use transient-token scrub — so with the default rethrow,
neither runs, and in production the customer only sees a generic "page refresh" dialog.

This service therefore overrides three methods:

* `handleException()` buffers the exception instead of rethrowing, letting the request complete and
  deliver the queued event.
* `evaluateCompletion()` reports the failure through the standard messenger. A `LocalizedException`
  message (the decline or verification reason) is shown verbatim; anything else is masked to a generic message so
  raw internals never leak.
* `canRedirect()` returns `false` while a failure is buffered, suppressing the success-page redirect
  the processor would otherwise push once the exception stops propagating.

**Re-verify decision:** the checkout client fires one automatic re-verification when — and only when — the
server's refusal wording carries the base module's re-verify marker (`BindingValidator::REVERIFY_MARKER`,
matched by substring in `scripts.phtml`). Recoverable blocks (an abandoned challenge, or expired/amount/currency
drift on a liability-shifted record) keep the marker and are retried once. Obligation-class blocks — a card
already failed or blocked by a prior authentication — deliberately drop the marker and use terminal wording, so
the client falls through to its terminal path and resets the form for another card instead of buying a second
doomed 3-D Secure round-trip. A transient token is single-use and its capture context is spent once submitted,
so a declined new-card attempt is never resubmitted.

Known Limitations (TODO)
========================

Pre-release caveats — verify before relying on these in production:

* **TODO: Live install verification.** This module has not yet been exercised against a running Hyva Checkout
  install. Two assumptions need confirmation there: Magewire `$wire` action return values resolving through the
  call promise (standard Livewire v2 semantics), and `dispatchBrowserEvent` delivery (the template defensively
  listens on `window`).
* **TODO: Browser QA of the Payer Authentication matrix** (frictionless, device-data-collection degrade,
  step-up challenge, challenge-abandoned, and decline-after-challenge).
* **TODO: $0-total checkout.** A $0 quote depends on the base module minting a capture context for the drop-in;
  confirm the drop-in renders and the $0 card-storage authorization succeeds on Hyva.
* `Require Payer Authentication` (base module, default off) refuses orders placed without *attempting* Payer
  Authentication. Confirm the Hyva place flow satisfies it — the client runs authentication before placement.

Changelog
=========

Please see [CHANGELOG.md](https://github.com/ParadoxLabs-Inc/cybersource-hyva-checkout/blob/master/CHANGELOG.md).

Support
=======

This module is provided free and without support of any kind. You may report issues you've found in the module, and we
will address them as we are able, but **no support will be provided here.**

**DO NOT include any API keys, credentials, or customer-identifying in issues, pull requests, or comments. Any
personally identifying information will be deleted on sight.**

If you need personal support services,
please [buy an extension support plan from ParadoxLabs](https://store.paradoxlabs.com/support-renewal.html), then open a
ticket at [support.paradoxlabs.com](https://support.paradoxlabs.com).

Contributing
============

Please feel free to submit pull requests with any contributions. We welcome and appreciate your support, and will
acknowledge contributors.

This module is maintained by ParadoxLabs, a Magento solutions provider. We make no guarantee of accepting contributions,
especially any that introduce architectural changes.

License
=======

This module is licensed
under [APACHE LICENSE, VERSION 2.0](https://github.com/ParadoxLabs-Inc/cybersource/blob/master/LICENSE).

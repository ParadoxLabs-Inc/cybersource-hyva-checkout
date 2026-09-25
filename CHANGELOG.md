# ParadoxLabs_CyberSourceHyvaCheckout Changelog

## 1.0.1 - Unreleased

- Fixed capture-context total drift detection never firing on Hyvä Checkout. Magewire does not return component
  method results to the browser, so the mount and submit totals always read as null; the total is now delivered via
  a browser event, and a new card entered before a total change (coupon, shipping) is re-collected as intended.
- Fixed the stored-card select showing "Add new card" while a stored card was preselected, which hid the Unified
  Checkout drop-in and showed the stored-card CVV field instead.
- Fixed "The payment form could not be loaded" appearing (after two silent drop-in reloads) whenever Unified Checkout
  offers more than one payment type, such as Google Pay: the mount check only looked at the card-entry container,
  which stays empty until a payment button is chosen. Affected checkout and My Payment Options.

## 1.0.0 - Aug 11, 2026

Initial release: CyberSource Unified Checkout support for Hyvä Checkout, built on `paradoxlabs/cybersource` 4.0.

- Unified Checkout drop-in card form on Hyvä Checkout, running on the CyberSource REST API.
- Native Payer Authentication (3D Secure 2) inline during place order: device data collection, the Cardinal-hosted
  step-up challenge, and the issuer ACS fallback all run in hidden frames without leaving the checkout page.
- Stored cards (vault) via ParadoxLabs_TokenBase, and customer-account payment options (list, add, edit, delete)
  on Hyvä themes via the shared ParadoxLabs_TokenBaseHyvaCheckout module.
- Place-order failures deliver the decline/verification message and drive one-shot re-verify inline, via a
  `PlaceOrderService` that buffers the exception instead of rethrowing (so the queued
  `order:place:paradoxlabs_cybersource:error` event reaches the client).
- Strict CSP and Alpine CSP compliant for Hyvä Checkout 1.3+ nonce-based CSP; all CyberSource and Payer
  Authentication CSP grants are inherited from the base module's frontend-global whitelist.

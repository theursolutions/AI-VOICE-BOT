# Payment method logos

Drop the official files here and they appear on the checkout page automatically
— `billing/_payment-methods.blade.php` looks each one up by filename and falls
back to a branded chip when it is missing. No code change, no deploy.

Expected names (`.svg` preferred, `.png` and `.webp` also accepted):

    visa.svg        mastercard.svg      bank.svg
    jazzcash.svg    easypaisa.svg
    paypal.svg      applepay.svg        googlepay.svg

Rendered at 18px tall, so a wide horizontal lockup works better than a square
one.

## Why these are not committed

They are their owners' trademarks, each with its own brand guidelines about
colour, clear space and minimum size. Redrawing one from memory produces
something that is not the logo, and a payment page carrying an almost-right
logo reads as a phishing page — which is the opposite of what putting it there
was meant to achieve.

Get them from the source:

  - JazzCash / Easypaisa — your merchant account manager, or the brand pack in
    the merchant portal. Both provide approved assets to integrating merchants.
  - Visa / Mastercard — the acceptance marks in their brand centres, free to use
    for merchants who accept the cards.
  - PayPal, Apple Pay, Google Pay — each publishes a marks page with the rules.

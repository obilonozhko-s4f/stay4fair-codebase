# stay4fair / BS Business Travelling – Codebase

Custom code for [stay4fair.com] – MU-plugins, styles and logic for BS Business Travelling.

## Structure

- `mu-plugins/` – main project logic:
  - custom checkout summary
  - search form styling
  - calendar localization
  - minimum stay rules
  - room permalink, loop queries, etc.
- `theme/bsbt-child/` – child theme overrides (optional).
- `docs/` – architecture and technical documentation.

## Tech stack

- WordPress
- MotoPress Hotel Booking
- WooCommerce
- Elementor / Elementor Pro
- Custom MU-plugins (`bsbt-*`)

## How we work

1. All custom code lives here (no WordPress core or third-party plugins).
2. Changes are made here → deployed to the server.
3. ChatGPT helps with:
   - refactoring and optimization
   - fixing bugs
   - extending booking / voucher / search logic

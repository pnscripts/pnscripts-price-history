# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-16

### Added

- **Prices from your own records**: for products that were already on sale when recording started, enter the prices that applied before (product edit screen, CSV upload under Import and tools, or `wp pnscripts-price-history add-record` / `import-records`). They are stored like imported history: only before the plugin's own first record of a product, never over it, and they can be removed again from the product screen. A product whose discount started before the install can show a provable notice on day one instead of after 30 days.
- Tools tab shows the size of the history table and the retention period.

### Changed

- WooCommerce tested up to 11.2. Bulgarian, German and Polish translations updated.
- GitHub repository renamed to `pnscripts/pnscripts-price-history` (was `pnscripts/woocommerce-omnibus`; GitHub redirects the old address) and the Composer package to `pnscripts/pnscripts-price-history` (it was never on Packagist).

## [1.0.3] - 2026-10-10

### Changed

- Renamed to "PN Scripts Price History for Discounts" (was "PN Scripts Pricetrail – 30-Day Lowest Price for Sales"): the WordPress.org review (2026-10-07) found "PriceTrail" already used by unrelated price-monitoring and price-history services. Slug, folder, main file (`pnscripts-price-history.php`) and text domain are now `pnscripts-price-history`; bundled translations renamed and recompiled.
- Admin page is WooCommerce → Price History (`admin.php?page=pnscripts-price-history`); WP-CLI command is `wp pnscripts-price-history`.
- Shortcode is `[pnscripts_price_history]`; the 1.0.0 tag `[pnscripts_omnibus_price]` keeps working. The 1.0.2 tag `[pnscripts_pricetrail_price]` is dropped (1.0.2 was never published).
- Code prefixes, hooks, option and meta names, CSS classes and the `{prefix}pnscripts_omnibus_price_history` table are unchanged, so settings and price history are kept.
- Plugin URI (and the translations' bug-report address) is now https://pnscripts.com/marketplace/pnscripts-price-history, the product page under the new name.
- Screenshots retaken on a neutral demo shop with the current admin screens, so none shows an old name.

## [1.0.2] - 2026-10-06

### Changed

- Renamed to "PN Scripts Pricetrail – 30-Day Lowest Price for Sales" (was "PN Omnibus – Lowest Price in 30 Days"): the WordPress.org review found the old name too close to an existing plugin. Slug, folder, main file (`pnscripts-pricetrail.php`) and text domain are now `pnscripts-pricetrail`; bundled translations renamed and recompiled.
- Admin page is WooCommerce → Pricetrail (`admin.php?page=pnscripts-pricetrail`); WP-CLI command is `wp pnscripts-pricetrail`.
- Shortcode is `[pnscripts_pricetrail_price]`; `[pnscripts_omnibus_price]` keeps working.
- Code prefixes, hooks, option names, CSS classes and the `{prefix}pnscripts_omnibus_price_history` table are unchanged, so settings and price history are kept. A site running 1.0.x from GitHub must activate the plugin again after replacing the folder, because the main file name changed.

## [1.0.1] - 2026-10-05

### Changed

- Plugin name is now "PN Omnibus – Lowest Price in 30 Days" (was "Omnibus Lowest Price 30 Days for WooCommerce – PN Omnibus"): WordPress.org does not allow the term "WooCommerce" in a plugin's name or permalink. Slug, text domain, folder and code are unchanged; the bundled translations of the name are updated.

### Security

- Every PHP file under `src/` exits when it is requested directly (WordPress.org plugin review: direct file access).

## [1.0.0] - 2026-10-04


### Added

- Price history capture for products and variations (regular price, sale price, sale schedule, stored price) on every product save and on direct price meta writes, in a custom table.
- Lowest prior price calculation: anchor at the start of the current reduction, progressive reductions, calendar-day period in the shop time zone (at least 30 days), earlier promotions counted, "unknown" for gaps and incomplete history.
- "Unknown" also after a shop currency change or a tax configuration change inside the period, while an inactive period is not yet checked, and when a reduction starts right after an imported price without an on-sale flag; prices written outside all hooks are marked as a gap at the next save.
- New-product rule (hide or period since launch) and optional perishable-goods exemption per product and category.
- Notice under the price in classic and block themes, for variations and in product lists; shortcode `[pnscripts_omnibus_price]`; customisable text with `{price}`, `{days}`, `{date}`.
- Settings page with rule presets, coverage report, import and tools; price history metabox on the product screen.
- Background baseline, repair, resume-after-inactivity and retention jobs (Action Scheduler).
- Read-only imports from Omnibus (iWorks), WC Price History and Omnibus by iLabs.
- WP-CLI commands `reference`, `history`, `backfill`, `import`, `prune`.
- Translations: Bulgarian, Polish, German.
- HPOS and Cart/Checkout blocks compatibility declarations.

[Unreleased]: https://github.com/pnscripts/pnscripts-price-history/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/pnscripts/pnscripts-price-history/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/pnscripts/pnscripts-price-history/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/pnscripts/pnscripts-price-history/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/pnscripts/pnscripts-price-history/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/pnscripts/pnscripts-price-history/releases/tag/v1.0.0

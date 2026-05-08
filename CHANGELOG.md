# Changelog

All notable changes to `dashed-ecommerce-etsy` will be documented in this file.

## v4.0.0 - 2026-05-08

### Added
- Initial release. Etsy v3 API integratie met OAuth 2.0 + PKCE per site.
- Order import: Etsy receipts → Dashed `Order` + `OrderProduct` + `OrderPayment` (cron `dashed-etsy:sync-orders`, elke 5 min).
- Track-&-trace push-back: zodra een Order met Etsy origin een tracking-code krijgt, wordt die naar Etsy gepusht (cron `dashed-etsy:sync-shipments`, elke 5 min).
- Token refresh cron `dashed-etsy:refresh-token` (elke 30 min) zodat access tokens (1u TTL) nooit verlopen tijdens een sync.
- Filament settings page met credentials-velden, OAuth-koppelknop en verbindingsstatus per site.
- Pivot-tabel `dashed__etsy_orders` voor idempotente koppeling tussen Etsy receipt en Dashed Order.

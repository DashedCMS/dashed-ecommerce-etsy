# Changelog

All notable changes to `dashed-ecommerce-etsy` will be documented in this file.

## v4.0.4 - 2026-05-08

### Fixed
- Settings page: `TextEntry`-velden voor "Etsy voor {site}" en de status-tekst tonen nu geen field-naam-label meer ("Etsy status site label" / "Etsy status site value"). `->hiddenLabel()` toegepast.
- `Etsy::fetchShopIdForUser()` is robuuster: ondersteunt drie response-shapes (direct shop-object, `{count, results: [...]}` paginated wrapper, lijst-response) zodat het `shop_id`-veld altijd correct geëxtraheerd wordt. Bij faalde fetch wordt nu de error-body in `etsy_connection_error` opgeslagen en gelogd voor diagnose.

### Added
- Nieuwe `Etsy::syncShopId()` publieke methode + "Werk shop_id bij"-knop op de settings page. Als een site wel gekoppeld is maar nog geen `shop_id` heeft (bv. door een stille fetch-fout tijdens OAuth), kan de admin met één klik de shop opnieuw ophalen zonder de hele OAuth-flow opnieuw te doen.

## v4.0.3 - 2026-05-08

### Fixed
- "Verbind ... met Etsy" / "Opnieuw verbinden" button werkt nu echt: Livewire/Filament `->action(fn => redirect()->away(...))` houdt externe redirects tegen waardoor de browser nooit naar Etsy's consent-pagina werd gestuurd. Nieuwe `EtsyOAuthStartController` + route `dashed.etsy.oauth.start` doet de server-side 302 naar Etsy. De action-button is nu een plain `->url(route(...))`-link (zelfde patroon als `dashed-ecommerce-exactonline`).

## v4.0.2 - 2026-05-08

### Fixed
- OAuth-callback redirect: stuurde gebruiker terug naar `/dashed/settings` (bestaat niet) waardoor de admin een 404 of de hoofdpagina kreeg na het koppelen. Nu redirect via `route('filament.dashed.pages.etsy-settings-page')` naar de daadwerkelijke Etsy-instellingen-pagina, met fallback op `/dashed/etsy-settings-page` mocht de routenaam niet resolved kunnen worden.

## v4.0.1 - 2026-05-08

### Added
- Read-only "Redirect URI"-veld per site op `EtsySettingsPage`. Toont de exacte callback-URL (`<host>/dashed/etsy/oauth/callback?site_id=<site>`) die in de Etsy developer console als "Callback URL" moet worden ingesteld. Klik selecteert de tekst voor snel kopiëren.

## v4.0.0 - 2026-05-08

### Added
- Initial release. Etsy v3 API integratie met OAuth 2.0 + PKCE per site.
- Order import: Etsy receipts → Dashed `Order` + `OrderProduct` + `OrderPayment` (cron `dashed-etsy:sync-orders`, elke 5 min).
- Track-&-trace push-back: zodra een Order met Etsy origin een tracking-code krijgt, wordt die naar Etsy gepusht (cron `dashed-etsy:sync-shipments`, elke 5 min).
- Token refresh cron `dashed-etsy:refresh-token` (elke 30 min) zodat access tokens (1u TTL) nooit verlopen tijdens een sync.
- Filament settings page met credentials-velden, OAuth-koppelknop en verbindingsstatus per site.
- Pivot-tabel `dashed__etsy_orders` voor idempotente koppeling tussen Etsy receipt en Dashed Order.

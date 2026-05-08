# Changelog

All notable changes to `dashed-ecommerce-etsy` will be documented in this file.

## v4.2.0 - 2026-05-08

### Removed
- Handmatige `etsy_listing_id`-koppeling: het veld op `dashed__product_groups` en de `dashed-etsy:link-listing` artisan command zijn weg. Matching is nu volledig automatisch — geen admin-koppelwerk per listing meer.

### Changed
- **Slimmere automatische product-matching** met token + startsWith fallback. Volgorde nu: (1) SKU exact, (2) `Product.name` exact, (3) `ProductGroup.name` exact, (4) elk betekenisvol token (≥3 chars, non-numeric) van de Etsy title vs `ProductGroup.name` exact in elke locale, (5) `ProductGroup.name LIKE eerste-token%`. Vangt verschil tussen bv. "Bluma 3D vase" (Etsy) en "Bluma 3D vaas" (Dashed NL) of "Bluma" (Dashed EN). De LIKE-anywhere fuzzy fallback uit v4.1.0 is verwijderd want te veel false positives.
- **`product_extras` formaat** is nu een lijst van `{name, value}` paren — het formaat dat `invoice.blade.php` verwacht. Voorheen waren het associative keys/values waardoor de invoice-template crashte met `Trying to access array offset on int`. Bevat nu Etsy variations (Color/Size etc.) plus listing-metadata (Etsy listing-ID, transaction-ID, verzendmethode, verwachte verzenddatum).

### Migration
- Drop migration voor `dashed__product_groups.etsy_listing_id` (datakolom + index).

## v4.1.0 - 2026-05-08

### Changed
- **Order-mapping volgt nu het Bol-patroon**: `etsy_receipt_id` + `etsy_shop_id` + `etsy_track_and_trace_pushed_at` + `etsy_track_and_trace_error` zijn directe kolommen op `dashed__orders` (i.p.v. de `dashed__etsy_orders` pivot, die in deze migration wordt gedropt na data-migratie). `invoice_id` start als `'PROFORMA'` en wordt direct daarna via `Order::generateInvoiceId()` op het webshop's eigen invoice-counter nummer gezet, zoals Bol dat ook doet.
- **Status altijd `paid` + automatische `OrderPayment`**: Etsy heeft de betaling al afgehandeld, dus elke synced receipt wordt direct als betaald gemarkeerd. `OrderPayment` met `psp='etsy'` en `status='paid'` wordt automatisch aangemaakt.
- **Auto-MyParcel-koppeling**: zodra `dashed-ecommerce-myparcel` geïnstalleerd + geconnect is roept `Etsy::syncOrder()` `MyParcel::connectOrderWithCarrier($order)` aan. Het MyParcel-label staat dan direct klaar in de wachtrij van de cron, zonder admin-interactie.
- **BTW automatisch berekend** via OrderProduct's bestaande `creating` boot-hook: per regel `vat_rate` (default 21%, overgenomen van het gematchte Product) → `btw = price - price/(1+vat_rate/100)`. Order-level som van btw + `vat_percentages` wordt na alle line-items berekend.

### Added
- **Slimmere product-matching**: 5-stappen-cascade — (1) SKU exact, (2) `ProductGroup.etsy_listing_id == transaction.listing_id` handmatige koppeling, (3) `Product.name` exact, (4) `ProductGroup.name` exact + eerste variant, (5) `Product.name LIKE %title%` fuzzy fallback.
- **Migration**: `dashed__product_groups.etsy_listing_id` (string, nullable, indexed) — admin koppelt expliciet welke Dashed productgroep bij welke Etsy listing hoort. Komt overeen met Bol's pattern voor extra integration-fields op core models.
- **Artisan command** `dashed-etsy:link-listing {group_id} {listing_id}` — link een ProductGroup aan een Etsy listing_id zonder UI-werk.
- **OrderProduct.product_extras** wordt nu gevuld met Etsy-metadata: `etsy_transaction_id`, `etsy_listing_id`, `etsy_listing_image_id`, `etsy_product_id`, `etsy_is_digital`, `etsy_shipping_method`, `etsy_expected_ship_date`, `etsy_variations` (geformatteerde property/value-paren) en `etsy_product_data`. Admin kan die in de Order edit-pagina inspecteren.
- **`skipped`-counter** in CLI-output van `dashed-etsy:sync-orders` zodat re-runs duidelijk laten zien welke receipts al bekend waren.

### Migration impact
- Nieuwe kolommen op `dashed__orders` (4 stuks).
- `dashed__product_groups.etsy_listing_id` toegevoegd.
- Pivot `dashed__etsy_orders` wordt gedropt na automatische data-migratie naar de nieuwe kolommen.
- Order- en ProductGroup-data zonder Etsy-koppeling blijft onaangeroerd.

## v4.0.8 - 2026-05-08

### Fixed
- Etsy API `x-api-key` header is nu `<keystring>:<shared_secret>` i.p.v. alleen `<keystring>`. Dat is wat Etsy v3 voor app-tier authenticatie verwacht. Voorheen kreeg iedere API-call een 403 `"Shared secret is required in x-api-key header."` terug, waardoor noch shop_id-fetch noch order-sync werkte. Nieuwe `Etsy::apiKeyHeader()` helper levert de juiste samengestelde waarde.
- User-class import gecorrigeerd van `Dashed\DashedEcommerceCore\Models\User` naar `Dashed\DashedCore\Models\User` (de echte locatie van de Authenticatable User).
- `Order` mapping past nu op het Dashed schema: `first_name`/`last_name` (gesplitst uit Etsy's `name` veld via `splitName()`-helper), `house_nr` voor adresregel-2, geen losse `shipping_costs`-kolom (verzendkosten gaan nu als `OrderProduct` met sku `shipping_costs` zoals checkout dat ook doet).
- `paid_at` en `created_at` worden niet meer in een mass-fillable assign meegenomen (Order heeft die kolommen wel, maar guarded-fields worden via direct assignment + extra save geüpdatet).

### Changed
- `Etsy::syncOrders()` retourneert nu ook `skipped`-count voor receipts die al bekend waren (geen duplicate insert dankzij `EtsyOrder::etsy_receipt_id` unique-constraint). CLI-output en notification op de settings page tonen "X nieuw, Y al bekend".

## v4.0.7 - 2026-05-08

### Added
- Handmatig `etsy_shop_id`-veld op de settings page als fallback wanneer de auto-fetch via `/users/{user_id}/shops` faalt. Admin kan de shop_id direct intypen, het opslaan en verder met "Sync bestellingen". Het veld toont de huidige waarde (al ingevulde shop_id of leeg).
- `submit()` slaat handmatige shop_id-invoer op in `Customsetting('etsy_shop_id')`.

## v4.0.6 - 2026-05-08

### Changed
- "Sync bestellingen"-knop verschijnt nu zodra een site gekoppeld is, ook als `shop_id` nog ontbreekt. Voorheen was 'm verborgen achter `shopId() !== null` waardoor admins met een falende shop_id-fetch de knop nooit zagen. De `Etsy::syncOrders()` doet zelf de shop_id-check en geeft een duidelijke foutmelding via de notification als die ontbreekt.

## v4.0.5 - 2026-05-08

### Added
- "Sync bestellingen ({site})"-knop op de `EtsySettingsPage` (verschijnt alleen wanneer site gekoppeld is + `shop_id` bekend). Klik triggert direct `Etsy::syncOrders($siteId)` met een confirm-modal. Notification toont aantal geïmporteerd + eventuele fouten. Daarmee hoef je niet op de 5-min-cron te wachten om net-geplaatste Etsy bestellingen binnen te halen.

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

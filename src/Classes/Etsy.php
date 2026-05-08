<?php

namespace Dashed\DashedEcommerceEtsy\Classes;

use Carbon\Carbon;
use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Models\User;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderLog;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceCore\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Etsy
{
    public const API_BASE = 'https://openapi.etsy.com/v3';

    public const OAUTH_AUTHORIZE = 'https://www.etsy.com/oauth/connect';

    public const OAUTH_TOKEN = 'https://api.etsy.com/v3/public/oauth/token';

    public const SCOPES = 'transactions_r transactions_w shops_r';

    public static function isConnected(?string $siteId = null): bool
    {
        $siteId ??= Sites::getActive();

        return (bool) Customsetting::get('etsy_connected', $siteId, false)
            && Customsetting::get('etsy_refresh_token', $siteId);
    }

    public static function clientId(?string $siteId = null): ?string
    {
        return Customsetting::get('etsy_client_id', $siteId ?? Sites::getActive()) ?: null;
    }

    public static function clientSecret(?string $siteId = null): ?string
    {
        return Customsetting::get('etsy_client_secret', $siteId ?? Sites::getActive()) ?: null;
    }

    public static function shopId(?string $siteId = null): ?string
    {
        return Customsetting::get('etsy_shop_id', $siteId ?? Sites::getActive()) ?: null;
    }

    public static function apiKeyHeader(?string $siteId = null): ?string
    {
        $clientId = self::clientId($siteId);
        $secret = self::clientSecret($siteId);
        if (! $clientId || ! $secret) {
            return null;
        }

        return $clientId.':'.$secret;
    }

    /**
     * @return array{url: string, state: string}
     */
    public static function buildAuthorizeUrl(string $siteId, string $redirectUri): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        Customsetting::set('etsy_oauth_verifier', $verifier, $siteId);
        Customsetting::set('etsy_oauth_state', $state, $siteId);

        $url = self::OAUTH_AUTHORIZE.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => self::clientId($siteId),
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => $url, 'state' => $state];
    }

    public static function exchangeCodeForTokens(string $siteId, string $code, string $redirectUri): bool
    {
        $verifier = Customsetting::get('etsy_oauth_verifier', $siteId);
        if (! $verifier) {
            Customsetting::set('etsy_connection_error', 'OAuth verifier ontbreekt; herhaal de koppeling.', $siteId);

            return false;
        }

        try {
            $response = Http::asForm()->post(self::OAUTH_TOKEN, [
                'grant_type' => 'authorization_code',
                'client_id' => self::clientId($siteId),
                'redirect_uri' => $redirectUri,
                'code' => $code,
                'code_verifier' => $verifier,
            ]);

            if (! $response->successful()) {
                Customsetting::set('etsy_connection_error', 'Token-exchange faalde: '.$response->body(), $siteId);

                return false;
            }

            $data = $response->json();
            self::storeTokens($siteId, $data);

            $userId = Str::before((string) ($data['access_token'] ?? ''), '.');
            $shopId = self::fetchShopIdForUser($siteId, $userId);
            if ($shopId) {
                Customsetting::set('etsy_shop_id', $shopId, $siteId);
            }

            Customsetting::set('etsy_connected', true, $siteId);
            Customsetting::set('etsy_connection_error', '', $siteId);
            Customsetting::set('etsy_oauth_verifier', '', $siteId);
            Customsetting::set('etsy_oauth_state', '', $siteId);

            return true;
        } catch (Throwable $e) {
            Customsetting::set('etsy_connection_error', $e->getMessage(), $siteId);
            Log::warning('Etsy token exchange failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function refreshAccessToken(?string $siteId = null): bool
    {
        $siteId ??= Sites::getActive();
        $refresh = Customsetting::get('etsy_refresh_token', $siteId);
        if (! $refresh || ! self::clientId($siteId)) {
            return false;
        }

        try {
            $response = Http::asForm()->post(self::OAUTH_TOKEN, [
                'grant_type' => 'refresh_token',
                'client_id' => self::clientId($siteId),
                'refresh_token' => $refresh,
            ]);

            if (! $response->successful()) {
                Customsetting::set('etsy_connection_error', 'Token refresh faalde: '.$response->body(), $siteId);
                Customsetting::set('etsy_connected', false, $siteId);

                return false;
            }

            self::storeTokens($siteId, $response->json());
            Customsetting::set('etsy_connection_error', '', $siteId);

            return true;
        } catch (Throwable $e) {
            Customsetting::set('etsy_connection_error', $e->getMessage(), $siteId);
            Log::warning('Etsy refresh failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function ensureValidAccessToken(?string $siteId = null): ?string
    {
        $siteId ??= Sites::getActive();
        $expiresAt = Customsetting::get('etsy_token_expires_at', $siteId);
        $needsRefresh = ! $expiresAt || Carbon::parse($expiresAt)->subMinutes(5)->isPast();
        if ($needsRefresh) {
            self::refreshAccessToken($siteId);
        }

        return Customsetting::get('etsy_access_token', $siteId) ?: null;
    }

    /**
     * @param  array{body?: array<string, mixed>, headers?: array<string, string>}  $options
     */
    public static function api(string $siteId, string $method, string $path, array $options = []): Response
    {
        $accessToken = self::ensureValidAccessToken($siteId);
        $apiKey = self::apiKeyHeader($siteId);
        $method = strtolower($method);

        $request = Http::withHeaders(array_merge([
            'x-api-key' => $apiKey,
            'Authorization' => 'Bearer '.$accessToken,
        ], $options['headers'] ?? []));

        return match ($method) {
            'get' => $request->get(self::API_BASE.$path, $options['body'] ?? []),
            'post' => $request->post(self::API_BASE.$path, $options['body'] ?? []),
            'put' => $request->put(self::API_BASE.$path, $options['body'] ?? []),
            'delete' => $request->delete(self::API_BASE.$path, $options['body'] ?? []),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };
    }

    public static function syncShopId(?string $siteId = null): ?string
    {
        $siteId ??= Sites::getActive();
        $accessToken = self::ensureValidAccessToken($siteId);
        if (! $accessToken) {
            return null;
        }
        $userId = Str::before($accessToken, '.');
        if (! $userId) {
            return null;
        }
        $shopId = self::fetchShopIdForUser($siteId, $userId);
        if ($shopId) {
            Customsetting::set('etsy_shop_id', $shopId, $siteId);
        }

        return $shopId;
    }

    /**
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public static function syncOrders(?string $siteId = null): array
    {
        $siteId ??= Sites::getActive();
        if (! self::isConnected($siteId)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Etsy niet gekoppeld voor site '.$siteId]];
        }

        $shopId = self::shopId($siteId);
        if (! $shopId) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Geen shop_id bekend voor site '.$siteId]];
        }

        $minCreated = (int) (Customsetting::get('etsy_min_created_after', $siteId, now()->subDays(7)->timestamp));
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $cursorMax = $minCreated;

        $offset = 0;
        do {
            $response = self::api($siteId, 'get', "/application/shops/{$shopId}/receipts", [
                'body' => [
                    'min_created' => $minCreated,
                    'limit' => 100,
                    'offset' => $offset,
                    'sort_on' => 'created',
                    'sort_order' => 'asc',
                ],
            ]);

            if (! $response->successful()) {
                $errors[] = 'Receipts fetch faalde: HTTP '.$response->status().' '.substr($response->body(), 0, 200);
                break;
            }

            $payload = $response->json();
            $results = $payload['results'] ?? [];
            if (empty($results)) {
                break;
            }

            foreach ($results as $receipt) {
                try {
                    $existedBefore = Order::where('etsy_receipt_id', (string) ($receipt['receipt_id'] ?? ''))->exists();
                    self::syncOrder($siteId, $receipt);
                    if ($existedBefore) {
                        $skipped++;
                    } else {
                        $imported++;
                    }
                    $cursorMax = max($cursorMax, (int) ($receipt['created_timestamp'] ?? 0));
                } catch (Throwable $e) {
                    $errors[] = 'Receipt '.($receipt['receipt_id'] ?? '?').': '.$e->getMessage();
                    Log::warning('Etsy syncOrder failed', [
                        'site_id' => $siteId,
                        'receipt_id' => $receipt['receipt_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $offset += 100;
            if (count($results) < 100) {
                break;
            }
        } while (true);

        Customsetting::set('etsy_min_created_after', $cursorMax, $siteId);
        Customsetting::set('etsy_last_sync_at', now()->toIso8601String(), $siteId);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public static function syncOrder(string $siteId, array $receipt): Order
    {
        $receiptId = (string) ($receipt['receipt_id'] ?? '');
        if (! $receiptId) {
            throw new RuntimeException('Receipt zonder receipt_id');
        }

        $existing = Order::where('etsy_receipt_id', $receiptId)->first();
        if ($existing) {
            return $existing;
        }

        $email = (string) ($receipt['buyer_email'] ?? ($receipt['buyer'][0]['email'] ?? ''));
        $email = $email !== '' ? strtolower(trim($email)) : 'etsy-'.$receiptId.'@noemail.local';

        $fullName = trim((string) ($receipt['name'] ?? ''));
        [$firstName, $lastName] = self::splitName($fullName);

        $user = User::firstOrCreate(['email' => $email], [
            'first_name' => $firstName !== '' ? $firstName : 'Etsy',
            'last_name' => $lastName !== '' ? $lastName : 'Klant',
            'password' => bcrypt(Str::random(32)),
        ]);

        $shippingCost = self::amount($receipt['total_shipping_cost'] ?? null);
        $shopId = self::shopId($siteId);

        $order = new Order();
        $order->user_id = $user->id;
        $order->order_origin = 'etsy';
        $order->site_id = $siteId;
        $order->etsy_receipt_id = $receiptId;
        $order->etsy_shop_id = $shopId;
        $order->invoice_id = 'PROFORMA';
        $order->email = $email;
        $order->first_name = $firstName;
        $order->last_name = $lastName;
        $order->phone_number = (string) ($receipt['buyer_phone'] ?? '');
        $order->street = trim((string) ($receipt['first_line'] ?? ''));
        $order->house_nr = trim((string) ($receipt['second_line'] ?? ''));
        $order->zip_code = (string) ($receipt['zip'] ?? '');
        $order->city = (string) ($receipt['city'] ?? '');
        $order->country = (string) ($receipt['country_iso'] ?? '');
        $order->total = self::amount($receipt['grandtotal'] ?? null);
        $order->subtotal = self::amount($receipt['subtotal'] ?? null);
        $order->status = 'paid'; // Etsy heeft de betaling al afgehandeld; status altijd paid bij sync
        $order->fulfillment_status = ! empty($receipt['was_shipped']) ? 'shipped' : 'unhandled';
        $order->locale = Locales::getFirstLocale()['id'] ?? 'nl';
        $order->invoice_send_to_customer = 0;
        $order->save();

        if (! empty($receipt['created_timestamp'])) {
            $order->created_at = Carbon::createFromTimestamp((int) $receipt['created_timestamp']);
            $order->save();
        }

        // Generate invoice_id volgens webshop's eigen counter (PROFORMA -> echte nummer)
        $order->generateInvoiceId();

        // Lijn-items uit transactions met smart product-match + auto-BTW via boot hook
        foreach (($receipt['transactions'] ?? []) as $transaction) {
            self::createOrderProduct($order, $transaction);
        }

        // Verzendkosten als losse OrderProduct met sku 'shipping_costs'
        if ($shippingCost > 0) {
            $shippingLine = new OrderProduct();
            $shippingLine->order_id = $order->id;
            $shippingLine->name = 'Verzendkosten (Etsy)';
            $shippingLine->sku = 'shipping_costs';
            $shippingLine->quantity = 1;
            $shippingLine->price = $shippingCost;
            $shippingLine->vat_rate = 21;
            $shippingLine->save();
        }

        // Som BTW per vat_rate voor order.btw + order.vat_percentages
        $order->refresh();
        $btwTotal = 0.0;
        $vatPercentages = [];
        foreach ($order->orderProducts as $op) {
            $btwTotal += (float) $op->btw;
            $rate = (string) ((int) ($op->vat_rate ?? 21));
            $vatPercentages[$rate] = ($vatPercentages[$rate] ?? 0.0) + (float) $op->btw;
        }
        $order->btw = round($btwTotal, 2);
        $order->vat_percentages = array_map(fn ($v) => round((float) $v, 2), $vatPercentages);
        $order->save();

        // OrderPayment altijd paid voor Etsy bestellingen
        $payment = new OrderPayment();
        $payment->order_id = $order->id;
        $payment->psp = 'etsy';
        $payment->payment_method = 'Etsy';
        $payment->status = 'paid';
        $payment->amount = $order->total;
        $payment->save();

        OrderLog::createLog($order->id, note: 'Order aangemaakt via Etsy met receipt ID '.$receiptId);

        // Auto MyParcel-koppeling als die package geïnstalleerd én geconnect is
        if (class_exists(\Dashed\DashedEcommerceMyParcel\Classes\MyParcel::class)) {
            try {
                \Dashed\DashedEcommerceMyParcel\Classes\MyParcel::connectOrderWithCarrier($order);
            } catch (Throwable $e) {
                Log::warning('Etsy → MyParcel auto-connect failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $order->fresh() ?? $order;
    }

    public static function pushTrackAndTrace(Order $order): bool
    {
        if (! $order->etsy_receipt_id || $order->trackAndTraces->isEmpty()) {
            return false;
        }

        $tnt = $order->trackAndTraces->last();
        $shopId = $order->etsy_shop_id ?: self::shopId($order->site_id);
        if (! $shopId) {
            $order->update(['etsy_track_and_trace_error' => 'Geen shop_id beschikbaar']);

            return false;
        }

        $carrier = self::mapCarrier((string) ($tnt->delivery_company ?? ''));

        try {
            $response = self::api($order->site_id, 'post',
                "/application/shops/{$shopId}/receipts/{$order->etsy_receipt_id}/tracking",
                [
                    'body' => [
                        'tracking_code' => $tnt->code,
                        'carrier_name' => $carrier,
                        'send_bcc' => true,
                    ],
                ]
            );

            if (! $response->successful()) {
                $order->update([
                    'etsy_track_and_trace_error' => 'HTTP '.$response->status().' '.substr($response->body(), 0, 300),
                ]);

                return false;
            }

            $order->update([
                'etsy_track_and_trace_pushed_at' => now(),
                'etsy_track_and_trace_error' => null,
            ]);

            return true;
        } catch (Throwable $e) {
            $order->update(['etsy_track_and_trace_error' => $e->getMessage()]);
            Log::warning('Etsy pushTrackAndTrace failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Match Etsy transaction op een Dashed Product:
     *   1. SKU exact match (Etsy listing-SKU == Dashed product SKU)
     *   2. Naam exact-match (case-insensitive) op de translatable name-JSON
     *   3. Geen match → product_id null, naam/SKU/prijs uit Etsy
     *
     * @param  array<string, mixed>  $transaction
     */
    private static function createOrderProduct(Order $order, array $transaction): void
    {
        $sku = trim((string) ($transaction['sku'] ?? ''));
        $title = trim((string) ($transaction['title'] ?? ''));

        $product = self::matchProduct($sku, $title);

        $orderProduct = new OrderProduct();
        $orderProduct->order_id = $order->id;
        $orderProduct->product_id = $product?->id;
        $orderProduct->name = $product?->name ?: ($title ?: 'Etsy item');
        $orderProduct->sku = $product?->sku ?: ($sku ?: null);
        $orderProduct->quantity = (int) ($transaction['quantity'] ?? 1);

        // Etsy levert single-unit price; vermenigvuldig met quantity zoals Bol dat ook doet
        $unitPrice = self::amount($transaction['price'] ?? null);
        $orderProduct->price = round($unitPrice * (int) ($transaction['quantity'] ?? 1), 2);

        // vat_rate via product, anders 21% default. OrderProduct boot-hook berekent btw zelf
        // op creating wanneer die nog niet is gezet.
        if ($product && $product->vat_rate !== null) {
            $orderProduct->vat_rate = (int) $product->vat_rate;
        } else {
            $orderProduct->vat_rate = 21;
        }
        $orderProduct->discount = 0;

        // Extra Etsy-info zodat admin kan zien om welke listing/variation het ging
        $orderProduct->product_extras = self::extractProductExtras($transaction);

        // Etsy levert images via apart endpoint per listing; voor nu alleen
        // listing_image_id opslaan in product_extras (image-url kan later async opgehaald)
        if (! $product && ! empty($transaction['listing_image_id'])) {
            $orderProduct->custom_image = null; // bewust leeg; laden zou extra API call vergen
        }

        $orderProduct->save();
    }

    /**
     * Probeer een Dashed Product automatisch te vinden voor deze Etsy
     * transaction. Geen handmatige koppeling — pure auto-match. Volgorde:
     *   1. SKU exact (Etsy listing-SKU == Dashed product SKU) — sterkste signaal
     *   2. Product.name exact match over alle locales (case-insensitive)
     *   3. ProductGroup.name exact match → eerste product in die group
     *   4. Token-match: eerste betekenisvolle token (>=3 chars, non-numeric) van
     *      de Etsy title is gelijk aan een ProductGroup name in elke locale
     *      (vangt "Bluma 3D vase" → group "Bluma" of "Bluma 3D vaas")
     *   5. ProductGroup.name STARTS WITH eerste token van title (vangt verschil
     *      tussen "vase" en "vaas" zolang het begin-woord matcht)
     *
     * Geen LIKE-anywhere-fallback meer: te veel false positives.
     */
    private static function matchProduct(string $sku, string $title): ?Product
    {
        if ($sku !== '') {
            $product = Product::where('sku', $sku)->first();
            if ($product) {
                return $product;
            }
        }

        if ($title === '') {
            return null;
        }

        $titleLower = strtolower($title);
        $locales = array_values(array_filter(array_map(
            fn ($l) => $l['id'] ?? null,
            Locales::getLocales()
        )));
        if (empty($locales)) {
            return null;
        }

        $matchOnLocale = function (string $modelClass, string $value, string $operator = '=', string $valueSuffix = '') use ($locales) {
            return $modelClass::query()->where(function (Builder $q) use ($locales, $value, $operator, $valueSuffix) {
                foreach ($locales as $localeId) {
                    $q->orWhereRaw(
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(name, ?))) '.$operator.' ?',
                        ['$."'.$localeId.'"', $value.$valueSuffix]
                    );
                }
            })->first();
        };

        // 2. Product.name exact match
        $product = $matchOnLocale(Product::class, $titleLower);
        if ($product) {
            return $product;
        }

        $hasGroups = class_exists(\Dashed\DashedEcommerceCore\Models\ProductGroup::class);

        // 3. ProductGroup.name exact match → eerste variant
        if ($hasGroups) {
            $group = $matchOnLocale(\Dashed\DashedEcommerceCore\Models\ProductGroup::class, $titleLower);
            if ($group) {
                $variant = Product::where('product_group_id', $group->id)->first();
                if ($variant) {
                    return $variant;
                }
            }
        }

        // Tokenize voor fallback-strategieën
        $tokens = array_values(array_filter(
            preg_split('/[\s\-_,]+/', $titleLower) ?: [],
            fn ($t) => strlen((string) $t) >= 3 && ! ctype_digit((string) $t)
        ));

        if (empty($tokens) || ! $hasGroups) {
            return null;
        }

        // 4. Token == ProductGroup name (in any locale)
        foreach ($tokens as $token) {
            $group = $matchOnLocale(\Dashed\DashedEcommerceCore\Models\ProductGroup::class, $token);
            if ($group) {
                $variant = Product::where('product_group_id', $group->id)->first();
                if ($variant) {
                    return $variant;
                }
            }
        }

        // 5. ProductGroup name STARTS WITH eerste token (vangt vase/vaas-soort verschillen)
        $firstToken = $tokens[0];
        $group = $matchOnLocale(\Dashed\DashedEcommerceCore\Models\ProductGroup::class, $firstToken, 'LIKE', '%');
        if ($group) {
            $variant = Product::where('product_group_id', $group->id)->first();
            if ($variant) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Bouw product_extras voor OrderProduct als een lijst van `{name, value}`
     * paren — dat is het formaat dat invoice.blade.php verwacht (loop over
     * `$option['name']` / `$option['value']`). Bevat de Etsy variations
     * (Color/Size etc.) plus relevante listing-metadata zodat de admin de
     * oorspronkelijke Etsy-info kan terugzien op de order/invoice.
     *
     * @param  array<string, mixed>  $transaction
     * @return array<int, array{name: string, value: string}>
     */
    private static function extractProductExtras(array $transaction): array
    {
        $extras = [];

        // Etsy variations (color, size, etc.) — primair zichtbaar op invoice
        if (! empty($transaction['variations']) && is_array($transaction['variations'])) {
            foreach ($transaction['variations'] as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $name = (string) ($variation['formatted_name'] ?? ($variation['property_name'] ?? ''));
                $value = (string) ($variation['formatted_value'] ?? ($variation['value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $extras[] = ['name' => $name, 'value' => $value];
                }
            }
        }

        // Etsy listing/transaction metadata — handig voor admin maar niet kritiek voor invoice
        $meta = [
            'Etsy listing' => $transaction['listing_id'] ?? null,
            'Etsy transaction' => $transaction['transaction_id'] ?? null,
            'Verzending' => $transaction['shipping_method'] ?? null,
            'Verwachte verzenddatum' => $transaction['expected_ship_date'] ?? null,
        ];
        foreach ($meta as $name => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $extras[] = ['name' => $name, 'value' => (string) $value];
        }

        if (! empty($transaction['is_digital'])) {
            $extras[] = ['name' => 'Type', 'value' => 'Digitaal'];
        }

        return $extras;
    }

    private static function storeTokens(string $siteId, array $data): void
    {
        Customsetting::set('etsy_access_token', $data['access_token'] ?? '', $siteId);
        Customsetting::set('etsy_refresh_token', $data['refresh_token'] ?? '', $siteId);
        $ttl = (int) ($data['expires_in'] ?? 3600);
        Customsetting::set('etsy_token_expires_at', now()->addSeconds($ttl)->toIso8601String(), $siteId);
    }

    private static function fetchShopIdForUser(string $siteId, string $userId): ?string
    {
        $accessToken = Customsetting::get('etsy_access_token', $siteId);
        $apiKey = self::apiKeyHeader($siteId);
        if (! $accessToken || ! $apiKey || ! $userId) {
            return null;
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Authorization' => 'Bearer '.$accessToken,
        ])->get(self::API_BASE.'/application/users/'.$userId.'/shops');

        if (! $response->successful()) {
            Customsetting::set('etsy_connection_error', 'Shop fetch faalde: HTTP '.$response->status().' '.substr($response->body(), 0, 300), $siteId);
            Log::warning('Etsy fetchShopIdForUser failed', [
                'site_id' => $siteId,
                'user_id' => $userId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $json = $response->json();

        $candidates = [];
        if (is_array($json)) {
            $candidates[] = $json['shop_id'] ?? null;
            if (isset($json['results'][0]['shop_id'])) {
                $candidates[] = $json['results'][0]['shop_id'];
            }
            if (isset($json[0]['shop_id'])) {
                $candidates[] = $json[0]['shop_id'];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        Log::warning('Etsy shop response zonder shop_id', [
            'site_id' => $siteId,
            'user_id' => $userId,
            'body' => substr((string) $response->body(), 0, 500),
        ]);

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $fullName, 2) ?: [$fullName];
        $first = (string) ($parts[0] ?? '');
        $last = (string) ($parts[1] ?? '');

        return [$first, $last];
    }

    /**
     * @param  array<string, mixed>|null  $money  Etsy money: { amount, divisor, currency_code }
     */
    private static function amount(?array $money): float
    {
        if (! $money || ! isset($money['amount'])) {
            return 0.0;
        }
        $divisor = max(1, (int) ($money['divisor'] ?? 100));

        return round((float) $money['amount'] / $divisor, 2);
    }

    private static function mapCarrier(string $deliveryCompany): string
    {
        $key = strtolower(trim($deliveryCompany));

        return match (true) {
            str_contains($key, 'postnl') => 'postnl',
            str_contains($key, 'dhl') => 'dhl',
            str_contains($key, 'dpd') => 'dpd',
            str_contains($key, 'ups') => 'ups',
            str_contains($key, 'fedex') => 'fedex',
            default => 'other',
        };
    }
}

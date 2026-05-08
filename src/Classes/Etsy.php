<?php

namespace Dashed\DashedEcommerceEtsy\Classes;

use Carbon\Carbon;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\User;
use Dashed\DashedEcommerceEtsy\Models\EtsyOrder;
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

            // Etsy access_token format is "<user_id>.<token>"; user_id is voorkant
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
        $clientId = self::clientId($siteId);
        $method = strtolower($method);

        $request = Http::withHeaders(array_merge([
            'x-api-key' => $clientId,
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

    /**
     * @return array{imported: int, errors: array<int, string>}
     */
    public static function syncOrders(?string $siteId = null): array
    {
        $siteId ??= Sites::getActive();
        if (! self::isConnected($siteId)) {
            return ['imported' => 0, 'errors' => ['Etsy niet gekoppeld voor site '.$siteId]];
        }

        $shopId = self::shopId($siteId);
        if (! $shopId) {
            return ['imported' => 0, 'errors' => ['Geen shop_id bekend voor site '.$siteId]];
        }

        $minCreated = (int) (Customsetting::get('etsy_min_created_after', $siteId, now()->subDays(7)->timestamp));
        $imported = 0;
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
                    self::syncOrder($siteId, $receipt);
                    $imported++;
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

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public static function syncOrder(string $siteId, array $receipt): EtsyOrder
    {
        $receiptId = (string) ($receipt['receipt_id'] ?? '');
        if (! $receiptId) {
            throw new RuntimeException('Receipt zonder receipt_id');
        }

        $existing = EtsyOrder::where('etsy_receipt_id', $receiptId)->first();
        if ($existing) {
            return $existing;
        }

        $email = (string) ($receipt['buyer_email'] ?? ($receipt['buyer'][0]['email'] ?? ''));
        $email = $email !== '' ? strtolower(trim($email)) : 'etsy-'.$receiptId.'@noemail.local';

        $user = User::firstOrCreate(['email' => $email], [
            'name' => trim(((string) ($receipt['name'] ?? '')) ?: 'Etsy Klant'),
            'password' => bcrypt(Str::random(32)),
        ]);

        $order = new Order();
        $order->user_id = $user->id;
        $order->order_origin = 'etsy';
        $order->site_id = $siteId;
        $order->invoice_id = 'ETSY-'.$receiptId;
        $order->email = $email;
        $order->name = (string) ($receipt['name'] ?? '');
        $order->phone_number = (string) ($receipt['buyer_phone'] ?? '');
        $order->street = trim(((string) ($receipt['first_line'] ?? '')).' '.((string) ($receipt['second_line'] ?? '')));
        $order->zip_code = (string) ($receipt['zip'] ?? '');
        $order->city = (string) ($receipt['city'] ?? '');
        $order->country = (string) ($receipt['country_iso'] ?? '');
        $order->total = self::amount($receipt['grandtotal'] ?? null);
        $order->subtotal = self::amount($receipt['subtotal'] ?? null);
        $order->btw = self::amount($receipt['total_tax_cost'] ?? null);
        $order->shipping_costs = self::amount($receipt['total_shipping_cost'] ?? null);
        $order->status = ! empty($receipt['was_paid']) ? 'paid' : 'pending';
        $order->fulfillment_status = ! empty($receipt['was_shipped']) ? 'shipped' : 'unhandled';
        $order->paid_at = ! empty($receipt['was_paid']) && ! empty($receipt['paid_timestamp'])
            ? Carbon::createFromTimestamp((int) $receipt['paid_timestamp'])
            : null;
        $order->created_at = ! empty($receipt['created_timestamp'])
            ? Carbon::createFromTimestamp((int) $receipt['created_timestamp'])
            : now();
        $order->save();

        foreach (($receipt['transactions'] ?? []) as $transaction) {
            $sku = (string) ($transaction['sku'] ?? '');
            $product = $sku ? Product::where('sku', $sku)->first() : null;

            $orderProduct = new OrderProduct();
            $orderProduct->order_id = $order->id;
            $orderProduct->product_id = $product?->id;
            $orderProduct->name = (string) ($transaction['title'] ?? 'Etsy item');
            $orderProduct->sku = $sku ?: null;
            $orderProduct->quantity = (int) ($transaction['quantity'] ?? 1);
            $orderProduct->price = self::amount($transaction['price'] ?? null);
            $orderProduct->save();
        }

        if (! empty($receipt['was_paid'])) {
            $payment = new OrderPayment();
            $payment->order_id = $order->id;
            $payment->psp = 'etsy';
            $payment->status = 'paid';
            $payment->amount = $order->total;
            $payment->save();
        }

        return EtsyOrder::create([
            'order_id' => $order->id,
            'etsy_receipt_id' => $receiptId,
            'etsy_shop_id' => self::shopId($siteId) ?? '',
            'site_id' => $siteId,
        ]);
    }

    public static function pushTrackAndTrace(EtsyOrder $etsyOrder): bool
    {
        $order = $etsyOrder->order;
        if (! $order || $order->trackAndTraces->isEmpty()) {
            return false;
        }

        $tnt = $order->trackAndTraces->last();
        $shopId = $etsyOrder->etsy_shop_id ?: self::shopId($etsyOrder->site_id);
        if (! $shopId) {
            $etsyOrder->update(['track_and_trace_error' => 'Geen shop_id beschikbaar']);

            return false;
        }

        $carrier = self::mapCarrier((string) ($tnt->delivery_company ?? ''));

        try {
            $response = self::api($etsyOrder->site_id, 'post',
                "/application/shops/{$shopId}/receipts/{$etsyOrder->etsy_receipt_id}/tracking",
                [
                    'body' => [
                        'tracking_code' => $tnt->code,
                        'carrier_name' => $carrier,
                        'send_bcc' => true,
                    ],
                ]
            );

            if (! $response->successful()) {
                $etsyOrder->update([
                    'track_and_trace_error' => 'HTTP '.$response->status().' '.substr($response->body(), 0, 300),
                ]);

                return false;
            }

            $etsyOrder->update([
                'track_and_trace_pushed_at' => now(),
                'track_and_trace_error' => null,
            ]);

            return true;
        } catch (Throwable $e) {
            $etsyOrder->update(['track_and_trace_error' => $e->getMessage()]);
            Log::warning('Etsy pushTrackAndTrace failed', [
                'etsy_order_id' => $etsyOrder->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
        $clientId = self::clientId($siteId);
        if (! $accessToken || ! $clientId || ! $userId) {
            return null;
        }

        $response = Http::withHeaders([
            'x-api-key' => $clientId,
            'Authorization' => 'Bearer '.$accessToken,
        ])->get(self::API_BASE.'/application/users/'.$userId.'/shops');

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (isset($json['shop_id'])) {
            return (string) $json['shop_id'];
        }

        return null;
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

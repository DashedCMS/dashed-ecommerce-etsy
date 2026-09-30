<?php

namespace Dashed\DashedEcommerceEtsy\Classes;

use Illuminate\Support\Facades\Schema;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\Order;

/**
 * Etsy-transactiekosten per order (`dashed__orders.etsy_order_commission`).
 *
 * Etsy rekent een percentage (standaard 6,5%) over het volledige orderbedrag,
 * inclusief doorberekende verzendkosten en cadeauverpakking, en alleen bij een
 * echte verkoop. Het bedrag is de fee zelf, zonder de btw die Etsy daar voor
 * Nederlandse verkopers bovenop rekent.
 *
 * Bij een retour stort Etsy de fee terug. Net als bij Bol draagt een creditorder
 * daarom hetzelfde positieve bedrag als de oorspronkelijke order, zodat
 * rapportages hem kunnen aftrekken (`credit_for_order_id` gezet = negatief tellen).
 */
class EtsyCommission
{
    public const DEFAULT_PERCENTAGE = 6.5;

    public const SETTING = 'etsy_commission_percentage';

    private static ?bool $columnExists = null;

    public static function percentage(?string $siteId = null): float
    {
        $value = Customsetting::get(self::SETTING, $siteId, self::DEFAULT_PERCENTAGE);
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        return is_numeric($value) && (float) $value >= 0 ? (float) $value : self::DEFAULT_PERCENTAGE;
    }

    public static function forAmount(float $amount, ?string $siteId = null): float
    {
        return round(abs($amount) * self::percentage($siteId) / 100, 2);
    }

    /**
     * Vult een nieuwe creditorder van een Etsy-order aan met de commissie van
     * het origineel. `markAsCancelledWithCredit()` repliceert de order en neemt
     * een gevulde commissie al mee; dit vangt het geval dat het origineel (nog)
     * geen commissie had.
     */
    public static function fillCreditOrder(Order $order): void
    {
        if (! $order->credit_for_order_id || strtolower((string) $order->order_origin) !== 'etsy') {
            return;
        }

        if ($order->getAttribute('etsy_order_commission') !== null || ! self::columnExists()) {
            return;
        }

        $original = Order::query()->find($order->credit_for_order_id);
        if (! $original || strtolower((string) $original->order_origin) !== 'etsy') {
            return;
        }

        $order->etsy_order_commission = $original->etsy_order_commission !== null
            ? abs((float) $original->etsy_order_commission)
            : self::forAmount((float) $original->total, $original->site_id);
    }

    /**
     * Vult Etsy-orders zonder commissie aan. Idempotent: een gevulde waarde wordt
     * nooit overschreven. Eerst de betaalde originelen, daarna hun creditorders
     * (zelfde positieve bedrag als het origineel).
     *
     * @return array{orders: int, credits: int}
     */
    public static function backfill(): array
    {
        $result = ['orders' => 0, 'credits' => 0];

        if (! Schema::hasColumn('dashed__orders', 'etsy_order_commission')) {
            return $result;
        }

        Order::query()
            ->where('order_origin', 'etsy')
            ->whereNull('credit_for_order_id')
            ->whereNull('etsy_order_commission')
            ->isPaid()
            ->select(['id', 'total', 'site_id'])
            ->chunkById(200, function ($orders) use (&$result) {
                foreach ($orders as $order) {
                    $result['orders'] += Order::query()
                        ->whereKey($order->id)
                        ->whereNull('etsy_order_commission')
                        ->update(['etsy_order_commission' => self::forAmount((float) $order->total, $order->site_id)]);
                }
            });

        Order::query()
            ->whereNotNull('credit_for_order_id')
            ->whereNull('etsy_order_commission')
            ->whereIn('credit_for_order_id', Order::query()
                ->where('order_origin', 'etsy')
                ->whereNull('credit_for_order_id')
                ->whereNotNull('etsy_order_commission')
                ->select('id'))
            ->select(['id', 'credit_for_order_id'])
            ->chunkById(200, function ($credits) use (&$result) {
                foreach ($credits as $credit) {
                    $commission = Order::query()->whereKey($credit->credit_for_order_id)->value('etsy_order_commission');

                    $result['credits'] += Order::query()
                        ->whereKey($credit->id)
                        ->whereNull('etsy_order_commission')
                        ->update(['etsy_order_commission' => abs((float) $commission)]);
                }
            });

        return $result;
    }

    private static function columnExists(): bool
    {
        if (self::$columnExists) {
            return true;
        }

        return self::$columnExists = Schema::hasColumn('dashed__orders', 'etsy_order_commission');
    }
}

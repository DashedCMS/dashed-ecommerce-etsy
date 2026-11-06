<?php

namespace Dashed\DashedEcommerceEtsy;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceEtsy\Filament\Widgets\EtsyOrderStats;
use Dashed\DashedEcommerceEtsy\Filament\Pages\Settings\EtsySettingsPage;

class DashedEcommerceEtsyPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-etsy';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->widgets(self::hasEtsyOrders() ? [EtsyOrderStats::class] : [])
            ->pages([EtsySettingsPage::class]);
    }

    /**
     * Dit draait in elk proces, ook op de front-end, en order_origin heeft geen
     * index. Daarom gecachet: de widget verschijnt hooguit een dag na de eerste
     * Etsy-bestelling.
     */
    private static function hasEtsyOrders(): bool
    {
        return rescue(fn () => Cache::remember(
            'dashed-ecommerce-etsy:has-orders',
            now()->addDay(),
            fn () => Schema::hasTable('dashed__orders') && Order::where('order_origin', 'etsy')->exists()
        ), false, false);
    }

    public function boot(Panel $panel): void
    {
    }
}

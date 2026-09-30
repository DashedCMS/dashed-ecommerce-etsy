<?php

namespace Dashed\DashedEcommerceEtsy;

use Filament\Panel;
use Filament\Contracts\Plugin;
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
        $widgets = [];

        if (Schema::hasTable('dashed__orders') && Order::where('order_origin', 'etsy')->exists()) {
            $widgets[] = EtsyOrderStats::class;
        }

        $panel
            ->widgets($widgets)
            ->pages([EtsySettingsPage::class]);
    }

    public function boot(Panel $panel): void
    {
    }
}

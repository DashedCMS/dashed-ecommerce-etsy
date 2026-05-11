<?php

namespace Dashed\DashedEcommerceEtsy;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Dashed\DashedEcommerceEtsy\Filament\Pages\Settings\EtsySettingsPage;

class DashedEcommerceEtsyPlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-etsy';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([EtsySettingsPage::class]);
    }

    public function boot(Panel $panel): void
    {
    }
}

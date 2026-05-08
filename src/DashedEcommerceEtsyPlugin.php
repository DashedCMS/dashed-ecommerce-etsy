<?php

namespace Dashed\DashedEcommerceEtsy;

use Dashed\DashedEcommerceEtsy\Filament\Pages\Settings\EtsySettingsPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

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

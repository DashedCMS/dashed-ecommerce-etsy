<?php

namespace Dashed\DashedEcommerceEtsy;

use Dashed\DashedEcommerceCore\Classes\OrderOrigins;
use Dashed\DashedEcommerceEtsy\Commands\RefreshEtsyToken;
use Dashed\DashedEcommerceEtsy\Commands\SyncOrdersFromEtsyCommand;
use Dashed\DashedEcommerceEtsy\Commands\SyncShipmentsToEtsy;
use Dashed\DashedEcommerceEtsy\Filament\Pages\Settings\EtsySettingsPage;
use Dashed\DashedTranslations\Models\Translation;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedEcommerceEtsyServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-etsy';

    public function bootingPackage(): void
    {
        OrderOrigins::register('etsy', 'Etsy', true);

        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(RefreshEtsyToken::class)->everyThirtyMinutes()->withoutOverlapping();
            $schedule->command(SyncOrdersFromEtsyCommand::class)->everyFiveMinutes()->withoutOverlapping();
            $schedule->command(SyncShipmentsToEtsy::class)->everyFiveMinutes()->withoutOverlapping();
        });

        cms()->registerSettingsPage(EtsySettingsPage::class, 'Etsy', 'shopping-bag', 'Koppel Etsy');

        // Registreer Etsy-velden als custom order fields zodat ze op de
        // order-detail-pagina én op de invoice/packing-slip getoond worden.
        // Net als Bol: keys mappen via snake_case naar de Order-kolommen
        // (etsyReceiptId → etsy_receipt_id, etsyShopId → etsy_shop_id).
        ecommerce()->builder('customOrderFields', [
            'etsyReceiptId' => [
                'label' => Translation::get('etsy-receipt-id', 'etsy-order-fields', 'Etsy receipt-ID'),
                'hideFromCheckout' => true,
                'showOnInvoice' => true,
            ],
            'etsyShopId' => [
                'label' => Translation::get('etsy-shop-id', 'etsy-order-fields', 'Etsy shop-ID'),
                'hideFromCheckout' => true,
                'showOnInvoice' => false,
            ],
        ]);
    }

    public function configurePackage(Package $package): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/oauth.php');

        $package
            ->name('dashed-ecommerce-etsy')
            ->hasCommands([
                SyncOrdersFromEtsyCommand::class,
                RefreshEtsyToken::class,
                SyncShipmentsToEtsy::class,
            ]);

        cms()->builder('plugins', [new DashedEcommerceEtsyPlugin()]);
    }
}

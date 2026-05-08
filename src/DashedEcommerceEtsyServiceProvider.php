<?php

namespace Dashed\DashedEcommerceEtsy;

use Dashed\DashedEcommerceCore\Classes\OrderOrigins;
use Dashed\DashedEcommerceEtsy\Commands\RefreshEtsyToken;
use Dashed\DashedEcommerceEtsy\Commands\SyncOrdersFromEtsyCommand;
use Dashed\DashedEcommerceEtsy\Commands\SyncShipmentsToEtsy;
use Dashed\DashedEcommerceEtsy\Filament\Pages\Settings\EtsySettingsPage;
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

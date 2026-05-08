<?php

namespace Dashed\DashedEcommerceEtsy\Filament\Pages\Settings;

use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class EtsySettingsPage extends Page
{
    use HasSettingsPermission;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Etsy';

    protected string $view = 'dashed-core::settings.pages.default-settings';

    public array $data = [];

    public function mount(): void
    {
        $formData = [];
        foreach (Sites::getSites() as $site) {
            $siteId = (string) $site['id'];
            $formData["etsy_client_id_{$siteId}"] = Customsetting::get('etsy_client_id', $siteId);
            $formData["etsy_client_secret_{$siteId}"] = Customsetting::get('etsy_client_secret', $siteId);
            $formData["etsy_redirect_uri_{$siteId}"] = url('/dashed/etsy/oauth/callback?site_id='.urlencode($siteId));
        }

        $this->form->fill($formData);
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (Sites::getSites() as $site) {
            $siteId = (string) $site['id'];
            $statusText = Etsy::isConnected($siteId)
                ? 'Etsy is gekoppeld voor '.$site['name'].' (shop_id: '.(Etsy::shopId($siteId) ?: '?').')'
                : 'Niet gekoppeld';
            $error = (string) (Customsetting::get('etsy_connection_error', $siteId, '') ?: '');

            $redirectUri = url('/dashed/etsy/oauth/callback?site_id='.urlencode($siteId));

            $tabs[] = Tab::make($siteId)
                ->label(ucfirst($site['name']))
                ->schema([
                    TextEntry::make("etsy_status_{$siteId}_label")
                        ->hiddenLabel()
                        ->state("Etsy voor {$site['name']}")
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make("etsy_status_{$siteId}_value")
                        ->hiddenLabel()
                        ->state($statusText.($error ? "\n".$error : ''))
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextInput::make("etsy_redirect_uri_{$siteId}")
                        ->label('Redirect URI (kopieer naar Etsy app-instellingen)')
                        ->helperText('Voeg deze URL exact toe als "Callback URL" in https://www.etsy.com/developers/your-apps zodat de OAuth-koppeling werkt.')
                        ->default($redirectUri)
                        ->readOnly()
                        ->dehydrated(false)
                        ->extraAttributes(['onclick' => 'this.select()'])
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextInput::make("etsy_client_id_{$siteId}")
                        ->label('Etsy keystring (client_id)')
                        ->maxLength(255),
                    TextInput::make("etsy_client_secret_{$siteId}")
                        ->label('Etsy shared secret')
                        ->password()
                        ->revealable()
                        ->maxLength(255),
                ])
                ->columns(['default' => 1, 'lg' => 2]);
        }

        return $schema->schema([Tabs::make('Sites')->tabs($tabs)])->statePath('data');
    }

    public function submit()
    {
        foreach (Sites::getSites() as $site) {
            $siteId = (string) $site['id'];
            $state = $this->form->getState();
            Customsetting::set('etsy_client_id', $state["etsy_client_id_{$siteId}"] ?? '', $siteId);
            Customsetting::set('etsy_client_secret', $state["etsy_client_secret_{$siteId}"] ?? '', $siteId);
        }

        Notification::make()
            ->title('De Etsy instellingen zijn opgeslagen')
            ->success()
            ->send();

        return redirect(self::getUrl());
    }

    protected function getActions(): array
    {
        return $this->getEtsyConnectActions();
    }

    protected function getHeaderActions(): array
    {
        return $this->getEtsyConnectActions();
    }

    /**
     * @return array<int, Action>
     */
    protected function getEtsyConnectActions(): array
    {
        $actions = [];
        foreach (Sites::getSites() as $site) {
            $siteId = (string) $site['id'];
            $label = Etsy::isConnected($siteId)
                ? 'Opnieuw verbinden ('.$site['name'].')'
                : 'Verbind '.$site['name'].' met Etsy';

            // Plain GET link i.p.v. ->action() omdat Livewire externe
            // redirects niet doorlaat na een action-callback. De
            // EtsyOAuthStartController doet de server-side redirect naar
            // Etsy's consent-pagina.
            $actions[] = Action::make("etsy_connect_{$siteId}")
                ->label($label)
                ->icon('heroicon-o-link')
                ->url(fn (): string => route('dashed.etsy.oauth.start', ['siteId' => $siteId]));

            if (Etsy::isConnected($siteId) && ! Etsy::shopId($siteId)) {
                $actions[] = Action::make("etsy_sync_shop_{$siteId}")
                    ->label('Werk shop_id bij ('.$site['name'].')')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function () use ($siteId, $site) {
                        $shopId = Etsy::syncShopId($siteId);
                        if ($shopId) {
                            Notification::make()
                                ->title('Shop_id opgehaald: '.$shopId)
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Kon shop_id niet ophalen voor '.$site['name'])
                                ->body('Check de connection-error en/of laravel.log voor de API-respons.')
                                ->danger()
                                ->send();
                        }
                    });
            }

            if (Etsy::isConnected($siteId) && Etsy::shopId($siteId)) {
                $actions[] = Action::make("etsy_sync_orders_{$siteId}")
                    ->label('Sync bestellingen ('.$site['name'].')')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Etsy bestellingen nu syncen?')
                    ->modalDescription('Haalt nieuwe receipts op vanaf de laatste-sync-cursor en maakt of update Dashed orders. Bestaande orders worden niet dubbel aangemaakt.')
                    ->modalSubmitActionLabel('Sync nu')
                    ->action(function () use ($siteId, $site) {
                        $result = Etsy::syncOrders($siteId);
                        $imported = (int) ($result['imported'] ?? 0);
                        $errors = $result['errors'] ?? [];

                        if (! empty($errors)) {
                            Notification::make()
                                ->title($site['name'].': '.$imported.' geïmporteerd, '.count($errors).' fouten')
                                ->body(implode("\n", array_slice($errors, 0, 3)))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($site['name'].': '.$imported.' '.($imported === 1 ? 'bestelling' : 'bestellingen').' gesynced')
                            ->success()
                            ->send();
                    });
            }
        }

        return $actions;
    }
}

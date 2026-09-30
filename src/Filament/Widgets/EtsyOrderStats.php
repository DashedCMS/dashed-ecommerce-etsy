<?php

namespace Dashed\DashedEcommerceEtsy\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Classes\CurrencyHelper;
use Dashed\DashedCore\Filament\Pages\Dashboard\Dashboard;

class EtsyOrderStats extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    public ?array $filters = [];

    protected $listeners = [
        'setPageFiltersData',
    ];

    public function mount(): void
    {
        $this->filters = Dashboard::getStartData();
    }

    protected function getHeading(): ?string
    {
        return 'Statistieken vanuit Etsy';
    }

    public function setPageFiltersData($data)
    {
        $this->filters = $data;
    }

    protected function getCards(): array
    {
        $startDate = ($this->filters['startDate'] ?? null) ? Carbon::parse($this->filters['startDate']) : now()->subMonth();
        $endDate = ($this->filters['endDate'] ?? null) ? Carbon::parse($this->filters['endDate']) : now();
        $steps = $this->filters['steps'] ?? 'per_day';

        $formats = Dashboard::getFormatsByStep($steps);
        $startFormat = $formats['startFormat'];
        $endFormat = $formats['endFormat'];

        $etsyOrders = fn () => Order::where('created_at', '>=', $startDate->$startFormat())
            ->where('created_at', '<=', $endDate->$endFormat())
            ->where('order_origin', 'etsy')
            ->isPaidOrReturn();

        // Een creditorder (retour) draagt de commissie van de oorspronkelijke order als positief bedrag,
        // terwijl Etsy die fee bij een retour terugstort: tel hem daarom negatief. Creditorders zijn ook
        // geen nieuwe bestelling.
        $commissie = (float) $etsyOrders()
            ->selectRaw('SUM(CASE WHEN credit_for_order_id IS NULL THEN etsy_order_commission ELSE -ABS(etsy_order_commission) END) AS netto')
            ->value('netto');

        return [
            StatsOverviewWidget\Stat::make('Aantal bestellingen vanuit Etsy', $etsyOrders()->whereNull('credit_for_order_id')->count()),
            StatsOverviewWidget\Stat::make('Omzet vanuit Etsy', CurrencyHelper::formatPrice($etsyOrders()->sum('total'))),
            StatsOverviewWidget\Stat::make('Totale commissie aan Etsy', CurrencyHelper::formatPrice($commissie))
                ->description('Na retouren, excl. btw over de fee'),
        ];
    }
}

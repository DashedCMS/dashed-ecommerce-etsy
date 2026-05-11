<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Illuminate\Console\Command;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;

class SyncOrdersFromEtsyCommand extends Command
{
    protected $signature = 'dashed-etsy:sync-orders {--site= : Optionele site_id om alleen die site te syncen}';

    protected $description = 'Importeer Etsy receipts als Dashed orders';

    public function handle(): int
    {
        $siteIds = $this->option('site')
            ? [(string) $this->option('site')]
            : array_map(fn ($s) => (string) $s['id'], Sites::getSites());

        foreach ($siteIds as $siteId) {
            if (! Etsy::isConnected($siteId)) {
                $this->line("Site {$siteId}: niet gekoppeld, overslaan");

                continue;
            }
            $result = Etsy::syncOrders($siteId);
            $this->line("Site {$siteId}: {$result['imported']} geïmporteerd, ".($result['skipped'] ?? 0).' al bekend, '.count($result['errors']).' fouten');
            foreach ($result['errors'] as $error) {
                $this->warn('  '.$error);
            }
        }

        return self::SUCCESS;
    }
}

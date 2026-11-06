<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;

class SyncShipmentsToEtsy extends Command
{
    protected $signature = 'dashed-etsy:sync-shipments';

    protected $description = 'Push track-&-trace codes van Dashed orders met Etsy origin terug naar Etsy';

    public function handle(): int
    {
        $pending = Etsy::pendingShipmentsQuery()->with('trackAndTraces')->get();

        if ($pending->isEmpty()) {
            $this->info('Geen orders met openstaande T&T-push.');

            return self::SUCCESS;
        }

        foreach ($pending as $order) {
            $ok = Etsy::pushTrackAndTrace($order);
            $this->line("Order #{$order->id} (receipt {$order->etsy_receipt_id}): ".($ok ? 'gepusht' : 'faalde'));
        }

        return self::SUCCESS;
    }
}

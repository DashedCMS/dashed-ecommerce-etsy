<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Dashed\DashedEcommerceEtsy\Classes\Etsy;
use Dashed\DashedEcommerceEtsy\Models\EtsyOrder;
use Illuminate\Console\Command;

class SyncShipmentsToEtsy extends Command
{
    protected $signature = 'dashed-etsy:sync-shipments';

    protected $description = 'Push track-&-trace codes van Dashed orders met Etsy origin terug naar Etsy';

    public function handle(): int
    {
        $pending = EtsyOrder::query()
            ->whereNull('track_and_trace_pushed_at')
            ->whereNull('track_and_trace_error')
            ->with('order.trackAndTraces')
            ->get()
            ->filter(fn (EtsyOrder $eo) => $eo->order && $eo->order->trackAndTraces->isNotEmpty());

        if ($pending->isEmpty()) {
            $this->info('Geen orders met openstaande T&T-push.');

            return self::SUCCESS;
        }

        foreach ($pending as $etsyOrder) {
            $ok = Etsy::pushTrackAndTrace($etsyOrder);
            $this->line("EtsyOrder #{$etsyOrder->id} (receipt {$etsyOrder->etsy_receipt_id}): ".($ok ? 'gepusht' : 'faalde'));
        }

        return self::SUCCESS;
    }
}

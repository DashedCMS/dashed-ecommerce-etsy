<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;
use Illuminate\Console\Command;

class SyncShipmentsToEtsy extends Command
{
    protected $signature = 'dashed-etsy:sync-shipments';

    protected $description = 'Push track-&-trace codes van Dashed orders met Etsy origin terug naar Etsy';

    public function handle(): int
    {
        $pending = Order::query()
            ->whereNotNull('etsy_receipt_id')
            ->whereNull('etsy_track_and_trace_pushed_at')
            ->whereNull('etsy_track_and_trace_error')
            ->with('trackAndTraces')
            ->get()
            ->filter(fn (Order $o) => $o->trackAndTraces->isNotEmpty());

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

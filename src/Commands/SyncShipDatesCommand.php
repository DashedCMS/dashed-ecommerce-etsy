<?php

namespace Dashed\DashedEcommerceEtsy\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceEtsy\Classes\Etsy;

class SyncShipDatesCommand extends Command
{
    protected $signature = 'etsy:sync-ship-dates {--days=60} {--dry-run}';

    protected $description = 'Fetch the Etsy ship-by date for recent open Etsy orders';

    public function handle(): int
    {
        $orders = Order::query()
            ->whereNotNull('etsy_receipt_id')
            ->where('etsy_receipt_id', '!=', '')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereIn('status', ['paid', 'partially_paid'])
            ->where(function ($q) {
                $q->whereNull('fulfillment_status')
                    ->orWhereNotIn('fulfillment_status', Order::DEADLINE_DONE_FULFILLMENT_STATUSES);
            })
            ->whereNull('ship_by_date')
            ->where(function ($q) {
                $q->whereNull('deadline_source')->orWhere('deadline_source', '!=', 'handmatig');
            })
            ->get();

        if ($this->option('dry-run')) {
            $this->info($orders->count().' orders zouden worden bijgewerkt.');

            return self::SUCCESS;
        }

        $updated = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                Etsy::syncShipByDate($order) ? $updated++ : $failed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }

            usleep(250000);
        }

        $this->info("Bijgewerkt: {$updated}, mislukt: {$failed}");

        return self::SUCCESS;
    }
}

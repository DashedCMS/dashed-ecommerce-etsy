<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedEcommerceEtsy\Classes\EtsyCommission;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('dashed__orders', 'etsy_order_commission')) {
            Schema::table('dashed__orders', function (Blueprint $table) {
                $table->decimal('etsy_order_commission', 10, 2)->nullable()->after('etsy_shop_id');
            });
        }

        // Bestaande Etsy-orders (en hun creditorders) krijgen alsnog hun commissie;
        // een al gevulde waarde blijft staan.
        EtsyCommission::backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('dashed__orders', 'etsy_order_commission')) {
            Schema::table('dashed__orders', function (Blueprint $table) {
                $table->dropColumn('etsy_order_commission');
            });
        }
    }
};

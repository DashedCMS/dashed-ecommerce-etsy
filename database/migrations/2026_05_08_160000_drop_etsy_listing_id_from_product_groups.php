<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dashed__product_groups') || ! Schema::hasColumn('dashed__product_groups', 'etsy_listing_id')) {
            return;
        }

        Schema::table('dashed__product_groups', function (Blueprint $table) {
            $table->dropIndex(['etsy_listing_id']);
            $table->dropColumn('etsy_listing_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashed__product_groups')) {
            return;
        }

        Schema::table('dashed__product_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__product_groups', 'etsy_listing_id')) {
                $table->string('etsy_listing_id')->nullable()->after('id');
                $table->index('etsy_listing_id');
            }
        });
    }
};

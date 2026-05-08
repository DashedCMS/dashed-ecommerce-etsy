<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashed__orders', function (Blueprint $table) {
            if (! Schema::hasColumn('dashed__orders', 'etsy_receipt_id')) {
                $table->string('etsy_receipt_id')->nullable()->after('bol_shipment_error');
            }
            if (! Schema::hasColumn('dashed__orders', 'etsy_shop_id')) {
                $table->string('etsy_shop_id')->nullable()->after('etsy_receipt_id');
            }
            if (! Schema::hasColumn('dashed__orders', 'etsy_track_and_trace_pushed_at')) {
                $table->dateTime('etsy_track_and_trace_pushed_at')->nullable()->after('etsy_shop_id');
            }
            if (! Schema::hasColumn('dashed__orders', 'etsy_track_and_trace_error')) {
                $table->text('etsy_track_and_trace_error')->nullable()->after('etsy_track_and_trace_pushed_at');
            }
        });

        // Index voor snelle lookup op etsy_receipt_id (idempotency check tijdens sync)
        if (! self::indexExists('dashed__orders', 'dashed__orders_etsy_receipt_id_index')) {
            Schema::table('dashed__orders', function (Blueprint $table) {
                $table->index('etsy_receipt_id');
            });
        }

        // Migrate data uit pivot-tabel als die nog bestaat
        if (Schema::hasTable('dashed__etsy_orders')) {
            DB::table('dashed__etsy_orders')->orderBy('id')->each(function ($row) {
                DB::table('dashed__orders')
                    ->where('id', $row->order_id)
                    ->update([
                        'etsy_receipt_id' => $row->etsy_receipt_id,
                        'etsy_shop_id' => $row->etsy_shop_id,
                        'etsy_track_and_trace_pushed_at' => $row->track_and_trace_pushed_at,
                        'etsy_track_and_trace_error' => $row->track_and_trace_error,
                    ]);
            });

            Schema::drop('dashed__etsy_orders');
        }
    }

    public function down(): void
    {
        Schema::create('dashed__etsy_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('dashed__orders')->cascadeOnDelete();
            $table->string('etsy_receipt_id')->unique();
            $table->string('etsy_shop_id');
            $table->string('site_id')->nullable();
            $table->dateTime('track_and_trace_pushed_at')->nullable();
            $table->text('track_and_trace_error')->nullable();
            $table->timestamps();
        });

        Schema::table('dashed__orders', function (Blueprint $table) {
            if (Schema::hasColumn('dashed__orders', 'etsy_receipt_id')) {
                $table->dropIndex(['etsy_receipt_id']);
                $table->dropColumn('etsy_receipt_id');
            }
            foreach (['etsy_shop_id', 'etsy_track_and_trace_pushed_at', 'etsy_track_and_trace_error'] as $column) {
                if (Schema::hasColumn('dashed__orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private static function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'select count(*) as c from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ?',
            [$database, $table, $index]
        );

        return ((int) ($rows[0]->c ?? 0)) > 0;
    }
};

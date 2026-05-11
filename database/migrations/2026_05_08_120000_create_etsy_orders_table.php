<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('dashed__etsy_orders');
    }
};

<?php

namespace Dashed\DashedEcommerceEtsy\Models;

use Dashed\DashedEcommerceCore\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtsyOrder extends Model
{
    protected $table = 'dashed__etsy_orders';

    protected $guarded = [];

    protected $casts = [
        'track_and_trace_pushed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}

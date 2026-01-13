<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSplit extends Model
{
    protected $fillable = [
        'payment_id',
        'seller_id',
        'amount',
        'status',
        'disbursed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}

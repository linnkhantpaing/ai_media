<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['details'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'details' => 'encrypted:array', 'completed_at' => 'immutable_datetime'];
    }
}

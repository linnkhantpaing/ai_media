<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'is_current' => 'boolean'];
    }
}

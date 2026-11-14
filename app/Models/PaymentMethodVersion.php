<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethodVersion extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['definition'];

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'number' => 'integer'];
    }
}

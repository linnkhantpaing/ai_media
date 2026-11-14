<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['details', 'qr_path', 'identity_hash'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array', 'instructions' => 'array', 'revision' => 'integer', 'sort_order' => 'integer'];
    }
}

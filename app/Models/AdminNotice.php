<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'revision' => 'integer', 'received_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}

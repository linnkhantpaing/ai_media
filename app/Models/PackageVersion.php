<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'number' => 'integer'];
    }
}

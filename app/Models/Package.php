<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'revision' => 1, 'published_version' => 0];

    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerPackageAssignment::class);
    }

    protected function casts(): array
    {
        return ['definition' => 'array', 'revision' => 'integer', 'published_version' => 'integer'];
    }
}

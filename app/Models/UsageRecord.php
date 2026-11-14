<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsageRecord extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'project_snapshot' => 'array', 'package_snapshot' => 'array', 'period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'allowance_start' => 'immutable_datetime', 'reserved_seconds' => 'integer', 'charged_seconds' => 'integer', 'returned_seconds' => 'integer', 'reserved_videos' => 'integer', 'charged_videos' => 'integer', 'returned_videos' => 'integer', 'storage_bytes' => 'integer', 'revision' => 'integer'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    public function code(): string
    {
        return 'USG-'.str_pad((string) $this->id, 8, '0', STR_PAD_LEFT);
    }
}

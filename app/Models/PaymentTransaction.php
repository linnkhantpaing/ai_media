<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['receiving_snapshot', 'transfer', 'reference_hash', 'reference_lookup', 'approved_reference_hash', 'internal_note'];

    protected function casts(): array
    {
        return ['package_snapshot' => 'array', 'receiving_snapshot' => 'encrypted:array', 'transfer' => 'encrypted:array', 'internal_note' => 'encrypted', 'expected_minor' => 'integer', 'received_minor' => 'integer', 'revision' => 'integer', 'expires_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }
}

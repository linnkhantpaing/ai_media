<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Purchased processing minutes for one workflow, valid for one allowance period. */
class UsageCredit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['seconds' => 'integer', 'allowance_start' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'package_snapshot' => 'array'];
    }
}

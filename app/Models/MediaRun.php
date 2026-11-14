<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaRun extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['artifacts'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'lines' => 'array', 'stages' => 'array', 'artifacts' => 'array', 'cancel_requested' => 'boolean', 'progress' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MediaProject::class, 'media_project_id');
    }
}

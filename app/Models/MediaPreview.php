<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaPreview extends Model
{
    use HasFactory,HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'lines' => 'array', 'options' => 'array', 'result' => 'array', 'progress' => 'integer', 'cancel_requested' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MediaProject::class, 'media_project_id');
    }
}

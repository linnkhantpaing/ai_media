<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTask extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stages' => 'array', 'history_available' => 'boolean', 'progress' => 'integer',
            'attempts' => 'integer', 'max_attempts' => 'integer',
            'queued_at' => 'datetime', 'ready_at' => 'datetime', 'started_at' => 'datetime',
            'attempt_started_at' => 'datetime', 'finished_at' => 'datetime', 'next_retry_at' => 'datetime',
            'processing_seconds' => 'float', 'queue_wait_seconds' => 'float',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MediaProject::class, 'media_project_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(MediaRun::class, 'media_run_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(MediaWorker::class, 'media_worker_id');
    }
}

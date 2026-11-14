<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaWorker extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['automatic' => 'boolean', 'process_id' => 'integer', 'queues' => 'array', 'capacity' => 'integer', 'heartbeat_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    public function currentTask(): BelongsTo
    {
        return $this->belongsTo(MediaTask::class, 'current_task_id');
    }
}

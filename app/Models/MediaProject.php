<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaProject extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['source_path', 'output_path', 'audio_path', 'subtitle_path', 'fingerprint', 'client_id'];

    protected function casts(): array
    {
        return ['storage_policy' => 'array', 'source_expires_at' => 'datetime', 'artifacts_expires_at' => 'datetime', 'source_expired_at' => 'datetime', 'metadata' => 'array', 'settings' => 'array', 'lines' => 'array', 'expected_bytes' => 'integer', 'uploaded_bytes' => 'integer', 'imported_bytes' => 'integer', 'import_cancel_requested' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(MediaRun::class);
    }

    public function activeRun(): BelongsTo
    {
        return $this->belongsTo(MediaRun::class, 'active_run_id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(MediaPreview::class);
    }

    public function busy(): bool
    {
        return in_array($this->status, ['importing', 'validating', 'queued', 'analyzing', 'translating', 'rendering', 'cancel_requested'], true) || $this->previews()->whereIn('status', ['queued', 'running'])->exists();
    }
}

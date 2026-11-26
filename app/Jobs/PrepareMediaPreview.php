<?php

namespace App\Jobs;

use App\Exceptions\MediaFailure;
use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Services\AiUsage;
use App\Services\CustomerAccounts;
use App\Services\Media\MediaPreviews;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareMediaPreview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 1860;

    public function __construct(public string $previewId)
    {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->previewId;
    }

    public function handle(MediaPreviews $service): void
    {
        $preview = MediaPreview::find($this->previewId);
        if (! $preview || in_array($preview->status, ['completed', 'cancelled'], true)) {
            return;
        }
        $project = MediaProject::find($preview->media_project_id);
        app(AiUsage::class)->begin($project, null, 'preview');
        if (! $project || ! app(CustomerAccounts::class)->canProcess($project->user_id)) {
            $preview->update(['status' => 'failed', 'error_code' => 'account_unavailable']);

            return;
        }
        try {
            $service->process($preview);
        } catch (MediaFailure $failure) {
            if ($failure->retryable && $this->job && $this->attempts() < $this->tries && ! $preview->fresh()->cancel_requested) {
                $preview->refresh();
                $preview->update(['status' => 'queued', 'error_code' => $failure->reason, 'result' => [...($preview->result ?? []), 'retryAt' => now()->addSeconds($failure->retryAfter ?? 30)->toIso8601String()]]);
                $this->release($failure->retryAfter ?? 30);

                return;
            }
            $preview->update(['status' => $failure->reason === 'cancelled' ? 'cancelled' : 'failed', 'error_code' => $failure->reason]);
        } catch (Throwable) {
            $preview->update(['status' => 'failed', 'error_code' => 'processing_failed']);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MediaPreview::whereKey($this->previewId)->whereNotIn('status', ['completed', 'cancelled'])->update(['status' => 'failed', 'error_code' => 'processing_failed']);
    }
}

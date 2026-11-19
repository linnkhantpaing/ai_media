<?php

namespace App\Jobs;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Services\CustomerAccounts;
use App\Services\Media\MediaInspector;
use App\Services\Media\MediaStorage;
use App\Services\Media\OperationsTelemetry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InspectMediaProject implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 960;

    public function __construct(public string $projectId)
    {
        $this->onQueue('media-inspect');
    }

    public function uniqueId(): string
    {
        return $this->projectId;
    }

    public function handle(MediaInspector $inspector, MediaStorage $storage): void
    {
        $project = MediaProject::find($this->projectId);
        if (! $project || $project->status !== 'validating') {
            return;
        }
        if (! app(CustomerAccounts::class)->canProcess($project->user_id)) {
            $project->update(['status' => 'invalid', 'error_code' => 'account_unavailable']);

            return;
        }
        $report = function (int $percent) use ($project): void {
            MediaProject::whereKey($project->id)->where('status', 'validating')->update(['source_progress' => $percent]);
            if ($current = MediaProject::find($project->id)) {
                app(OperationsTelemetry::class)->syncSource($current);
            }
        };
        try {
            $report($project->source_type === 'youtube' ? 90 : 1);
            $metadata = $inspector->inspect($project, fn (int $percent) => $report($project->source_type === 'youtube' ? 90 + (int) floor($percent * 0.04) : max(1, (int) floor($percent * 0.9))));
            $report(94);
            $storage->publish($project, $project->source_path, $metadata['mime']);
            $report(98);
            $storage->publish($project, 'media/'.$project->id.'/poster.jpg', 'image/jpeg');
            MediaProject::whereKey($project->id)->where('status', 'validating')->update([
                'status' => 'ready', 'source_progress' => 100, 'error_code' => null, 'import_stage' => $project->source_type === 'youtube' ? 'completed' : null, 'source_mime' => $metadata['mime'],
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        } catch (MediaFailure $error) {
            MediaProject::whereKey($project->id)->where('status', 'validating')->update(['status' => 'invalid', 'error_code' => $error->reason]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MediaProject::whereKey($this->projectId)->where('status', 'validating')->update(['status' => 'invalid', 'error_code' => 'inspection_failed']);
    }
}

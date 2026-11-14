<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveStorageSettingsRequest;
use App\Jobs\RunMediaCleanup;
use App\Services\Media\MediaStorage;
use App\Services\Media\RetentionCleanup;
use App\Services\Media\StorageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class StorageSettingsController extends Controller
{
    public function __construct(private StorageSettings $settings, private RetentionCleanup $cleanup) {}

    public function show(): Response
    {
        return Inertia::render('settings/storage', ['frame' => 'storageRetention', 'configuration' => $this->settings->present(), 'recommended' => $this->settings->baselinePolicy(), 'usage' => $this->usageData(), 'automation' => $this->cleanup->automation(), 'history' => $this->cleanup->history(), 'linksStorage' => ['retry' => route('admin.settings.storage.retry', ['run' => '__RUN__'], absolute: false), ...collect(['save', 'test', 'usage', 'preview', 'cleanup', 'history'])->mapWithKeys(fn (string $name) => [$name => route('admin.settings.storage.'.$name, absolute: false)])->all()]]);
    }

    public function save(SaveStorageSettingsRequest $request): JsonResponse
    {
        $this->settings->save($request->validated(), $request->user('admin')->id);

        return response()->json(['configuration' => $this->settings->present(), 'usage' => $this->usageData(), 'automation' => $this->cleanup->automation()])->header('Cache-Control', 'no-store');
    }

    public function test(SaveStorageSettingsRequest $request): JsonResponse
    {
        $connection = $this->settings->candidate($request->validated('connection'));

        return response()->json(['report' => $this->settings->check($connection, $request->user('admin')->id)])->header('Cache-Control', 'no-store');
    }

    public function usage(): JsonResponse
    {
        Cache::forget('media-local-usage');

        return response()->json(['usage' => $this->usageData(), 'automation' => $this->cleanup->automation()]);
    }

    private function usageData(): array
    {
        $committed = app(MediaStorage::class)->reservedBytes();
        $tracked = (int) DB::table('media_files')->where('state', '!=', 'deleted')->sum('bytes');
        $reserved = max(0, $committed - $tracked);
        $budget = $this->settings->policy()['budget'];
        $local = Cache::remember('media-local-usage', 60, function (): ?int {
            try {
                $disk = Storage::disk('local');
                $files = $disk->allFiles('media');
                if (count($files) > 50000) {
                    return null;
                }$bytes = 0;
                foreach ($files as $file) {
                    $bytes += $disk->size($file);
                }

                return $bytes;
            } catch (\Throwable) {
                return null;
            }
        });

        return ['tracked' => $tracked, 'reserved' => $reserved, 'committed' => $committed, 'available' => max(0, $budget - $committed), 'budget' => $budget, 'local' => $local, 'measuredAt' => now()->toIso8601String()];
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['preview' => $this->cleanup->preview($request->user('admin')->id)]);
    }

    public function cleanup(Request $request): JsonResponse
    {
        $data = $request->validate(['preview' => ['required', 'uuid'], 'confirm' => ['required', 'accepted']]);
        $id = $this->cleanup->confirm($data['preview'], $request->user('admin')->id);
        RunMediaCleanup::dispatch($id)->afterCommit();

        return response()->json(['runId' => $id], 202);
    }

    public function history(): JsonResponse
    {
        return response()->json(['history' => $this->cleanup->history()]);
    }

    public function retry(Request $request, string $run): JsonResponse
    {
        $row = DB::table('media_cleanup_runs')->find($run);
        abort_unless($row && in_array($row->status, ['partial', 'failed'], true), 409);
        $failed = array_column(array_filter(json_decode($row->results ?? '[]', true), fn (array $r) => $r['status'] === 'failed'), 'id');
        $candidates = array_values(array_filter(json_decode($row->candidates, true), fn (array $r) => in_array($r['id'], $failed, true)));
        $id = $this->cleanup->createRun($candidates, $request->user('admin')->id);
        RunMediaCleanup::dispatch($id)->afterCommit();

        return response()->json(['runId' => $id], 202);
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\MediaFailure;
use App\Http\Requests\SaveProviderSettingsRequest;
use App\Services\AiUsage;
use App\Services\Media\AutomaticMediaWorker;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaBackground;
use App\Services\Media\MediaRuntime;
use App\Services\Media\OperationsMonitor;
use App\Services\Media\ProviderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProviderSettingsController extends Controller
{
    public function __construct(private ProviderSettings $settings) {}

    public function show(): Response
    {
        return Inertia::render('settings/providers', [
            'frame' => 'apiKeysModels', 'provider' => $this->settings->present(), 'health' => $this->healthData(),
            'providerLinks' => collect(['save', 'test', 'models', 'health'])->mapWithKeys(fn (string $action): array => [$action => route('admin.settings.providers.'.$action, absolute: false)]),
        ]);
    }

    public function save(SaveProviderSettingsRequest $request): JsonResponse
    {
        $configuration = $this->settings->draft($request->validated());
        $this->settings->save($configuration, $request->integer('revision'), $request->user('admin')->id);

        app(AutomaticMediaWorker::class)->wake();

        return response()->json(['provider' => $this->settings->present(), 'health' => $this->healthData()])->header('Cache-Control', 'no-store');
    }

    public function models(SaveProviderSettingsRequest $request): JsonResponse
    {
        try {
            return response()->json(['models' => $this->settings->refreshModels($this->settings->draft($request->validated(), false))]);
        } catch (MediaFailure $failure) {
            return response()->json(['code' => $failure->reason], 422);
        }
    }

    public function test(SaveProviderSettingsRequest $request, GeminiClient $gemini, MediaRuntime $runtime): JsonResponse
    {
        $configuration = $this->settings->draft($request->validated());
        $capability = $request->input('capability', 'text');
        $directory = 'provider-checks';
        $relative = $directory.'/'.Str::uuid().'.wav';
        $sample = null;
        try {
            $this->settings->withConfiguration($configuration, function () use ($gemini, $runtime, $capability, $configuration, $relative, &$sample): void {
                app(AiUsage::class)->begin(null, null, 'check');
                if ($capability === 'speech') {
                    Storage::disk('local')->makeDirectory(dirname($relative));
                    $file = Storage::disk('local')->path($relative);
                    $gemini->speech("\u{1019}\u{1004}\u{103A}\u{1039}\u{1002}\u{101C}\u{102C}\u{1015}\u{102B}\u{104B} AI Media \u{1019}\u{103E} \u{1000}\u{103C}\u{102D}\u{102F}\u{1006}\u{102D}\u{102F}\u{1015}\u{102B}\u{1010}\u{101A}\u{103A}\u{104B}", $configuration['voice'], 'Speak clearly in natural Burmese.', $file.'.raw');
                    $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-i', $file.'.raw', '-af', 'atempo='.$configuration['speech_rate'].',volume='.$configuration['voice_volume'], '-ar', '48000', $file], timeout: 45);
                    $probe = $runtime->probe($file);
                    if (! collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio') || ($probe['format']['duration'] ?? 0) <= 0) {
                        throw new MediaFailure('provider_response_invalid');
                    }
                    $sample = $relative;
                } else {
                    $result = $gemini->translate([['index' => 0, 'start' => 0, 'end' => 4, 'source_text' => 'Hello. Welcome to our video studio.']], 'A studio greeting.');
                    if (($result['translations'][0]['index'] ?? null) !== 0 || ! is_string($result['translations'][0]['text'] ?? null) || trim($result['translations'][0]['text']) === '') {
                        throw new MediaFailure('provider_response_invalid');
                    }
                }
            });
            $report = $this->settings->report($configuration, $capability, 'success', null, $request->user('admin')->id);
            if ($sample) {
                $token = (string) Str::uuid();
                Cache::put('provider-sample:'.$token, ['user' => $request->user('admin')->id, 'path' => $sample], now()->addMinutes(10));
                $report['sampleUrl'] = URL::temporarySignedRoute('admin.settings.providers.sample', now()->addMinutes(10), ['sample' => $token], absolute: false);
            }

            return response()->json(['report' => $report])->header('Cache-Control', 'no-store');
        } catch (MediaFailure $failure) {
            Storage::disk('local')->delete($relative);

            return response()->json(['report' => $this->settings->report($configuration, $capability, 'failed', $failure->reason, $request->user('admin')->id), 'retryAfter' => $failure->retryAfter]);
        } finally {
            Storage::disk('local')->delete($relative.'.raw');
            foreach (Storage::disk('local')->files($directory) as $path) {
                if (Storage::disk('local')->lastModified($path) < now()->subMinutes(15)->timestamp) {
                    Storage::disk('local')->delete($path);
                }
            }
        }
    }

    public function sample(Request $request, string $sample): BinaryFileResponse
    {
        $file = Cache::get('provider-sample:'.$sample);
        abort_unless($file && $file['user'] === $request->user('admin')->id && Storage::disk('local')->exists($file['path']), 404);

        return response()->file(Storage::disk('local')->path($file['path']), ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }

    public function health(Request $request): JsonResponse
    {
        return response()->json($this->healthData($request->boolean('recheck')));
    }

    private function healthData(bool $recheck = false): array
    {
        $background = app(MediaBackground::class);
        $ready = $recheck ? $background->diagnose() : $background->available();
        $workers = collect(app(OperationsMonitor::class)->health()['workers'])->filter(fn (array $worker): bool => $worker['stoppedAt'] === null);
        $online = $workers->whereIn('state', ['idle', 'busy'])->count();

        return [
            'automation' => app(AutomaticMediaWorker::class)->status(),
            'background' => ['ready' => $ready, 'engine' => 'Audio Separator (UVR)', 'model' => 'UVR-MDX-NET-Inst_HQ_3', 'checkedAt' => now()->toIso8601String()],
            'workers' => ['total' => $workers->count(), 'online' => $online, 'busy' => $workers->where('state', 'busy')->count(), 'offline' => $workers->count() - $online, 'checkedAt' => now()->toIso8601String()],
        ];
    }
}

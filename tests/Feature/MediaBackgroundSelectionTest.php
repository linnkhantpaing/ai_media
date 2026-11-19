<?php

namespace Tests\Feature;

use App\Exceptions\MediaFailure;
use App\Models\MediaProject;
use App\Services\Media\MediaBackground;
use App\Services\Media\MediaRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaBackgroundSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        Http::preventStrayRequests();
        if (! app(MediaRuntime::class)->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
    }

    public function test_movie_plan_uses_only_included_ranges_and_maps_reordered_overlapping_scenes(): void
    {
        $plan = MediaBackground::plan([
            ['start' => 90, 'end' => 95], ['start' => 10, 'end' => 15],
            ['start' => 13, 'end' => 18], ['start' => 20, 'end' => 80, 'included' => false],
            ['start' => 10, 'end' => 15],
        ], 100, true);
        $this->assertSame([['start' => 10.0, 'end' => 18.0, 'offset' => 0.0], ['start' => 90.0, 'end' => 95.0, 'offset' => 8.0]], $plan);
        $this->assertSame(10.0, MediaBackground::offset($plan, 92));
        $this->assertSame(3.0, MediaBackground::offset($plan, 13));
        $this->expectException(MediaFailure::class);
        MediaBackground::offset($plan, 30);
    }

    public function test_translation_plan_keeps_all_original_audio_including_gaps(): void
    {
        $plan = MediaBackground::plan([['start' => 30, 'end' => 40]], 120, false);
        $this->assertSame([['start' => 0.0, 'end' => 120.0, 'offset' => 0.0]], $plan);
        $this->assertSame(119.5, MediaBackground::offset($plan, 119.5));
    }

    public function test_selected_audio_includes_real_edge_context_but_publishes_only_the_used_seconds_and_reuses_cache(): void
    {
        $project = $this->fixture();
        $ranges = MediaBackground::plan([['start' => 78, 'end' => 80], ['start' => 0, 'end' => 2]], 80, true);
        $progress = [];
        $file = app(MediaBackground::class)->obtain($project, fn (): bool => false, $ranges, function (int $value) use (&$progress): void {
            $progress[] = $value;
        });
        $this->assertEqualsWithDelta(4, $this->duration($file), 0.01);
        $calls = file(Storage::disk('local')->path('calls.log'), FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $calls);
        foreach ($calls as $bytes) {
            $this->assertLessThan(9 * 44100 * 4, (int) $bytes, 'Only the two-second clip plus six seconds of real neighboring audio is decoded.');
            $this->assertGreaterThan(7 * 44100 * 4, (int) $bytes);
        }
        $this->assertContains(52, $progress);
        $this->assertSame(100, end($progress));
        $this->assertGreaterThan(220, $this->tone($file, 0.5, 220));
        $this->assertGreaterThan(220, $this->tone($file, 2.5, 440));
        $this->assertSame($file, app(MediaBackground::class)->obtain($project, fn (): bool => false, $ranges));
        $this->assertCount(2, file(Storage::disk('local')->path('calls.log')));
        $this->assertSame([], glob(dirname($file).'/input-*'));
        $this->assertSame([], glob(dirname($file).'/*.partial.wav'));
    }

    public function test_completed_chunks_survive_a_later_engine_failure_and_retry_only_missing_work(): void
    {
        $project = $this->fixture();
        $flag = Storage::disk('local')->path('fail-second');
        touch($flag);
        $ranges = MediaBackground::plan([['start' => 0, 'end' => 2], ['start' => 78, 'end' => 80]], 80, true);
        try {
            app(MediaBackground::class)->obtain($project, fn (): bool => false, $ranges);
            $this->fail('The second synthetic engine call must fail.');
        } catch (MediaFailure $failure) {
            $this->assertSame('background_unavailable', $failure->reason);
        }
        $this->assertCount(1, glob(Storage::disk('local')->path('media/'.$project->id.'/background/chunk-*.wav')));
        unlink($flag);
        $file = app(MediaBackground::class)->obtain($project, fn (): bool => false, $ranges);
        $this->assertEqualsWithDelta(4, $this->duration($file), 0.01);
        $this->assertCount(3, file(Storage::disk('local')->path('calls.log')), 'Retry must reuse the first verified chunk.');
    }

    public function test_cancellation_stops_before_starting_the_separation_engine(): void
    {
        $project = $this->fixture();
        try {
            app(MediaBackground::class)->obtain($project, fn (): bool => true, MediaBackground::plan([['start' => 0, 'end' => 2]], 80, true));
            $this->fail('Cancellation must be respected.');
        } catch (MediaFailure $failure) {
            $this->assertSame('cancelled', $failure->reason);
        }
        $this->assertFileDoesNotExist(Storage::disk('local')->path('calls.log'));
    }

    public function test_installed_uvr_processes_two_context_windows_with_one_loaded_model_and_live_progress(): void
    {
        if (! getenv('MEDIA_UVR_SMOKE')) {
            $this->markTestSkipped('Set MEDIA_UVR_SMOKE=1 for the installed local UVR smoke check.');
        }
        $project = $this->fixture();
        config(['media.separation_command' => null]);
        $this->assertTrue(app(MediaBackground::class)->available());
        $ranges = MediaBackground::plan([['start' => 0, 'end' => 2], ['start' => 78, 'end' => 80]], 80, true);
        $progress = [];
        $file = app(MediaBackground::class)->obtain($project, fn (): bool => false, $ranges, function (int $value) use (&$progress): void {
            $progress[] = $value;
        });
        $this->assertEqualsWithDelta(4, $this->duration($file), 0.01);
        $this->assertSame(100, end($progress));
        $this->assertNotEmpty(array_filter($progress, fn (int $percent): bool => $percent > 10 && $percent < 94));
        $this->assertCount(2, glob(dirname($file).'/chunk-*.wav'));
    }

    private function fixture(): MediaProject
    {
        $project = MediaProject::factory()->create(['storage_disk' => 'local', 'metadata' => ['duration' => 80, 'width' => 160, 'height' => 120, 'audio' => true]]);
        $local = Storage::disk('local');
        $local->makeDirectory(dirname($project->source_path));
        app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'aevalsrc=0.1*sin(2*PI*if(lt(t\,40)\,220\,440)*t):s=44100:d=80', '-ac', '2', '-f', 'wav', $local->path($project->source_path)]);
        $script = $local->path('engine.php');
        file_put_contents($script, '<?php file_put_contents($argv[3],filesize($argv[1]).PHP_EOL,FILE_APPEND); if(is_file($argv[4]) && count(file($argv[3]))===2){exit(7);} copy($argv[1],$argv[2]);');
        config(['media.separation_command' => json_encode([PHP_BINARY, $script, '{input}', '{output}', $local->path('calls.log'), $local->path('fail-second')])]);

        return $project;
    }

    private function duration(string $path): float
    {
        return (float) app(MediaRuntime::class)->probe($path)['format']['duration'];
    }

    private function tone(string $path, float $start, int $frequency): float
    {
        $output = app(MediaRuntime::class)->execute('ffmpeg', ['-v', 'error', '-ss', (string) $start, '-t', '0.2', '-i', $path, '-f', 's16le', '-ac', '1', '-ar', '8000', 'pipe:1']);
        $samples = array_values(unpack('v*', $output));
        $sum = 0.0;
        foreach ($samples as $index => $sample) {
            $sum += ($sample > 32767 ? $sample - 65536 : $sample) * sin(2 * M_PI * $frequency * $index / 8000);
        }

        return abs($sum / count($samples));
    }
}

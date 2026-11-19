<?php

namespace Tests\Feature;

use App\Exceptions\MediaAnalysisDeferred;
use App\Exceptions\MediaFailure;
use App\Jobs\ProcessMediaRun;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaPipeline;
use App\Services\Media\MediaRuntime;
use App\Services\Media\MediaStorage;
use App\Services\Media\RecapPlanner;
use App\Services\UsageLedger;
use App\Support\MediaSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaAnalysisPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private ?Process $server = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.media_disk' => 'local', 'media.gemini_key' => 'synthetic-provider-key', 'media.analysis_concurrency' => 3]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        parent::tearDown();
    }

    public function test_real_parallel_http_is_faster_and_preserves_all_twenty_two_chapters(): void
    {
        $url = $this->provider();
        $elapsed = [];
        foreach ([1, 3] as $concurrency) {
            config(['media.analysis_concurrency' => $concurrency]);
            [$planner, $run, $directory] = $this->planner(5137.229);
            $started = microtime(true);
            $lines = $planner->analyze($run, $directory, fn () => null);
            $elapsed[$concurrency] = microtime(true) - $started;
            $catalog = $run->fresh()->artifacts['catalog'];
            $this->assertCount(22, $catalog);
            $this->assertEquals(range(0, 5040, 240), array_column($catalog, 'start'));
            $this->assertEqualsWithDelta(5137.229, $catalog[21]['end'], .001);
            $this->assertSame('Meaningful full-story narration', $lines[0]['text']);
            $this->assertEquals(180, array_sum(array_column($lines, 'planned_duration')));
        }
        $metrics = Http::get($url.'/metrics')->json();
        $this->assertSame(3, $metrics['peak']);
        $this->assertLessThan($elapsed[1] * .7, $elapsed[3], json_encode($elapsed));
        fwrite(STDOUT, sprintf("\n22-chapter synthetic HTTP benchmark: sequential %.2fs; parallel %.2fs (%.2fx).\n", $elapsed[1], $elapsed[3], $elapsed[1] / $elapsed[3]));
    }

    public function test_one_failed_chapter_retains_other_results_and_resume_only_requests_missing_chapters(): void
    {
        $url = $this->provider(503);
        [$planner, $run, $directory] = $this->planner(960);
        try {
            $planner->analyze($run, $directory, fn () => null);
            $this->fail('The simulated unavailable chapter must fail.');
        } catch (MediaFailure $failure) {
            $this->assertSame('provider_unavailable', $failure->reason);
        }
        $this->assertSame([1, 2], array_keys($run->fresh()->artifacts['catalog']));
        $this->assertFalse($run->artifacts['plan_complete'] ?? false);
        $lines = $planner->analyze($run->fresh(), $directory, fn () => null);
        $this->assertNotEmpty($lines);
        $metrics = Http::get($url.'/metrics')->json();
        $this->assertEquals(['0' => 2, '240' => 1, '480' => 1, '720' => 1], $metrics['chapters']);
        $this->assertCount(4, $run->fresh()->artifacts['catalog']);
    }

    public function test_rate_limit_drains_successful_requests_then_honors_cooldown_without_key_rotation(): void
    {
        $url = $this->provider(429);
        [$planner, $run, $directory] = $this->planner(960);
        foreach ([1, 2] as $attempt) {
            try {
                $planner->analyze($run->fresh(), $directory, fn () => null);
                $this->fail('A provider cooldown must stop further requests.');
            } catch (MediaFailure $failure) {
                $this->assertSame('provider_rate_limit', $failure->reason);
                $this->assertGreaterThan(0, $failure->retryAfter);
            }
        }
        $metrics = Http::get($url.'/metrics')->json();
        $this->assertSame(3, array_sum($metrics['chapters']));
        $this->assertCount(2, $run->fresh()->artifacts['catalog']);
    }

    public function test_cancellation_interrupts_an_in_flight_provider_request(): void
    {
        $this->provider(delay: 5000);
        $start = microtime(true);
        try {
            app(GeminiClient::class)->structuredAsync([['type' => 'text', 'text' => 'Synthetic data']], ['type' => 'object'], function () use ($start): void {
                if (microtime(true) - $start > .2) {
                    throw new MediaFailure('cancelled');
                }
            })->wait();
            $this->fail('Cancellation must abort the pending request.');
        } catch (MediaFailure $failure) {
            $this->assertSame('cancelled', $failure->reason);
        }
        $this->assertLessThan(3, microtime(true) - $start);
    }

    public function test_analysis_continuation_releases_queue_without_charging_or_spending_failure_attempt(): void
    {
        [, $run] = $this->planner(500);
        $usage = app(UsageLedger::class)->reserve($run);
        $pipeline = Mockery::mock(MediaPipeline::class);
        $pipeline->shouldReceive('check')->once();
        $pipeline->shouldReceive('analyze')->once()->andThrow(new MediaAnalysisDeferred);
        $queue = Mockery::mock(Job::class);
        $queue->shouldReceive('release')->once()->with(1);
        $job = new ProcessMediaRun($run->id);
        $job->setJob($queue);
        $job->handle($pipeline);
        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(0, $run->artifacts['processing_attempts_analysis']);
        $this->assertSame(1, $run->artifacts['analysis_continuations']);
        $this->assertSame('reserved', $usage->fresh()->status);
        $this->assertNull($run->finished_at);
    }

    public function test_translation_resume_does_not_skip_missing_lines_in_a_partially_completed_batch(): void
    {
        [$planner, $run, $directory] = $this->planner(60, false);
        $events = array_map(fn (int $i): array => ['start' => $i * 2, 'end' => $i * 2 + 1, 'source_text' => 'Spoken line'], range(0, 29));
        $run->update(['artifacts' => ['catalog_chunk_seconds' => 60, 'catalog' => [['summary' => 'Whole context', 'start' => 0, 'end' => 60, 'events' => $events]]], 'lines' => [29 => ['subtitle_text' => 'Only the last line was saved']]]);
        Http::fake(function ($request) {
            $rows = json_decode($request['input'][1]['text'], true);

            return Http::response($this->providerOutput(['translations' => array_map(fn (array $line): array => ['index' => $line['index'], 'text' => 'Translated line'], $rows)]));
        });
        $lines = $planner->analyze($run->fresh(), $directory, fn () => null);
        $this->assertCount(30, $lines);
        $this->assertCount(30, array_filter($lines, fn (array $line): bool => ($line['text'] ?? '') === 'Translated line'));
        Http::assertSentCount(1);
    }

    public function test_real_clock_proxy_preserves_duration_audio_and_source_while_bounding_timestamps(): void
    {
        $runtime = app(MediaRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('FFmpeg is required.');
        }
        [, $run, $directory] = $this->planner(4);
        $project = $run->project;
        $source = Storage::disk('local')->path($project->source_path);
        $runtime->execute('ffmpeg', ['-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=24', '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=16000', '-t', '4', '-c:v', 'libx264', '-c:a', 'aac', '-threads', '2', $source]);
        $hash = hash_file('sha256', $source);
        Http::fake(function ($request) {
            $schema = $request['response_format']['schema'];
            $key = isset($schema['properties']['events']) ? 'events' : 'scenes';
            $this->assertEquals(4, $schema['properties'][$key]['items']['properties']['end']['maximum']);
            $entry = ['start' => 0, 'end' => 3, 'source_text' => 'Actual action'];
            $value = $key === 'events' ? ['summary' => 'Actual story', 'events' => [$entry]] : ['scenes' => [[...$entry, 'text' => 'Narration', 'subtitle_text' => 'Subtitle', 'planned_duration' => 3]]];

            return Http::response($this->providerOutput($value));
        });
        $planner = new RecapPlanner($runtime, app(GeminiClient::class), app(MediaStorage::class));
        $planner->analyze($run, $directory, fn () => null);
        $proxy = $runtime->probe($directory.'/chapter-clock-v1-0-4000.mp4');
        $this->assertEqualsWithDelta(4, (float) $proxy['format']['duration'], .1);
        $video = collect($proxy['streams'])->firstWhere('codec_type', 'video');
        $this->assertSame(512, $video['width']);
        $this->assertSame('1/1', $video['avg_frame_rate']);
        $this->assertSame('4', $video['nb_frames']);
        $this->assertNotNull(collect($proxy['streams'])->firstWhere('codec_type', 'audio'));
        $this->assertSame($hash, hash_file('sha256', $source));
    }

    public function test_slice_deadline_interrupts_waiting_and_can_resume_without_marking_the_plan_complete(): void
    {
        $this->provider(delay: 5000);
        config(['media.analysis_slice_seconds' => 1]);
        [$planner, $run, $directory] = $this->planner(240);
        $started = microtime(true);
        try {
            $planner->analyze($run, $directory, fn () => null);
            $this->fail('The analysis slice should yield before the worker timeout.');
        } catch (MediaAnalysisDeferred) {
            $this->assertLessThan(3, microtime(true) - $started);
            $this->assertFalse($run->fresh()->artifacts['plan_complete'] ?? false);
        }
    }

    public function test_transient_failure_budget_resets_after_progress_but_stops_after_three_stalled_attempts(): void
    {
        [, $run] = $this->planner(500);
        $run->update(['artifacts' => ['analysis_failure_checkpoint' => '1:0', 'analysis_failures_without_progress' => 2, 'processing_attempts_analysis' => 9]]);
        $usage = app(UsageLedger::class)->reserve($run);
        $pipeline = Mockery::mock(MediaPipeline::class);
        $pipeline->shouldReceive('check')->times(3);
        $pipeline->shouldReceive('analyze')->times(3)->andReturnUsing(function (MediaRun $current): void {
            $current->update(['artifacts' => [...$current->artifacts, 'catalog' => [['summary' => 'First complete chapter'], ['summary' => 'Second complete chapter']]]]);
            throw new MediaFailure('provider_unavailable', true);
        });
        $queue = Mockery::mock(Job::class);
        $queue->shouldReceive('release')->once()->with(15);
        $queue->shouldReceive('release')->once()->with(45);
        foreach ([1, 2, 3] as $attempt) {
            $job = new ProcessMediaRun($run->id);
            $job->setJob($queue);
            $job->handle($pipeline);
            $run->refresh();
            $this->assertSame($attempt, $run->artifacts['analysis_failures_without_progress']);
            $this->assertSame($attempt === 3 ? 'failed' : 'retry_waiting', $run->status);
            $this->assertSame($attempt === 3 ? 'released' : 'reserved', $usage->fresh()->status);
        }
    }

    private function planner(float $duration, bool $movie = true): array
    {
        $project = MediaProject::factory()->create(['metadata' => ['duration' => $duration, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => $movie ? 'movie' : 'subtitles', 'mode' => $movie ? 'dub' : 'subs', 'target_seconds' => 180])]);
        Storage::disk('local')->put($project->source_path, 'synthetic source');
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'settings' => $project->settings]);
        $project->update(['active_run_id' => $run->id]);
        $runtime = Mockery::mock(MediaRuntime::class);
        $runtime->shouldReceive('execute')->andReturnUsing(function (string $tool, array $arguments): string {
            file_put_contents(end($arguments), $arguments[array_search('-ss', $arguments) + 1]);

            return '';
        });
        $directory = Storage::disk('local')->path('analysis-'.$run->id);
        mkdir($directory);

        return [new RecapPlanner($runtime, app(GeminiClient::class), app(MediaStorage::class)), $run, $directory];
    }

    private function provider(int $failure = 0, int $delay = 140): string
    {
        $script = <<<'JS'
const http=require('node:http');let active=0,peak=0;const chapters={};
const output=value=>({steps:[{type:'model_output',content:[{type:'text',text:JSON.stringify(value)}]}]});
const server=http.createServer((req,res)=>{
 if(req.url==='/metrics'){res.end(JSON.stringify({peak,chapters}));return;}
 let body='';req.on('data',chunk=>body+=chunk);req.on('end',()=>{
  const data=JSON.parse(body),schema=data.response_format.schema,chapter=!!schema.properties?.events;
  const offset=chapter?Number(Buffer.from(data.input[1].data,'base64').toString()):null;
  if(chapter)chapters[offset]=(chapters[offset]||0)+1;
  active++;peak=Math.max(peak,active);
  setTimeout(()=>{
   active--;res.setHeader('Content-Type','application/json');
   if(chapter&&offset===0&&chapters[offset]===1&&FAILURE){res.statusCode=FAILURE;res.setHeader('Retry-After','30');res.end('{}');return;}
   if(chapter){res.end(JSON.stringify(output({summary:'Actual chapter '+offset,events:[{start:1,end:10,source_text:'Synthetic visible story event'}]})));return;}
   if(schema.properties?.scenes){res.end(JSON.stringify(output({scenes:[{start:1,end:10,source_text:'Actual event',text:'Meaningful full-story narration',subtitle_text:'Meaningful subtitle',planned_duration:180}]})));return;}
   res.end(JSON.stringify(output({ok:true})));
  },DELAY+(offset===0?50:0));
 });
});server.listen(0,'127.0.0.1',()=>console.log(server.address().port));
JS;
        $script = str_replace(['FAILURE', 'DELAY'], [(string) $failure, (string) $delay], $script);
        $this->server = new Process(['node', '-e', $script], base_path(), timeout: 30);
        $this->server->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($this->server->getOutput(), "\n") && $this->server->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $port = (int) trim($this->server->getOutput());
        $this->assertGreaterThan(0, $port, $this->server->getErrorOutput());
        $url = 'http://127.0.0.1:'.$port;
        Http::allowStrayRequests([$url.'/*']);
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withUri($request->getUri()->withScheme('http')->withHost('127.0.0.1')->withPort($port)));

        return $url;
    }

    private function providerOutput(array $value): array
    {
        return ['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($value)]]]]];
    }
}

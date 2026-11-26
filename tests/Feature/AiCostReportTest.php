<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AiCostReport;
use App\Services\AiCostSettings;
use App\Services\AiUsage;
use App\Services\Media\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiCostReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['media.gemini_key' => 'test-key', 'media.gemini_model' => 'gemini-test', 'media.gemini_tts_model' => 'gemini-tts-test']);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function event(array $values): void
    {
        DB::table('ai_usage_events')->insert([...['user_id' => null, 'media_project_id' => null, 'media_run_id' => null, 'context' => 'analysis', 'operation' => 'watch', 'model' => 'gemini-test',
            'input_tokens' => 0, 'audio_input_tokens' => 0, 'cached_tokens' => 0, 'output_tokens' => 0, 'thought_tokens' => 0, 'total_tokens' => 0, 'created_at' => now()], ...$values]);
    }

    private function prices(): void
    {
        app(AiCostSettings::class)->save($this->admin, ['revision' => 0, 'storage_gb_month' => '0.015', 'usd_mmk' => '4000', 'thb_mmk' => '120',
            'models' => ['gemini-test' => ['input' => '1', 'audio_input' => '2', 'cached' => null, 'output' => '10']]]);
    }

    public function test_every_gemini_call_records_its_tokens_against_the_customer_and_video(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $project = MediaProject::factory()->create(['user_id' => $customer->id]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'phase' => 'analysis']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"translations":[{"index":0,"text":"Hi"}]}']]]],
            'usage' => ['total_input_tokens' => 1200, 'total_output_tokens' => 80, 'total_thought_tokens' => 20, 'total_cached_tokens' => 100, 'total_tokens' => 1300,
                'input_tokens_by_modality' => [['modality' => 'text', 'tokens' => 900], ['modality' => 'audio', 'tokens' => 300]]]])]);

        app(AiUsage::class)->begin($project, $run, 'analysis');
        app(GeminiClient::class)->translate([['index' => 0, 'text' => 'Hello']], 'story');

        $row = DB::table('ai_usage_events')->sole();
        $this->assertSame([$customer->id, $project->id, $run->id, 'analysis', 'translate', 'gemini-test', 1200, 300, 100, 80, 20, 1300],
            [(int) $row->user_id, $row->media_project_id, $row->media_run_id, $row->context, $row->operation, $row->model, (int) $row->input_tokens, (int) $row->audio_input_tokens,
                (int) $row->cached_tokens, (int) $row->output_tokens, (int) $row->thought_tokens, (int) $row->total_tokens]);

        // A queue job must not leak its context into the next job.
        app(AiUsage::class)->reset();
        app(GeminiClient::class)->translate([['index' => 0, 'text' => 'Hello']], 'story');
        $this->assertNull(DB::table('ai_usage_events')->orderByDesc('id')->first()->user_id);
    }

    public function test_report_estimates_mmk_per_customer_video_and_step_with_revenue_and_wasted_cost(): void
    {
        $this->prices();
        $good = User::factory()->create(['is_admin' => false, 'name' => 'Paying']);
        $bad = User::factory()->create(['is_admin' => false, 'name' => 'Heavy']);
        $exported = MediaProject::factory()->create(['user_id' => $good->id, 'title' => 'Done', 'status' => 'completed', 'output_path' => 'media/x/recap.mp4', 'settings' => ['workflow' => 'movie', 'mode' => 'dub']]);
        $abandoned = MediaProject::factory()->create(['user_id' => $bad->id, 'title' => 'Never exported', 'status' => 'awaiting_review', 'output_path' => null, 'settings' => ['mode' => 'subs']]);
        // 1M text input = $1, 100k output = $1 → $2 → 8000 Ks
        $this->event(['user_id' => $good->id, 'media_project_id' => $exported->id, 'input_tokens' => 1_000_000, 'output_tokens' => 100_000, 'total_tokens' => 1_100_000]);
        // 1M audio input = $2 → 8000 Ks, wasted (never exported)
        $this->event(['user_id' => $bad->id, 'media_project_id' => $abandoned->id, 'operation' => 'transcribe', 'input_tokens' => 1_000_000, 'audio_input_tokens' => 1_000_000, 'total_tokens' => 1_000_000]);
        // admin API test is shown but not charged to a customer
        $this->event(['context' => 'check', 'operation' => 'translate', 'input_tokens' => 1_000_000, 'total_tokens' => 1_000_000]);
        PaymentTransaction::factory()->create(['user_id' => $good->id, 'status' => 'approved', 'currency' => 'USD', 'expected_minor' => 500, 'received_minor' => 500, 'approved_at' => now()]);

        $report = app(AiCostReport::class)->build(['range' => '7']);

        $this->assertEqualsWithDelta(20000, $report['summary']['ai_mmk'], 0.01);
        $this->assertEqualsWithDelta(4000, $report['summary']['check_mmk'], 0.01);
        $this->assertEqualsWithDelta(8000, $report['summary']['wasted_mmk'], 0.01);
        $this->assertEqualsWithDelta(20000, $report['summary']['revenue_mmk'], 0.01);
        $customers = collect($report['customers']['data'])->keyBy('name');
        $this->assertEqualsWithDelta(8000, $customers['Paying']['ai_mmk'], 0.01);
        $this->assertEqualsWithDelta(12000, $customers['Paying']['profit_mmk'], 0.01);
        $this->assertEqualsWithDelta(-8000, $customers['Heavy']['profit_mmk'], 0.01);
        $videos = collect($report['videos']['data'])->keyBy('title');
        $this->assertSame(['completed', 'movie'], [$videos['Done']['state'], $videos['Done']['workflow']]);
        $this->assertSame('not_exported', $videos['Never exported']['state']);
        $this->assertSame(['check', 'movie_watch', 'transcribe'], collect($report['stages'])->pluck('key')->sort()->values()->all());
        $this->assertSame([], $report['pricing']['missing_models']);
        $this->assertCount(7, $report['daily']);
    }

    public function test_old_calls_roll_into_daily_totals_and_stay_in_the_report(): void
    {
        $this->prices();
        $this->travelTo(now()->subDays(100));
        $this->event(['input_tokens' => 1_000_000, 'total_tokens' => 1_000_000, 'created_at' => now()]);
        $this->travelBack();
        $this->event(['input_tokens' => 500_000, 'total_tokens' => 500_000]);

        $this->assertSame(1, app(AiUsage::class)->prune());
        $this->assertSame(1, DB::table('ai_usage_events')->count());
        $this->assertSame(1_000_000, (int) DB::table('ai_usage_daily')->sole()->input_tokens);
        $this->assertSame(0, app(AiUsage::class)->prune());

        $report = app(AiCostReport::class)->build(['range' => 'custom', 'from' => now()->subDays(110)->toDateString(), 'to' => now()->toDateString()]);
        $this->assertSame(1_500_000, $report['summary']['input_tokens']);
    }

    public function test_admin_page_prices_and_project_detail(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        $project = MediaProject::factory()->create(['user_id' => $customer->id]);
        $this->event(['user_id' => $customer->id, 'media_project_id' => $project->id, 'operation' => 'speech', 'context' => 'generate', 'input_tokens' => 10, 'output_tokens' => 500, 'total_tokens' => 510]);

        $this->actingAs($customer)->get('/admin/reports/ai-costs')->assertRedirect();
        $this->actingAs($this->admin, 'admin')->get('/admin/reports/ai-costs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/ai-costs')
            ->where('report.pricing.configured', false)->where('report.summary.total_tokens', 510)->where('pricing.known_models', fn ($models) => collect($models)->contains('gemini-test')));
        $this->putJson('/admin/reports/ai-costs/settings', ['revision' => 0, 'models' => ['gemini-test' => ['input' => '-1']], 'storage_gb_month' => '0.015'])->assertUnprocessable();
        $this->putJson('/admin/reports/ai-costs/settings', ['revision' => 0, 'models' => ['gemini-test' => ['input' => '1', 'output' => '10']], 'storage_gb_month' => '0.015', 'usd_mmk' => '4000'])
            ->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('models.gemini-test.output', '10');
        $this->putJson('/admin/reports/ai-costs/settings', ['revision' => 0, 'models' => [], 'storage_gb_month' => '0.015'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->getJson('/admin/reports/ai-costs?range=30')->assertOk()->assertJsonPath('pricing.configured', true)->assertJsonCount(30, 'daily');
        $this->getJson('/admin/reports/ai-costs/projects/'.$project->id)->assertOk()->assertJsonPath('calls.0.stage', 'narration_voice')->assertJsonPath('total_tokens', 510);
    }
}

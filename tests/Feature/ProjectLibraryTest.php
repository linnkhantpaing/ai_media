<?php

namespace Tests\Feature;

use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Models\User;
use App\Support\MediaSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        config(['filesystems.media_disk' => 'local']);
        $this->actingAs(User::factory()->create());
    }

    public function test_filters_and_counts_are_owner_scoped_before_status_selection(): void
    {
        $mine = ['user_id' => auth()->id(), 'source_type' => 'youtube', 'settings' => [...MediaSettings::DEFAULTS, 'mode' => 'dub']];
        foreach (['ready', 'awaiting_review', 'rendering', 'completed', 'failed'] as $status) {
            MediaProject::factory()->create([...$mine, 'title' => 'Story '.$status, 'status' => $status]);
        }
        MediaProject::factory()->create(['title' => 'Story private', 'status' => 'completed']);
        MediaProject::factory()->create(['user_id' => auth()->id(), 'title' => 'Story other mode', 'status' => 'completed']);
        $this->get('/projects?q=Story&mode=dub&source=youtube&status=completed')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')->where('frame', 'completed')->has('projects.data', 1)
            ->where('projects.total', 1)->where('counts.all', 5)->where('counts.draft', 1)
            ->where('counts.awaiting_review', 1)->where('counts.processing', 1)->where('counts.completed', 1)->where('counts.needs_attention', 1)
            ->where('filters.q', 'Story')->where('filters.source', 'youtube')->where('filters.mode', 'dub'));
        if (getenv('PROJECT_LIBRARY_UI_CAPTURE')) {
            $this->withVite();
            file_put_contents(sys_get_temp_dir().'/project-library-qa.html', $this->get('/projects')->getContent());
        }
    }

    public function test_search_matches_filenames_and_treats_sql_wildcards_literally(): void
    {
        $wanted = MediaProject::factory()->create(['user_id' => auth()->id(), 'title' => 'အမည်', 'source_name' => 'Story_100%.mp4']);
        MediaProject::factory()->create(['user_id' => auth()->id(), 'source_name' => 'StoryZ100000.mp4']);
        $this->get('/projects?q='.urlencode('_100%'))->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('projects.data.0.id', $wanted->id));
        $this->get('/projects?q='.urlencode('အမည်'))->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('projects.data.0.id', $wanted->id));
    }

    public function test_updated_date_filters_use_bangkok_boundaries_including_custom_end_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
        foreach (['2026-11-09 16:59:59', '2026-11-09 17:00:00', '2026-11-10 16:59:59', '2026-11-10 17:00:00'] as $date) {
            MediaProject::factory()->create(['user_id' => auth()->id(), 'updated_at' => $date]);
        }
        $this->get('/projects?date=today')->assertInertia(fn (Assert $page) => $page->where('projects.total', 2)->where('counts.all', 2)->where('libraryTimezone', 'Asia/Bangkok'));
        $this->get('/projects?date=custom&from=2026-11-10&to=2026-11-10')->assertInertia(fn (Assert $page) => $page->where('projects.total', 2));
        $this->getJson('/projects?date=custom&from=2026-11-10&to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/projects?date=custom')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_sorting_pagination_and_out_of_range_pages_remain_valid(): void
    {
        MediaProject::factory()->count(13)->create(['user_id' => auth()->id(), 'title' => 'Zulu']);
        $first = MediaProject::factory()->create(['user_id' => auth()->id(), 'title' => 'Alpha']);
        $this->get('/projects?sort=title&view=list')->assertInertia(fn (Assert $page) => $page
            ->where('projects.total', 14)->has('projects.data', 12)->where('projects.data.0.id', $first->id)
            ->where('filters.view', 'list')->where('projects.from', 1)->where('projects.to', 12));
        $this->get('/projects?sort=title&view=list&page=999')->assertInertia(fn (Assert $page) => $page
            ->where('projects.current_page', 2)->where('projects.last_page', 2)->has('projects.data', 2));
        $this->get('/projects?q=missing&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('projects.current_page', 1)->where('projects.total', 0)->has('projects.data', 0));
    }

    public function test_invalid_library_inputs_are_rejected_without_returning_private_configuration(): void
    {
        foreach (['mode=bogus', 'source=remote', 'sort=sql', 'view=bad', 'page=-1', 'q[]=bad', 'date=bad'] as $query) {
            $this->getJson('/projects?'.$query)->assertUnprocessable();
        }
        $this->get('/projects?status=unknown')->assertNotFound();
        $this->get('/projects?status[]=completed')->assertNotFound();
        $this->getJson('/projects?q='.str_repeat('a', 251))->assertUnprocessable();
    }

    public function test_library_returns_saved_script_presence_and_actual_output_freshness_without_full_scripts(): void
    {
        $lines = [['index' => 0, 'start' => 0, 'end' => 1, 'text' => 'မင်္ဂလာပါ', 'source_text' => 'Hello']];
        $project = MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'completed', 'lines' => $lines]);
        $run = MediaRun::factory()->for($project, 'project')->create(['phase' => 'generate', 'status' => 'completed', 'lines' => $lines, 'finished_at' => now()]);
        $project->update(['output_path' => 'media/'.$project->id.'/runs/'.$run->id.'/recap.mp4', 'subtitle_path' => 'media/'.$project->id.'/runs/'.$run->id.'/recap.srt']);
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.outputState', 'current')->where('projects.data.0.changesPending', false)
            ->where('projects.data.0.hasScript', true)->where('projects.data.0.hasOutput', true)
            ->missing('projects.data.0.lines')->missing('projects.data.0.fingerprint')->missing('projects.data.0.sourceUrl')
            ->missing('projects.data.0.output_path')->missing('runtime.key'));
        $project->update(['status' => 'awaiting_review']);
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page->where('projects.data.0.outputState', 'current'));
        $project->update(['lines' => [[...$lines[0], 'text' => 'ပြင်ပြီး']]]);
        $this->get('/projects')->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.outputState', 'previous')->where('projects.data.0.changesPending', true)->where('projects.data.0.hasSubtitles', true));
        $project->update(['status' => 'failed']);
        $this->get('/projects?status=needs_attention')->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('projects.data.0.outputState', 'previous'));
    }

    public function test_rename_only_updates_owned_metadata_and_is_blocked_during_work(): void
    {
        $project = MediaProject::factory()->create(['user_id' => auth()->id()]);
        $original = $project->only(['settings', 'lines', 'source_path', 'output_path']);
        $this->patchJson('/media/projects/'.$project->id, ['title' => '  Better title  '])->assertOk()->assertJsonPath('project.title', 'Better title');
        $this->assertSame($original, $project->fresh()->only(array_keys($original)));
        Queue::assertNothingPushed();
        $this->patchJson('/media/projects/'.$project->id, ['title' => '  '])->assertUnprocessable();
        $project->update(['status' => 'rendering']);
        $this->patchJson('/media/projects/'.$project->id, ['title' => 'Busy'])->assertConflict();
        $this->assertSame('Better title', $project->fresh()->title);
        $other = MediaProject::factory()->create();
        $this->patchJson('/media/projects/'.$other->id, ['title' => 'No access'])->assertNotFound();
    }

    public function test_srt_only_and_source_ingestion_are_not_presented_as_generated_video(): void
    {
        MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'completed', 'subtitle_path' => 'media/example/subtitles.srt', 'settings' => [...MediaSettings::DEFAULTS, 'output' => 'srt']]);
        $this->get('/projects?status=completed')->assertInertia(fn (Assert $page) => $page
            ->where('projects.data.0.hasOutput', false)->where('projects.data.0.hasSubtitles', true)->where('projects.data.0.outputState', 'unknown'));
        MediaProject::factory()->create(['user_id' => auth()->id(), 'status' => 'importing', 'source_type' => 'youtube']);
        $this->get('/projects?status=draft')->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 1)->where('projects.data.0.status', 'importing')->where('projects.data.0.hasOutput', false)->where('projects.data.0.outputState', 'none'));
    }
}

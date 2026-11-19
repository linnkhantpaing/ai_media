<?php

namespace Tests\Feature;

use App\Models\MediaPreview;
use App\Models\MediaProject;
use App\Models\MediaRun;
use App\Services\Media\GeminiClient;
use App\Services\Media\MediaPreviews;
use App\Support\MediaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewSceneIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['filesystems.media_disk' => 'local', 'operations.auto_launch_allowed' => false]);
    }

    public function test_reordered_scene_identifiers_notes_and_text_survive_save_and_reload(): void
    {
        $project = $this->project();
        $rows = array_reverse($project->lines);
        foreach ($rows as $i => &$row) {
            $row['index'] = $i;
        }
        unset($row);
        $response = $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $rows]);
        $response->assertOk()->assertJsonPath('project.lines.0.scene_id', 'scene-b')->assertJsonPath('project.lines.0.note', 'Note B')->assertJsonPath('project.lines.1.text', 'Narration A');
        $this->assertSame($rows, $project->fresh()->lines);
        $this->assertSame($rows, $project->fresh()->activeRun->lines);
        $this->getJson('/media/projects/'.$project->id)->assertOk()->assertJsonPath('project.lines.1.note', 'Note A');
    }

    public function test_duplicate_or_malformed_scene_identifiers_cannot_be_saved(): void
    {
        $project = $this->project();
        $rows = $project->lines;
        $rows[1]['scene_id'] = 'scene-a';
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $rows])->assertUnprocessable()->assertJsonValidationErrors('lines.0.scene_id');
        $rows[1]['scene_id'] = '<script>';
        $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $rows])->assertUnprocessable()->assertJsonValidationErrors('lines.1.scene_id');
        $this->assertSame('scene-b', $project->fresh()->lines[1]['scene_id']);
    }

    public function test_legacy_scenes_receive_unique_ids_without_losing_notes(): void
    {
        $project = $this->project();
        $rows = array_map(function ($row) {
            unset($row['scene_id']);

            return $row;
        }, $project->lines);
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $rows])->assertOk();
        $saved = $project->fresh()->lines;
        $this->assertNotSame($saved[0]['scene_id'], $saved[1]['scene_id']);
        $this->assertSame('Note A', $saved[0]['note']);
    }

    public function test_rewrite_keeps_scene_identity_and_uses_the_subtitle_output_language(): void
    {
        config(['media.gemini_key' => 'test-key']);
        $project = $this->project();
        $settings = MediaSettings::normalize(['workflow' => 'subtitles', 'mode' => 'subs', 'narration_lang' => 'my', 'subtitle_lang' => 'th']);
        $preview = MediaPreview::factory()->create(['media_project_id' => $project->id, 'kind' => 'rewrite', 'settings' => $settings, 'lines' => $project->lines, 'options' => ['line' => 1, 'action' => 'shorten']]);
        $gemini = \Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('structured')->once()->withArgs(function ($parts, $schema): bool {
            return str_contains($parts[0]['text'], 'Language code: th.') && str_contains($parts[1]['text'], 'scene-b');
        })->andReturn(['text' => 'Short translated text']);
        $this->app->instance(GeminiClient::class, $gemini);
        app(MediaPreviews::class)->process($preview);
        $this->assertSame('scene-b', $preview->fresh()->result['sceneId']);
        $this->assertSame('Narration B', $project->fresh()->lines[1]['text']);
        $this->assertSame('Note B', $project->fresh()->lines[1]['note']);
    }

    public function test_track_switches_are_validated_and_retained_with_each_scene(): void
    {
        $project = $this->project();
        $rows = $project->lines;
        $rows[0]['audio_muted'] = true;
        $rows[0]['subtitle_hidden'] = true;
        $rows[1]['audio_muted'] = false;
        $rows[1]['subtitle_hidden'] = false;
        $this->actingAs($project->user)->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->lines)), 'lines' => $rows])
            ->assertOk()->assertJsonPath('project.lines.0.audio_muted', true)->assertJsonPath('project.lines.0.subtitle_hidden', true);
        $this->assertSame($rows, $project->fresh()->lines);
        $this->assertSame($rows, $project->fresh()->activeRun->lines);
        $rows[1]['audio_muted'] = 'not-a-boolean';
        $this->patchJson('/media/projects/'.$project->id.'/scenes', ['revision' => hash('sha256', json_encode($project->fresh()->lines)), 'lines' => $rows])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.1.audio_muted');
    }

    private function project(): MediaProject
    {
        $row = ['scene_id' => 'scene-a', 'index' => 0, 'start' => 0, 'end' => 10, 'source_text' => 'Source A', 'text' => 'Narration A', 'subtitle_text' => 'Subtitle A', 'planned_duration' => 20, 'included' => true, 'locked' => false, 'note' => 'Note A'];
        $rows = [$row, [...$row, 'scene_id' => 'scene-b', 'index' => 1, 'start' => 200, 'end' => 220, 'text' => 'Narration B', 'note' => 'Note B']];
        $project = MediaProject::factory()->create(['status' => 'awaiting_review', 'metadata' => ['duration' => 300, 'width' => 320, 'height' => 240, 'audio' => true], 'settings' => MediaSettings::normalize(['workflow' => 'movie', 'mode' => 'dub', 'target_seconds' => 60]), 'lines' => $rows]);
        $run = MediaRun::factory()->create(['media_project_id' => $project->id, 'status' => 'awaiting_review', 'settings' => $project->settings, 'lines' => $rows]);
        $project->update(['active_run_id' => $run->id]);

        return $project;
    }
}

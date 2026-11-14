<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_renders_the_inertia_page_for_an_authenticated_owner(): void
    {
        $this->withoutVite();
        config(['app.name' => 'AI Media']);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->get(route('workspace'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace')
            ->where('app.name', 'ZATLAN')
            ->where('links.workspace', '/')
        );
    }
}

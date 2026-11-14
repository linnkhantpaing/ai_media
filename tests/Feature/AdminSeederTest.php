<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['admin.seed' => [
            'enabled' => true,
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => '12345678',
        ]]);
    }

    public function test_seeded_admin_can_sign_in_and_is_stored_with_a_password_hash(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertSame('Test Admin', $admin->name);
        $this->assertNotSame('12345678', $admin->password);
        $this->assertTrue(Hash::check('12345678', $admin->password));

        $this->post('/login', ['email' => 'admin@example.com', 'password' => '12345678'])
            ->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_reseeding_does_not_duplicate_the_admin_or_reset_a_changed_password(): void
    {
        $this->seed(AdminSeeder::class);
        $admin = User::query()->firstOrFail();
        $admin->password = 'ChangedPassword!123';
        $admin->save();
        $newHash = $admin->password;

        $this->seed(AdminSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($newHash, $admin->fresh()->password);
        $this->assertTrue(Hash::check('ChangedPassword!123', $admin->fresh()->password));
    }

    public function test_development_admin_seeding_cannot_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);

        $this->app->make(AdminSeeder::class)->run();
    }
}

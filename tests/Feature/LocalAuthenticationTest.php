<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Hash;

class LocalAuthenticationTest extends TestCase
{
    public function createApplication(): Application
    {
        // Avoid the legacy Tests\CreatesApplication trait, which resets a configured database.
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        $this->withoutVite();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function user(?string $password = 'test-password-123'): User
    {
        Role::firstOrCreate(['id' => Role::USER], ['name' => 'user']);
        $user = new User(['name' => 'Local User', 'email' => 'local@example.com', 'role_id' => Role::USER]);
        $user->password = $password === null ? null : Hash::make($password);
        $user->save();

        return $user;
    }

    public function test_login_page_works_before_locale_is_configured(): void
    {
        $this->get('/login')->assertOk()->assertSee('Email Address');
        $this->get('/beheer')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_password_login_and_post_logout(): void
    {
        $user = $this->user();
        $this->post('/login', ['email' => $user->email, 'password' => 'test-password-123', 'remember' => true])
            ->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
        $this->get('/home')->assertOk();
        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_invalid_password_is_rejected_and_attempts_are_throttled(): void
    {
        $user = $this->user();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_accounts_without_a_local_password_cannot_log_in(): void
    {
        $user = $this->user(null);
        $this->post('/login', ['email' => $user->email, 'password' => 'anything'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_migration_preserves_existing_user_fields(): void
    {
        $user = $this->user();
        $user->solis_id = 'legacy-id';
        $user->allowed_attributes = 'legacy-attributes';
        $user->save();
        $migration = require __DIR__.'/../../database/migrations/2026_10_27_000000_add_local_authentication_to_users.php';
        $migration->up();
        $this->assertSame('legacy-id', $user->fresh()->solis_id);
        $this->assertSame('legacy-attributes', $user->fresh()->allowed_attributes);
        $this->assertTrue(Hash::check('test-password-123', $user->fresh()->password));
    }

    public function test_regular_users_cannot_access_admin_pages(): void
    {
        $this->actingAs($this->user())->get('/usertable')->assertForbidden();
    }

    public function test_command_creates_an_admin_with_a_hashed_password(): void
    {
        $this->artisan('app:local-user', ['email' => 'admin@example.com', '--name' => 'Admin', '--admin' => true])
            ->expectsQuestion('Password (at least 12 characters)', 'admin-password-123')
            ->expectsQuestion('Confirm password', 'admin-password-123')
            ->assertExitCode(0);
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('admin-password-123', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
    }

    public function test_password_reset_preserves_existing_role_and_rejects_mismatches(): void
    {
        $user = $this->user();
        $this->artisan('app:local-user', ['email' => $user->email])
            ->expectsQuestion('Password (at least 12 characters)', 'replacement-password')
            ->expectsQuestion('Confirm password', 'mismatching-password')
            ->assertExitCode(1);
        $this->assertTrue(Hash::check('test-password-123', $user->fresh()->password));

        $this->artisan('app:local-user', ['email' => $user->email])
            ->expectsQuestion('Password (at least 12 characters)', 'replacement-password')
            ->expectsQuestion('Confirm password', 'replacement-password')
            ->assertExitCode(0);
        $this->assertSame(Role::USER, $user->fresh()->role_id);
        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }
}

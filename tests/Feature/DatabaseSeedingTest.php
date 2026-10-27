<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceSample;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The documented fresh install (php artisan migrate --seed) and the admin
 * account it makes: no password in the code, and seeding again never resets it.
 */
class DatabaseSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'solicode.co.uk@gmail.com';

    protected function tearDown(): void
    {
        unset($_SERVER['ADMIN_SEED_PASSWORD'], $_ENV['ADMIN_SEED_PASSWORD']);

        parent::tearDown();
    }

    public function test_the_full_seed_runs_on_a_fresh_database(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(User::query()->where('email', 'test@example.com')->exists(), 'testing gets the test user');
        $this->assertTrue((bool) User::query()->where('email', self::ADMIN_EMAIL)->value('is_admin'));
        $this->assertGreaterThan(0, VoiceSample::query()->count(), 'voice samples are seeded after the HD voices');
        // VoiceSeeder's "Marcus" (Schedar) keeps the name; the hybrid voice of the same name is left out.
        $this->assertSame('en-Gemini-HD-Schedar', Voice::query()->where('language', 'en')->where('display_name', 'Marcus')->value('internal_voice_id'));
        $this->assertFalse(Voice::query()->where('internal_voice_id', 'gemini-en-marcus')->exists());
        $this->assertTrue(Voice::query()->where('internal_voice_id', 'gemini-en-serene')->exists());
    }

    public function test_servers_get_no_test_user(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertFalse(User::query()->where('email', 'test@example.com')->exists());
        $this->assertTrue(User::query()->where('email', self::ADMIN_EMAIL)->exists());
    }

    public function test_the_admin_password_comes_from_the_environment_and_seeding_again_keeps_it(): void
    {
        $_SERVER['ADMIN_SEED_PASSWORD'] = 'first-seed-password';
        $this->seed(AdminSeeder::class);

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('first-seed-password', $admin->password));

        $_SERVER['ADMIN_SEED_PASSWORD'] = 'second-seed-password';
        $this->seed(AdminSeeder::class);

        $this->assertTrue(Hash::check('first-seed-password', $admin->fresh()->password), 'an existing admin keeps their password');
        $this->assertSame(1, User::query()->where('email', self::ADMIN_EMAIL)->count());
    }

    public function test_without_a_password_a_random_one_is_printed_once(): void
    {
        Artisan::call('db:seed', ['--class' => AdminSeeder::class]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/shown only this once: (\S+)/', $output);
        preg_match('/shown only this once: (\S+)/', $output, $match);
        $this->assertGreaterThanOrEqual(20, strlen($match[1]));
        $this->assertTrue(Hash::check($match[1], (string) User::query()->where('email', self::ADMIN_EMAIL)->value('password')));

        Artisan::call('db:seed', ['--class' => AdminSeeder::class]);
        $this->assertStringNotContainsString('shown only this once', Artisan::output());
    }

    public function test_the_printed_password_is_exactly_the_password_whatever_its_symbols(): void
    {
        // The console reads \< and \> as markup: printed raw, these lost their backslash.
        foreach (['7uhK,T<;fhq\\>H)6W|4TcDQ|', 'i\\<}X9$<jc$ft*59uN)d~T/(', 'a</b>c<info>d'] as $password) {
            $output = new \Symfony\Component\Console\Output\BufferedOutput;
            $output->writeln(AdminSeeder::passwordLine($password));

            $this->assertStringEndsWith('shown only this once: '.$password.PHP_EOL, $output->fetch());
        }
    }

    public function test_an_account_that_already_has_the_admin_email_is_left_as_it_is(): void
    {
        $existing = User::factory()->create(['email' => self::ADMIN_EMAIL, 'password' => 'their-own-password']);

        $this->seed(AdminSeeder::class);

        $existing->refresh();
        $this->assertFalse((bool) $existing->is_admin, 'seeding never makes someone else\'s sign-up an admin');
        $this->assertTrue(Hash::check('their-own-password', $existing->password));
    }
}

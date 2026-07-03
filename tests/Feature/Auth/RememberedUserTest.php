<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\RememberedUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use SystemX\Core\Preferences\PreferencesService;
use Tests\TestCase;

class RememberedUserTest extends TestCase
{
    use RefreshDatabase;

    private function aUser(): User
    {
        return User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ]);
    }

    public function test_cookie_carries_the_users_look_name_and_email(): void
    {
        $user = $this->aUser();

        $cookie = RememberedUser::cookie($user, [
            'theme' => 'dark',
            'accent' => 'violet',
        ]);

        $this->assertSame('sx_last_user', $cookie->getName());

        $payload = json_decode($cookie->getValue(), true);

        $this->assertSame('dark', $payload['theme']);
        $this->assertSame('violet', $payload['accent']);
        $this->assertSame('Ada Lovelace', $payload['name']);
        $this->assertSame('ada@system-x.test', $payload['email']);
    }

    public function test_cookie_is_long_lived_http_only_and_same_site_lax(): void
    {
        $user = $this->aUser();

        $cookie = RememberedUser::cookie($user, [
            'theme' => 'dark',
            'accent' => 'blue',
        ]);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        // Roughly a year out (allow a little slack for clock drift in the test run).
        $this->assertGreaterThan(now()->addMonths(11)->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_from_request_round_trips_a_valid_cookie(): void
    {
        $value = json_encode([
            'theme' => 'pewter',
            'accent' => 'teal',
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ]);

        $request = Request::create('/login', 'GET', cookies: ['sx_last_user' => $value]);

        $this->assertSame([
            'theme' => 'pewter',
            'accent' => 'teal',
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ], RememberedUser::fromRequest($request));
    }

    public function test_a_forged_theme_falls_back_to_the_dark_brand_default(): void
    {
        $value = json_encode([
            'theme' => '<script>alert(1)</script>',
            'accent' => 'blue',
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ]);

        $request = Request::create('/login', 'GET', cookies: ['sx_last_user' => $value]);

        $result = RememberedUser::fromRequest($request);

        // The forged value NEVER survives -- it falls back to the literal brand default 'dark',
        // not PreferencesService::DEFAULTS['theme'] (which is 'modern').
        $this->assertSame('dark', $result['theme']);
        $this->assertNotSame('modern', $result['theme']);
        $this->assertContains($result['theme'], PreferencesService::ALLOWED['theme']);
    }

    public function test_an_unknown_accent_falls_back_to_blue(): void
    {
        $value = json_encode([
            'theme' => 'dark',
            'accent' => 'hot-pink',
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ]);

        $request = Request::create('/login', 'GET', cookies: ['sx_last_user' => $value]);

        $result = RememberedUser::fromRequest($request);

        $this->assertSame('blue', $result['accent']);
        $this->assertContains($result['accent'], PreferencesService::ALLOWED['accent']);
    }

    public function test_a_stale_removed_theme_falls_back_to_dark(): void
    {
        // A theme that was once valid but has since been removed from the allow-list
        // must not survive either -- the allow-list is the single source of truth.
        $value = json_encode([
            'theme' => 'retired-theme',
            'accent' => 'amber',
            'name' => '',
            'email' => '',
        ]);

        $request = Request::create('/login', 'GET', cookies: ['sx_last_user' => $value]);

        $result = RememberedUser::fromRequest($request);

        $this->assertSame('dark', $result['theme']);
        $this->assertSame('amber', $result['accent']);
    }

    public function test_no_cookie_returns_null(): void
    {
        $request = Request::create('/login', 'GET');

        $this->assertNull(RememberedUser::fromRequest($request));
    }

    public function test_garbage_cookie_returns_null(): void
    {
        $request = Request::create('/login', 'GET', cookies: ['sx_last_user' => 'not-json-at-all{{{']);

        $this->assertNull(RememberedUser::fromRequest($request));
    }

    public function test_the_raw_cookie_value_is_encrypted_not_readable_json(): void
    {
        // Lock the D3 guarantee: when the cookie rides a real response through the web
        // middleware stack, EncryptCookies scrambles it -- the plaintext email must NOT
        // be readable in the wire value. This is the regression net against a future
        // encryptCookies(except: ['sx_last_user']) leaking the email.
        $user = $this->aUser();

        Route::middleware('web')->get('/__remember_probe', function () use ($user) {
            Cookie::queue(RememberedUser::cookie($user, ['theme' => 'dark', 'accent' => 'blue']));

            return 'ok';
        });

        $response = $this->get('/__remember_probe');

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'sx_last_user');

        $this->assertNotNull($cookie, 'sx_last_user cookie was not set on the response');

        $raw = $cookie->getValue();
        $this->assertStringNotContainsString('ada@system-x.test', $raw);
        $this->assertStringNotContainsString('"theme"', $raw);
        $this->assertStringNotContainsString('dark', $raw);
    }
}

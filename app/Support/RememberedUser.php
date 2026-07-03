<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use SystemX\Core\Preferences\PreferencesService;

// The remember-last-user cookie seam (Plan 5c, D2/D3). The ONE tested place the cookie
// shape + its validation live. The cookie is COSMETIC ONLY -- it carries the user's look
// (theme/accent) + their display name + email for the greeter to reskin, greet by name,
// and pre-fill the email. It holds NO id/token/password: forging, clearing, or stealing
// it changes only the cosmetics + the pre-filled email string, never who can log in (auth
// is server-side + unchanged). Laravel's EncryptCookies encrypts it on the response by
// default (no encryptCookies(except:) exclusion in this app), so it is encrypted at rest.
class RememberedUser
{
    // The cookie name. Must NEVER be added to a future encryptCookies(except:) list (D3) --
    // a regression test asserts the raw value is not human-readable JSON.
    public const NAME = 'sx_last_user';

    // ~1 year, in minutes (the cookie() helper takes minutes). The greeter should keep
    // remembering you across the long gaps between sessions.
    private const LIFETIME_MINUTES = 525600;

    // The greeter's brand default look (D3). This is a DELIBERATE divergence from
    // PreferencesService::DEFAULTS (modern/blue) -- the greeter's default backdrop is the
    // dramatic dark theme, so an absent/invalid theme falls back to 'dark', not 'modern'.
    private const DEFAULT_THEME = 'dark';

    private const DEFAULT_ACCENT = 'blue';

    /**
     * Build the long-lived remember-cookie for $user with their current look. The value is
     * a JSON payload {theme, accent, name, email}; EncryptCookies encrypts it on the way out.
     *
     * @param  array<string, string>  $prefs
     */
    public static function cookie(User $user, array $prefs): Cookie
    {
        $payload = json_encode([
            'theme' => $prefs['theme'] ?? self::DEFAULT_THEME,
            'accent' => $prefs['accent'] ?? self::DEFAULT_ACCENT,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ]);

        // cookie() helper: name, value, minutes, path, domain, secure, httpOnly, raw, sameSite.
        // secure follows the app's session policy (https in prod/Herd) so the remember-cookie
        // is held to the same transport bar as the auth session itself.
        return cookie(
            name: self::NAME,
            value: $payload,
            minutes: self::LIFETIME_MINUTES,
            secure: config('session.secure'),
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    /**
     * Read + decode + VALIDATE the remember-cookie off the request. The cookie is UNTRUSTED
     * input -- its theme/accent get stamped onto <html data-sx-*> by the greeter (the exact
     * injection vector the /prefs endpoint guards), so validate them against the SAME single
     * source of truth (PreferencesService::ALLOWED) before they can touch the page. An
     * absent/unknown/stale/forged theme or accent drops to the brand default; a missing or
     * undecodable cookie returns null (the caller renders the blank brand state).
     *
     * @return array{theme: string, accent: string, name: string, email: string}|null
     */
    public static function fromRequest(Request $request): ?array
    {
        // By the time a request handler sees it, EncryptCookies has already decrypted it.
        $raw = $request->cookie(self::NAME);

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'theme' => self::validatedLook($decoded, 'theme', self::DEFAULT_THEME),
            'accent' => self::validatedLook($decoded, 'accent', self::DEFAULT_ACCENT),
            // Bounded -- the cookie is owner-forgeable (encrypted, so only by its owner), but a
            // multi-KB name would still bloat the greeting layout. Escaping is the XSS guard ({{ }}
            // in the view); this just caps the cosmetic length.
            'name' => self::boundedString($decoded['name'] ?? null),
            'email' => self::boundedString($decoded['email'] ?? null),
        ];
    }

    /**
     * Resolve a look key against PreferencesService::ALLOWED, falling back to $default when
     * the value is absent, the wrong type, or not in the allow-list. No allowed set is
     * duplicated here -- ALLOWED is the shared source of truth.
     *
     * @param  array<string, mixed>  $decoded
     */
    private static function validatedLook(array $decoded, string $key, string $default): string
    {
        $value = $decoded[$key] ?? null;

        if (is_string($value) && in_array($value, PreferencesService::ALLOWED[$key], true)) {
            return $value;
        }

        return $default;
    }

    // A display string off the (untrusted) cookie: a string, capped at 255 chars.
    private static function boundedString(mixed $value): string
    {
        return is_string($value) ? mb_substr($value, 0, 255) : '';
    }
}

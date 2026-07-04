<?php

namespace App\Http\Controllers\Auth;

use App\Support\RememberedUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SystemX\Core\Support\ClientConfig;

// Hand-rolled minimal login over the built-in `web` SESSION guard (D1). No starter
// kit, no registration, no profile/password-reset surface -- just enough to put a
// real authenticated user in the session so every desktop action is attributable.
// The on-brand X11 greeter that REPLACES this plain form is Plan 5; this controller
// is unaffected by that restyle (it authenticates + redirects regardless of view).
class LoginController
{
    // The greeter (Plan 5c, D1/D2/D4). The ONLY change the restyle makes to this controller:
    // read the cosmetic remember-cookie so the view can reskin + greet + pre-fill the email.
    // authenticate()/logout() and the routes/throttle/gate stay byte-unchanged (D1).
    public function show(Request $request): View
    {
        // Live-demo swap (showcase plan): when demo mode is on, the guest-facing entry is the
        // landing page, not the credential greeter. The route + guest gate + /-to-/login bounce
        // are all unchanged; only the returned view differs. Off => the original greeter path below.
        if (config('system-x-demo.enabled')) {
            return view('demo.landing');
        }

        // "Not you?" (D4): ?forget=1 wipes the remember-cookie and renders the blank brand
        // state, so the machine is never stuck greeting one person. No new route -- this GET
        // already serves /login. Cookie::forget queues the deletion onto the response.
        if ($request->boolean('forget')) {
            Cookie::queue(Cookie::forget(RememberedUser::NAME));

            return view('system-x::greeter', [
                'remembered' => null,
                'sxConfig' => (new ClientConfig)->forRequest($request),
            ]);
        }

        // The cookie is UNTRUSTED -- fromRequest validates theme/accent against the shared
        // PreferencesService::ALLOWED before the view stamps them onto <html> (D3). null =
        // no/garbage cookie -> the view renders the dark/blue brand default + a blank card.
        return view('system-x::greeter', [
            'remembered' => RememberedUser::fromRequest($request),
            'sxConfig' => (new ClientConfig)->forRequest($request),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // No `remember` (S2): a long-lived auth cookie is unneeded surface on a
        // throwaway minimal login. Plain session auth only.
        if (! Auth::attempt($credentials)) {
            // A generic, non-enumerating error keyed to `email` (the standard Laravel
            // shape) -- it does not reveal whether the email exists.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Session fixation defence (D7): rotate the session id on the privilege change.
        $request->session()->regenerate();

        // intended() honours a pre-auth redirect (the `auth` middleware stashes the
        // desktop URL a guest was bounced from); default to the desktop shell.
        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        // Invalidate the session + rotate the CSRF token so the post-logout session
        // cannot be replayed (D7).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}

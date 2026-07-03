<!DOCTYPE html>
{{-- No-flash brand stamp (Plan 5c, D2/D3): the theme + accent ride <html> SERVER-SIDE, so the
     whole greeter paints in the remembered user's look on the first byte (the same pattern as
     desktop.blade.php). A fresh guest (no cookie -> $remembered is null) falls back to the
     greeter's brand default -- the dramatic DARK theme + blue accent (LITERALS, NOT the desktop
     PreferencesService DEFAULTS which are modern/blue). The theme/accent in $remembered are
     already validated against PreferencesService::ALLOWED by RememberedUser::fromRequest, so an
     unknown/forged value can never reach this attribute (D3). --}}
<html lang="en"
      data-sx-theme="{{ $remembered['theme'] ?? 'dark' }}"
      data-sx-accent="{{ $remembered['accent'] ?? 'blue' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>system-x &middot; sign in</title>
    {{-- B1: the plain login page loaded ZERO css -- without the design-system bundle every
         var(--sx-*) resolves to nothing and the greeter is unstyled garbage. system-x.css pulls
         the tokens/themes/accents + (via its @import) greeter.css; greeter.js ticks the clock. --}}
    <script>window.sxConfig = @json($sxConfig)</script>
    @systemxStyles
    @systemxGreeterScripts
</head>
<body>
    {{-- The backdrop owns the theme's --sx-desktop-bg gradient (D8) -- so it reskins for free
         with the remembered look, exactly like the desktop wallpaper. --}}
    <div class="sx-greeter">
        {{-- Top-left: the muted wordmark (the design mark + "system-x", currentColor-tinted so
             it inherits the theme's soft text colour). --}}
        <div class="sx-greeter-brand" aria-hidden="true">
            <svg class="sx-greeter-mark" width="22" height="22" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="3.5" y="4.5" width="25" height="23" stroke="currentColor" stroke-width="2"></rect>
                <rect x="3.5" y="4.5" width="25" height="6" fill="currentColor"></rect>
                <path d="M10 15L22 25M22 15L10 25" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"></path>
            </svg>
            <span class="sx-greeter-wordmark">system<span class="sx-greeter-wordmark-x">-x</span></span>
        </div>

        {{-- Upper-centre: the live clock + date, SERVER-SEEDED with now() so there is no
             blank-then-pop on first paint (D5). greeter.js repaints both every 15s. --}}
        <div class="sx-greeter-time">
            <div class="sx-greeter-clock" data-sx-clock>{{ now()->format('H:i') }}</div>
            <div class="sx-greeter-date" data-sx-date>{{ now()->translatedFormat('l j F') }}</div>
        </div>

        {{-- Centre: the login card -- a bevelled panel that reskins with the theme. --}}
        <div class="sx-greeter-card">
            @if ($remembered)
                {{-- The remembered user (D2). The avatar shows their INITIALS -- derived from the
                     escaped name via a small helper, then output through {{ }} so a <script> name
                     can never inject (D3 XSS). "Welcome back" + the bold name greet them. --}}
                @php
                    $initials = collect(preg_split('/\s+/', trim($remembered['name'])))
                        ->filter()
                        ->take(2)
                        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                        ->implode('');
                @endphp
                <div class="sx-greeter-identity">
                    <div class="sx-greeter-avatar" aria-hidden="true">{{ $initials }}</div>
                    <p class="sx-greeter-welcome">Welcome back</p>
                    <p class="sx-greeter-name">{{ $remembered['name'] }}</p>
                </div>
            @endif

            {{-- The auth form contract (Task 3 landmine): method/action/@csrf/name=email/
                 name=password/the "Sign in" submit/the @error('email') slot all survive the
                 restyle verbatim -- the controller + the Dusk loginAsDemoUser helper + LoginTest
                 depend on them. This is a RESTYLE, not a rewrite. --}}
            <form method="POST" action="/login" class="sx-greeter-form">
                @csrf

                @error('email')
                    <p class="sx-greeter-error" role="alert">{{ $message }}</p>
                @enderror

                {{-- Email: pre-filled with the remembered address (a convenience, not a
                     credential -- D3) or old() on a failed submit; blank for a fresh guest.
                     All name/email output via {{ }} (Blade-escaped, D3 XSS). --}}
                <label class="sx-greeter-field">
                    <span class="sx-greeter-label">Email</span>
                    <span class="sx-textfield">
                        <input type="email" name="email"
                               value="{{ $remembered['email'] ?? old('email') }}"
                               autocomplete="username" required
                               @unless ($remembered) autofocus @endunless>
                    </span>
                </label>

                {{-- Password: always empty, autofocused when the email is already known (the
                     returning user just types their password). No reveal/eye toggle (D7 --
                     dropped: a shoulder-surf risk on the most security-sensitive screen). --}}
                <label class="sx-greeter-field">
                    <span class="sx-greeter-label">Password</span>
                    <span class="sx-textfield">
                        <input type="password" name="password"
                               autocomplete="current-password" required
                               @if ($remembered) autofocus @endif>
                    </span>
                </label>

                <button type="submit" class="sx-button sx-greeter-submit">Sign in</button>
            </form>

            @if ($remembered)
                {{-- "Not you?" (D4): reset to the blank brand state. show() honours ?forget=1 by
                     forgetting the cookie + rendering the default look + an empty card. --}}
                <a href="/login?forget=1" class="sx-greeter-forget">Not you? Sign in as someone else</a>
            @endif
        </div>

        {{-- Bottom-right: a small muted footer. --}}
        <div class="sx-greeter-footer" aria-hidden="true">system-x</div>
    </div>
</body>
</html>

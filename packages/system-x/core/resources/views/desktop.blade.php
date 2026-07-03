<!DOCTYPE html>
{{-- No-flash boot (Plan 5b-2, D4): the per-user theme/accent/panel-position are STAMPED on
     <html> server-side, so the WHOLE desktop (windows + the body-mounted panel/launcher)
     paints in the user's look on the first byte. <html> is the only common ancestor of
     #sx-desktop AND the body chrome, so the bare-attribute token selectors reskin everything.
     A default user stamps modern/blue/top -- which matches the CSS :root, so no flash either way. --}}
<html lang="en"
      data-sx-theme="{{ $prefs['theme'] }}"
      data-sx-accent="{{ $prefs['accent'] }}"
      data-sx-panel="{{ $prefs['panel_position'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>system-x</title>
    <script>window.sxConfig = @json($sxConfig)</script>
    @systemxStyles
    @systemxVendorStyles
    @systemxScripts
    @systemxVendorScripts
</head>
<body>
    {{-- The wallpaper rides on #sx-desktop (Plan 5b-2, D3) -- it owns the background, so the
         wallpaper rules key off #sx-desktop[data-sx-wallpaper='...']. Outside the morph (the
         reconciler only touches .sx-window content), so a server frame can never clobber it. --}}
    {{-- data-desktop-id is the id surface (the authenticated user id); display-server.js
         reads it and subscribes to the private user.{id} channel (Plan 5c, D6). The
         attribute name stays put -- only the channel string it feeds was renamed. --}}
    <div id="sx-desktop" class="sx-desktop"
         data-desktop-id="{{ $desktopId }}"
         data-sx-wallpaper="{{ $prefs['wallpaper'] }}">
        {{-- The user's OPEN-WINDOW set (D7) -- read from OpenWindowService, not a constant.
             On first boot this is the seeded hello+notes pair (slug-as-id, so durable state
             reattaches across reload with no id minting); runtime-launched windows (Task 8)
             carry a ULID id + their app. Each surface declares data-window-id (the bag/
             surface key) + data-app (which App to run, D4). The window manager owns geometry
             and chrome -- do NOT add positioning CSS here. --}}
        {{-- The boot RESTORE stamp (Plan 5e, D4/S3). A window with SAVED geometry (x is not null)
             carries its persisted rect as the EXACT data-sx-* attributes the WM adopt() reads, so
             the WM APPLIES it instead of cascading: data-sx-x/y (position) + data-sx-z (stacking)
             + (for a SIZED window) the width/height style AND data-sx-sized='true' (BOTH, or the 5d
             fill rule won't match) + the data-sx-max/min flags. A MAXIMISED window stamps the
             RESTORE rect (x/y/w/h) + the flag -- NOT the maximised dims (the WM computes the
             work-area fill against the live viewport). A NULL-geometry window stamps nothing extra
             (just data-window-id/data-app) so the WM cascades it. The morph never touches the
             surface, so this stamped geometry survives every server frame (D3). --}}
        @foreach ($windows as $w)
            <div class="sx-window-surface"
                 data-window-id="{{ $w['window'] }}"
                 data-app="{{ $w['app'] }}"
                 @if (! is_null($w['x']))
                     data-sx-x="{{ $w['x'] }}"
                     data-sx-y="{{ $w['y'] }}"
                     @if (! is_null($w['z'])) data-sx-z="{{ $w['z'] }}" @endif
                     @if ($w['sized']) data-sx-sized="true" style="width:{{ $w['w'] }}px;height:{{ $w['h'] }}px" @endif
                     @if ($w['maximised']) data-sx-max="true" @endif
                     @if ($w['minimised']) data-sx-min="true" @endif
                 @endif
            ></div>
        @endforeach

        {{-- The boot payload (Plan 5b, D2/D3). App metadata rides to the client as a JSON
             blob the display server parses at boot: `windows` is the per-window
             {window, app, title, icon} (the panel's labels -- the trees self-hydrate, so the
             title/icon are NOT stamped as per-surface attributes; the panel reads this map),
             and `apps` is the registry metadata grid (the launcher). The blob lives in
             #sx-desktop so it tears down/reattaches with the mount, never as a stray body node. --}}
        {{-- HTML-safe JSON: JSON_HEX_TAG|AMP|APOS|QUOT hex-escape < > & ' " so a third-party
             app's developer-declared title()/icon() can never break out of this inline <script>
             at the HTML-parser level (open-core: titles are app-controlled). Zero behaviour
             change -- the client JSON.parse decodes the < etc. escapes back transparently. --}}
        {{-- The boot blob GAINS the panel position (Plan 5b-2, D4/D6) so the WindowManager
             ctor insets the work area for the correct edge on its first maximise/clamp. --}}
        {{-- The user's name (plan system-menu, D5) rides the SAME hardened json_encode as
             apps/windows -- so a </script>-style name inherits the JSON_HEX_* escaping and
             can never break out of this inline <script>. The system menu reads it for the
             header + the tray initials, rendered client-side via textContent (XSS-safe). --}}
        <script type="application/json" id="sx-boot">{!! json_encode(['windows' => $windows, 'apps' => $apps, 'layout' => $layout, 'panel' => $prefs['panel_position'], 'user' => ['name' => $userName]], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

        {{-- Logout lives in the panel tray now (Plan 5b, Task 7, D6). The tray control
             (dusk="logout") POSTs /logout via the csrf-token meta above -- the old floating
             <form> is gone, so it no longer overlaps a maximised window's controls. --}}
    </div>
</body>
</html>

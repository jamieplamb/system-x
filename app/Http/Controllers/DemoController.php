<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindowService;

// Live-demo provisioning (showcase plan). Mints a throwaway is_demo user, logs them in,
// opens the welcome window, and drops them on the desktop. This route only exists when demo
// mode is on (registered conditionally in routes/web.php), and is guest + throttle gated.
class DemoController extends Controller
{
    public function __construct(private OpenWindowService $openWindows) {}

    public function launch(Request $request): RedirectResponse|View
    {
        // Soft ceiling (S: accepted non-atomic race). At/over the cap we mint nothing and show
        // the capacity page; the prune drains idle users so this self-heals. No locking.
        $cap = (int) config('system-x-demo.max_users');
        if (User::query()->where('is_demo', true)->count() >= $cap) {
            return view('demo.capacity');
        }

        // The ephemeral account: a .invalid email (reserved TLD, can never route mail or collide
        // with a real address) + a random password nobody knows. The live session cookie is the
        // only key to this desktop, which is exactly right for a throwaway. 'hashed' cast hashes
        // the password on assignment.
        $user = User::query()->create([
            'name' => 'Guest',
            'email' => 'demo+'.Str::ulid().'@system-x.invalid',
            'password' => Str::random(40),
            'is_demo' => true,
            'last_active_at' => now(),
        ]);

        Auth::login($user);

        // Populate the demo desktop for the just-minted user. Same principal shape WmController
        // uses for open-set ops (windowId irrelevant). Order matters: seedDefaults early-returns
        // the moment the user has ANY open window, so it must run BEFORE we open the welcome
        // window -- otherwise the hello/notes example pair would be skipped and the demo would
        // boot bare. Seed the example apps first, then stack the welcome window on top. All
        // once-per-user (provisioning runs once), so no extra guard. The first GET / then finds
        // the user already seeded and just stamps the seed marker.
        $principal = new StateKey('user', (string) $user->id, '');
        $this->openWindows->seedDefaults($principal);
        $this->openWindows->launch($principal, 'welcome');

        return redirect('/');
    }
}

<?php

namespace SystemX\Core\Preferences;

use Illuminate\Database\Eloquent\Model;

// One row per principal (Plan 5b-2, D1) -- per-USER prefs, the 2-tuple key. The prefs
// column is a JSON bag cast to array (theme/accent/wallpaper/panel_position).
class Preference extends Model
{
    protected $table = 'system_x_preferences';

    // desktop_seeded_at is the desktop-BOOTSTRAP marker (seed-once-ever fix), NOT a cosmetic
    // pref -- a real nullable timestamp column riding this same per-user row, distinct from the
    // prefs JSON bag.
    protected $fillable = ['principal_type', 'principal_id', 'prefs', 'desktop_seeded_at'];

    protected $casts = ['prefs' => 'array', 'desktop_seeded_at' => 'datetime'];
}

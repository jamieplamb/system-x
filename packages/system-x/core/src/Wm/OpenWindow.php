<?php

namespace SystemX\Core\Wm;

use Illuminate\Database\Eloquent\Model;

// One row per OPEN window for a principal (Plan 5a, D7). The window_id is a ULID for a
// launched window, or the slug for the static pair (hello/notes) -- both fit the varchar.
// The app column carries the registered app slug rendered into the window (the lookup
// that lets a ULID window resolve its app on resync, B4).
class OpenWindow extends Model
{
    protected $table = 'system_x_open_windows';

    // Plan 5e, D1: the geometry columns ride on the open-windows row (the RESTORE rect +
    // stacking + the maximised/minimised flags). Fillable so saveGeometry can update them.
    protected $fillable = [
        'principal_type', 'principal_id', 'window_id', 'app',
        'x', 'y', 'w', 'h', 'sized', 'maximised', 'minimised', 'z',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
            'w' => 'integer',
            'h' => 'integer',
            'sized' => 'boolean',
            'maximised' => 'boolean',
            'minimised' => 'boolean',
            'z' => 'integer',
        ];
    }
}

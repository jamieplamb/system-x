<?php

namespace SystemX\Core\Launcher;

use Illuminate\Database\Eloquent\Model;

// One row per principal (Plan 4a) -- per-USER launcher layout, the 2-tuple key. The layout
// column is an ordered JSON document cast to array.
class LauncherLayout extends Model
{
    protected $table = 'system_x_launcher_layout';

    protected $fillable = ['principal_type', 'principal_id', 'layout'];

    protected $casts = ['layout' => 'array'];
}

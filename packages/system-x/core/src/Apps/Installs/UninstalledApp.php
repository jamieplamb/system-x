<?php

namespace SystemX\Core\Apps\Installs;

use Illuminate\Database\Eloquent\Model;

// One row per app a principal has UNINSTALLED (App-install plan, D1). The SUBTRACTIVE
// per-user set: a row means "this user uninstalled this app", so the launcher shows the
// registered USER apps MINUS these. A fresh user has no rows (nothing uninstalled).
class UninstalledApp extends Model
{
    protected $table = 'system_x_uninstalled_apps';

    protected $fillable = ['principal_type', 'principal_id', 'app'];
}

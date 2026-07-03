<?php

namespace SystemX\Core\Apps;

use SystemX\Core\Runtime\App;
use SystemX\Core\Widgets\Raw;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The Audit viewer app (audit plan §7). A SYSTEM app -- lives in the user-icon menu next to
// Appearance/About/Manage-apps. render() is PRINCIPAL-FREE: it emits only a Raw mount shell; the
// client (audit.js, a later task) fetches the viewer-scoped GET /system-x/audit and paints the
// trail into the .sx-audit container when the surface appears. The escape hatch keeps the
// per-user query off the render path.
class AuditApp extends App
{
    public function slug(): string
    {
        return 'audit';
    }

    public function title(): string
    {
        return 'Audit';
    }

    public function icon(): string
    {
        return 'audit';
    }

    public function system(): bool
    {
        return true;
    }

    public function render(): Node
    {
        return Window::make('Audit')->size(420, 460)->content([
            Raw::make()->html('<div class="sx-audit" data-sx-audit>Loading audit trail...</div>'),
        ]);
    }
}

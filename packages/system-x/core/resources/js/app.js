import './echo.js';
import { DisplayServer } from './system-x/display-server.js';

const mount = document.getElementById('sx-desktop');
if (mount) {
    const server = new DisplayServer(mount, mount.dataset.desktopId);
    // Expose the live display server so the launch path can be driven directly in tests
    // (window.sx.launch(app) opens a window end-to-end). The panel's system-x button opens
    // the launcher overlay (D5), which drives the same openApp path for real users.
    window.sx = server;
    server.boot();
}

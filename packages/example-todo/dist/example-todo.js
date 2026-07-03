// system-x vendor bundle for example/todo-app -- HAND-WRITTEN, no build step.
// A custom widget renderer is a plain { create, update } object registered into the shared
// window.SystemX.renderers global. No import from core is needed -- the global is the seam.
// This file is emitted as a <script defer> AFTER the core bundle (via @systemxVendorScripts),
// so window.SystemX.renderers already exists and this registration lands before the first render.
(function () {
    var sx = window.SystemX;
    if (!sx || !sx.renderers) {
        // Defensive: if the ordering ever regressed, fail loud in the console rather than silently.
        console.error('example-todo: window.SystemX.renderers missing -- vendor script loaded before core?');
        return;
    }

    // Delegated-dispatch, exactly like core's button.js: stamp data-sx-id + data-sx-events and
    // attach NO listener. The surface dispatcher reads data-sx-events, resolves the window/app, and
    // emits -- so a bound click (App::on('click', ...)) round-trips with zero imports from core.
    sx.renderers.register('example.gauge', {
        create: function (node) {
            var el = document.createElement('button');
            el.className = 'sx-example-gauge';
            el.dataset.sxId = node.id || '';
            el.dataset.sxEvents = (node.props.events || ['click']).join(',');
            el.textContent = String(node.props.value);
            return el;
        },
        update: function (el, node) {
            el.dataset.sxEvents = (node.props.events || ['click']).join(',');
            var next = String(node.props.value);
            if (el.textContent !== next) {
                el.textContent = next;
            }
        },
    });
})();

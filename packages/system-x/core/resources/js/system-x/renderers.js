import { registry } from './renderer-registry.js';
import { badgeRenderer } from './widgets/badge.js';
import { groupBoxRenderer } from './widgets/groupbox.js';
import { progressBarRenderer } from './widgets/progressbar.js';
import { separatorRenderer } from './widgets/separator.js';
import { windowRenderer } from './widgets/window.js';
import { labelRenderer } from './widgets/label.js';
import { buttonRenderer } from './widgets/button.js';
import { boxRenderer } from './widgets/box.js';
import { gridRenderer } from './widgets/grid.js';
import { stackRenderer } from './widgets/stack.js';
import { textFieldRenderer } from './widgets/textfield.js';
import { listRenderer } from './widgets/list.js';
import { listItemRenderer } from './widgets/listitem.js';
import { checkboxRenderer } from './widgets/checkbox.js';
import { switchRenderer } from './widgets/switch.js';
import { selectRenderer } from './widgets/select.js';
import { radioGroupRenderer } from './widgets/radiogroup.js';
import { sliderRenderer } from './widgets/slider.js';
import { tabsRenderer } from './widgets/tabs.js';
import { toolbarRenderer } from './widgets/toolbar.js';
import { dialogRenderer } from './widgets/dialog.js';
import { menuButtonRenderer } from './widgets/menubutton.js';
import { menuBarRenderer } from './widgets/menubar.js';
import { tooltipRenderer } from './widgets/tooltip.js';
import { rawRenderer } from './widgets/raw.js';
import { imageRenderer } from './widgets/image.js';
import { chartRenderer } from './widgets/chart.js';

// Self-register the core widgets at import time -- BEFORE boot()'s first render,
// the same timing rule as customElements.define() (D5). Pro packs register later
// into the same singleton via window.SystemX.renderers.register(...), but ALSO
// before the first render -- late registration is out of scope (no re-reconcile).
registry.register('badge', badgeRenderer);
registry.register('groupbox', groupBoxRenderer);
registry.register('progressbar', progressBarRenderer);
registry.register('separator', separatorRenderer);
registry.register('window', windowRenderer);
registry.register('label', labelRenderer);
registry.register('button', buttonRenderer);
registry.register('box', boxRenderer);
registry.register('grid', gridRenderer);
registry.register('stack', stackRenderer);
registry.register('textfield', textFieldRenderer);
registry.register('list', listRenderer);
registry.register('listitem', listItemRenderer);
registry.register('checkbox', checkboxRenderer);
registry.register('switch', switchRenderer);
registry.register('select', selectRenderer);
registry.register('radiogroup', radioGroupRenderer);
registry.register('slider', sliderRenderer);
registry.register('tabs', tabsRenderer);
registry.register('toolbar', toolbarRenderer);
registry.register('dialog', dialogRenderer);
registry.register('menu', menuButtonRenderer);
registry.register('menubar', menuBarRenderer);
registry.register('tooltip', tooltipRenderer);
registry.register('raw', rawRenderer);
registry.register('image', imageRenderer);
registry.register('chart', chartRenderer);

// Back-compat shim so older imports of renderNode keep working. New code uses
// registry.render directly.
export function renderNode(node, ctx) {
    return registry.render(node, ctx);
}

export { registry };

<?php

namespace SystemX\Core\Demo;

use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\Widgets\Badge;
use SystemX\Core\Widgets\Box;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Dialog;
use SystemX\Core\Widgets\GroupBox;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\MenuBar;
use SystemX\Core\Widgets\MenuButton;
use SystemX\Core\Widgets\ProgressBar;
use SystemX\Core\Widgets\RadioGroup;
use SystemX\Core\Widgets\Select;
use SystemX\Core\Widgets\Separator;
use SystemX\Core\Widgets\Slider;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\SwitchWidget;
use SystemX\Core\Widgets\Tabs;
use SystemX\Core\Widgets\Toolbar;
use SystemX\Core\Widgets\Tooltip;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

// The Controls gallery. A USER app (launchable + uninstallable like Hello/Notes) that
// dogfoods the widget set and doubles as living documentation. The gallery is itself a
// Tabs widget: each control TYPE gets its own category tab (Display / Inputs / Containers)
// so only one group renders at a time -- the window always fits without scrolling, however
// much we add. The active category is durable too. A live-state readout sits below the
// tabs, always visible. The Volume Slider + ProgressBar are grouped because they share one
// durable value (move the slider, the bar follows); the Containers tab nests the demo Tabs.
class ControlsApp extends App
{
    public string $theme = 'light';

    public string $size = 's';

    public bool $wifi = false;

    public int $volume = 0;

    public string $activeTab = 'first';

    public string $lastAction = 'none';

    // The gallery's own top-level category tab (durable, like every other Tabs).
    public string $activeCategory = 'display';

    public bool $showDialog = false;

    public bool $showForced = false;

    public string $lastPick = 'none';

    public function slug(): string
    {
        return 'controls';
    }

    public function title(): string
    {
        return 'Controls';
    }

    public function icon(): string
    {
        // A sliders/settings-ish glyph from the design Icon set (icons.js).
        return 'gear';
    }

    public function render(): Node
    {
        $wifi = $this->wifi ? 'on' : 'off';
        $dlg = $this->showDialog ? 'open' : 'closed';

        return Window::make('Controls')->size(500, 480)->content([
            Stack::make()->content([

                // The gallery IS a Tabs widget: one category tab per control type, so only
                // one group renders at a time and the window always fits (window-content
                // scrolling is not built yet, and a launched window grows to its content).
                // The active category is durable. Panels pair to the tabs map by order.
                Tabs::make()
                    ->tabs(['display' => 'Display', 'inputs' => 'Inputs', 'containers' => 'Containers', 'dialogs' => 'Dialogs', 'menus' => 'Menus', 'tooltips' => 'Tooltips'])
                    ->active($this->activeCategory)
                    ->id('ctl-categories')
                    ->content([

                        // DISPLAY: Badges (in a Box so they sit in a row) + the Separator demo.
                        Stack::make()->content([
                            Label::make('Inline status pills in five tones for status and labels.'),
                            Box::make()->content([
                                Badge::make('Neutral')->tone('neutral'),
                                Badge::make('Info')->tone('info'),
                                Badge::make('OK')->tone('ok'),
                                Badge::make('Warn')->tone('warn'),
                                Badge::make('Error')->tone('error'),
                            ]),
                            Separator::make(),
                            Label::make('The rule above is a Separator widget.'),
                        ]),

                        // INPUTS: the form controls, plus the Volume pair (Slider + ProgressBar
                        // grouped, since they share one durable value -- move the slider, the
                        // bar follows).
                        Stack::make()->content([
                            Label::make('Select: pick one from a dropdown.'),
                            Select::make('Theme')
                                ->options(['light' => 'Light', 'dark' => 'Dark'])
                                ->value($this->theme)
                                ->id('ctl-theme')
                                ->onChange(function (WidgetEvent $event): void {
                                    $this->theme = $event->asString();
                                }),

                            Label::make('Radio group: pick one, shown inline.'),
                            RadioGroup::make('Size')
                                ->options(['s' => 'Small', 'l' => 'Large'])
                                ->value($this->size)
                                ->id('ctl-size')
                                ->onChange(function (WidgetEvent $event): void {
                                    $this->size = $event->asString();
                                }),

                            Label::make('Switch: a boolean toggle.'),
                            SwitchWidget::make('Wifi')
                                ->checked($this->wifi)
                                ->id('ctl-wifi')
                                ->onChange(function (WidgetEvent $event): void {
                                    $this->wifi = $event->asBool();
                                }),

                            GroupBox::make('Volume (slider drives the bar)')->content([
                                Label::make('These two share one durable value. Drag the slider and the bar follows.'),
                                Slider::make('Volume')
                                    ->min(0)->max(100)->step(1)
                                    ->value($this->volume)
                                    ->id('ctl-volume')
                                    ->onChange(function (WidgetEvent $event): void {
                                        $this->volume = $event->asInt();
                                    }),
                                ProgressBar::make()->value($this->volume)->label('Volume'),
                            ]),
                        ]),

                        // CONTAINERS: the demo Tabs (nested Tabs) + a Toolbar.
                        Stack::make()->content([
                            GroupBox::make('Tabs')->content([
                                Label::make('A tab strip whose active tab is durable and survives a reload.'),
                                Tabs::make()
                                    ->tabs(['first' => 'First', 'second' => 'Second'])
                                    ->active($this->activeTab)
                                    ->id('ctl-tabs')
                                    ->content([
                                        Label::make('First panel')->id('tab-panel-first'),
                                        Label::make('Second panel')->id('tab-panel-second'),
                                    ])
                                    ->onChange(fn (WidgetEvent $event) => $this->activeTab = $event->asString()),
                            ]),

                            GroupBox::make('Toolbar')->content([
                                Label::make('A raised strip of actions. Each button runs a handler.'),
                                Toolbar::make()->content([
                                    Button::make('New')->id('tb-new')->handles('toolbarNew'),
                                    Separator::make()->vertical(),
                                    Button::make('Open')->id('tb-open')->handles('toolbarOpen'),
                                ]),
                            ]),
                        ]),

                        // DIALOGS: a dismissible dialog (Escape/backdrop/close all dismiss) + a must-decide one.
                        Stack::make()->content([
                            Label::make('A window-modal dialog. Opens over this window, dims the rest, traps focus.'),
                            Button::make('Open dialog')->id('dlg-open')->handles('openDialog'),
                            Dialog::make()
                                ->open($this->showDialog)
                                ->title('Settings')
                                ->id('ctl-dialog')
                                ->onClose('closeDialog')
                                ->content([
                                    Label::make('This is a window-modal dialog. Dismiss with the close button, Escape, or by clicking the backdrop.'),
                                    Button::make('Done')->id('dlg-done')->handles('closeDialog'),
                                ]),

                            Separator::make(),
                            Label::make('A non-dismissible dialog: Escape and backdrop do nothing, you must choose.'),
                            Button::make('Open forced choice')->id('dlg-open-forced')->handles('openForced'),
                            Dialog::make()
                                ->open($this->showForced)
                                ->title('Confirm')
                                ->dismissible(false)
                                ->id('ctl-dialog-forced')
                                ->onClose('closeForced')
                                ->content([
                                    Label::make('You must pick an action. Escape and backdrop clicks are ignored.'),
                                    Button::make('OK')->id('dlg-forced-ok')->handles('closeForced'),
                                ]),
                        ]),

                        // MENUS: a MenuButton (anchored dropdown) + a MenuBar (menu strip).
                        Stack::make()->content([
                            Label::make('A MenuButton opens an anchored dropdown. Pick an item and the readout updates.'),
                            MenuButton::make('Actions')
                                ->id('ctl-menubutton')
                                ->items([
                                    ['label' => 'Save', 'value' => 'save'],
                                    ['label' => 'Duplicate', 'value' => 'duplicate'],
                                    ['divider' => true],
                                    ['label' => 'Rename', 'value' => 'rename', 'disabled' => true],
                                    ['label' => 'Delete', 'value' => 'delete', 'danger' => true],
                                ])
                                ->onSelect('onMenuPick'),

                            Separator::make(),
                            Label::make('A MenuBar. Open a menu, then hover the other label to switch.'),
                            MenuBar::make()
                                ->id('ctl-menubar')
                                ->menus([
                                    ['label' => 'File', 'items' => [
                                        ['label' => 'New', 'value' => 'file.new'],
                                        ['label' => 'Open', 'value' => 'file.open'],
                                    ]],
                                    ['label' => 'Edit', 'items' => [
                                        ['label' => 'Copy', 'value' => 'edit.copy'],
                                        ['label' => 'Paste', 'value' => 'edit.paste'],
                                    ]],
                                ])
                                ->onSelect('onMenuPick'),
                        ]),

                        // TOOLTIPS: display-only hints on a button and a badge.
                        Stack::make()->content([
                            Label::make('Hover (or tab to) a control to see its tooltip.'),
                            Tooltip::make('Saves your work')
                                ->side('top')
                                ->id('ctl-tooltip')
                                ->content([Button::make('Save')->id('tt-save')]),
                            Tooltip::make('An inline status pill')
                                ->side('right')
                                ->content([Badge::make('Info')->tone('info')]),
                        ]),
                    ])
                    ->onChange(fn (WidgetEvent $event) => $this->activeCategory = $event->asString()),

                // Live state: the durable properties, updated as you interact. Sits BELOW the
                // category tabs so it is always visible whichever category is on show. Dusk
                // asserts this readout string (keep its format; new fields append at the end).
                GroupBox::make('Live state')->content([
                    Label::make('The durable state, updated live as you interact:'),
                    Label::make("theme={$this->theme} size={$this->size} wifi={$wifi} volume={$this->volume} activeTab={$this->activeTab} lastAction={$this->lastAction} activeCategory={$this->activeCategory} dialog={$dlg} menuPick={$this->lastPick}")
                        ->id('controls-readout'),
                ]),

                // Error isolation: a handler that throws is caught server-side. It returns an
                // error toast and rolls back; the desktop survives. Sits by the readout so it
                // is always visible whichever category is on show.
                GroupBox::make('Error isolation')->content([
                    Label::make('Error isolation: this button throws; the desktop survives and toasts.'),
                    Button::make('Crash')->id('ctl-crash')->handles('crash'),
                ]),
            ]),
        ]);
    }

    public function toolbarNew(): void
    {
        $this->lastAction = 'new';
    }

    public function toolbarOpen(): void
    {
        $this->lastAction = 'open';
    }

    public function openDialog(): void
    {
        $this->showDialog = true;
    }

    public function closeDialog(): void
    {
        $this->showDialog = false;
    }

    public function openForced(): void
    {
        $this->showForced = true;
    }

    public function closeForced(): void
    {
        $this->showForced = false;
    }

    public function onMenuPick(WidgetEvent $event): void
    {
        $this->lastPick = $event->asString();
    }

    public function crash(): void
    {
        throw new \RuntimeException('deliberate demo crash');
    }
}

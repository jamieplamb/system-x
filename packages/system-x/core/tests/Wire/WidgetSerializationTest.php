<?php

namespace SystemX\Core\Tests\Wire;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Widgets\Badge;
use SystemX\Core\Widgets\Box;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Chart;
use SystemX\Core\Widgets\Checkbox;
use SystemX\Core\Widgets\Dialog;
use SystemX\Core\Widgets\Grid;
use SystemX\Core\Widgets\GroupBox;
use SystemX\Core\Widgets\Image;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\ListItem;
use SystemX\Core\Widgets\ListWidget;
use SystemX\Core\Widgets\MenuBar;
use SystemX\Core\Widgets\MenuButton;
use SystemX\Core\Widgets\ProgressBar;
use SystemX\Core\Widgets\RadioGroup;
use SystemX\Core\Widgets\Raw;
use SystemX\Core\Widgets\Select;
use SystemX\Core\Widgets\Separator;
use SystemX\Core\Widgets\Slider;
use SystemX\Core\Widgets\Stack;
use SystemX\Core\Widgets\SwitchWidget;
use SystemX\Core\Widgets\Tabs;
use SystemX\Core\Widgets\TextField;
use SystemX\Core\Widgets\Toolbar;
use SystemX\Core\Widgets\Tooltip;
use SystemX\Core\Wire\Serializer;

class WidgetSerializationTest extends TestCase
{
    public function test_stack_serializes_with_empty_props_as_object(): void
    {
        $result = (new Serializer)->serialize(Stack::make()->content([]));

        $this->assertSame('stack', $result['type']);
        // Empty props must encode as {} (object), not [].
        $this->assertEquals((object) [], $result['props']);
        $this->assertSame([], $result['children']);
    }

    public function test_textfield_defaults_to_submit_only_and_carries_its_value(): void
    {
        $result = (new Serializer)->serialize(TextField::make('name')->value('Jamie'));

        $this->assertSame('textfield', $result['type']);
        $this->assertSame('name', $result['props']['name']);
        $this->assertSame('Jamie', $result['props']['value']);
        $this->assertSame(['submit'], $result['props']['events']);
    }

    public function test_textfield_onchange_adds_change_to_the_events_allowlist(): void
    {
        $result = (new Serializer)->serialize(TextField::make('name')->onChange());

        $this->assertContains('change', $result['props']['events']);
    }

    public function test_list_holds_keyed_items(): void
    {
        $tree = ListWidget::make()->content([
            ListItem::make('Alpha')->key('a'),
            ListItem::make('Beta')->key('b'),
        ]);

        $result = (new Serializer)->serialize($tree);

        $this->assertSame('list', $result['type']);
        $this->assertSame('listitem', $result['children'][0]['type']);
        $this->assertSame('a', $result['children'][0]['props']['key']);
        $this->assertSame('Alpha', $result['children'][0]['props']['text']);
        $this->assertSame('b', $result['children'][1]['props']['key']);
    }

    public function test_checkbox_defaults_to_change_only_and_carries_label_and_checked(): void
    {
        $result = (new Serializer)->serialize(Checkbox::make('Subscribe')->checked(true));

        $this->assertSame('checkbox', $result['type']);
        $this->assertSame('Subscribe', $result['props']['label']);
        $this->assertTrue($result['props']['checked']);
        // A toggle is a discrete commit -- change is the default round-trip event.
        $this->assertSame(['change'], $result['props']['events']);
    }

    public function test_checkbox_defaults_to_unchecked(): void
    {
        $result = (new Serializer)->serialize(Checkbox::make('Subscribe'));

        $this->assertFalse($result['props']['checked']);
    }

    public function test_box_serializes_as_a_prop_less_row_container(): void
    {
        $result = (new Serializer)->serialize(Box::make()->content([
            Stack::make()->content([]),
        ]));

        $this->assertSame('box', $result['type']);
        $this->assertEquals((object) [], $result['props']); // prop-less -> {} not []
        $this->assertSame('stack', $result['children'][0]['type']);
    }

    public function test_grid_defaults_to_two_columns_and_the_setter_overrides_clamped(): void
    {
        $default = (new Serializer)->serialize(Grid::make()->content([]));
        $this->assertSame('grid', $default['type']);
        $this->assertSame(2, $default['props']['columns']);

        $three = (new Serializer)->serialize(Grid::make()->columns(3)->content([]));
        $this->assertSame(3, $three['props']['columns']);

        // Guard: repeat(0, 1fr) is invalid CSS -> clamp to a minimum of 1.
        $zero = (new Serializer)->serialize(Grid::make()->columns(0)->content([]));
        $this->assertSame(1, $zero['props']['columns']);
    }

    public function test_raw_carries_its_html_and_has_no_events(): void
    {
        $result = (new Serializer)->serialize(Raw::make()->html('<b>hi</b>'));

        $this->assertSame('raw', $result['type']);
        $this->assertSame('<b>hi</b>', $result['props']['html']);
        // raw is opaque to the morph and has NO interaction contract.
        $this->assertArrayNotHasKey('events', $result['props']);
        $this->assertSame([], $result['children']);
    }

    public function test_badge_carries_text_and_defaults_to_neutral_tone(): void
    {
        $result = (new Serializer)->serialize(Badge::make('New'));

        $this->assertSame('badge', $result['type']);
        $this->assertSame('New', $result['props']['text']);
        $this->assertSame('neutral', $result['props']['tone']);
        $this->assertArrayNotHasKey('events', $result['props']);
    }

    public function test_badge_tone_setter_overrides(): void
    {
        $result = (new Serializer)->serialize(Badge::make('Error')->tone('error'));
        $this->assertSame('error', $result['props']['tone']);
    }

    public function test_separator_defaults_to_horizontal(): void
    {
        $result = (new Serializer)->serialize(Separator::make());
        $this->assertSame('separator', $result['type']);
        $this->assertSame('horizontal', $result['props']['orientation']);
        $this->assertArrayNotHasKey('events', $result['props']);
    }

    public function test_separator_vertical_setter(): void
    {
        $this->assertSame('vertical', (new Serializer)->serialize(Separator::make()->vertical())['props']['orientation']);
    }

    public function test_groupbox_carries_legend_and_holds_children(): void
    {
        $tree = GroupBox::make('Settings')->content([Label::make('inside')]);
        $result = (new Serializer)->serialize($tree);
        $this->assertSame('groupbox', $result['type']);
        $this->assertSame('Settings', $result['props']['legend']);
        $this->assertSame('label', $result['children'][0]['type']);
        $this->assertArrayNotHasKey('events', $result['props']);
    }

    public function test_progressbar_defaults(): void
    {
        $result = (new Serializer)->serialize(ProgressBar::make());
        $this->assertSame('progressbar', $result['type']);
        $this->assertSame(0, $result['props']['value']);
        $this->assertFalse($result['props']['indeterminate']);
        $this->assertNull($result['props']['label']);
        $this->assertArrayNotHasKey('events', $result['props']);
    }

    public function test_progressbar_setters_and_value_clamps_0_to_100(): void
    {
        $r = (new Serializer)->serialize(ProgressBar::make()->value(150)->label('Uploading')->indeterminate());
        $this->assertSame(100, $r['props']['value']);          // clamped high
        $this->assertSame('Uploading', $r['props']['label']);
        $this->assertTrue($r['props']['indeterminate']);
        $this->assertSame(0, (new Serializer)->serialize(ProgressBar::make()->value(-5))['props']['value']); // clamped low
    }

    public function test_switch_defaults_to_change_only_and_carries_label_and_checked(): void
    {
        $result = (new Serializer)->serialize(SwitchWidget::make('Wifi')->checked(true));
        $this->assertSame('switch', $result['type']);
        $this->assertSame('Wifi', $result['props']['label']);
        $this->assertTrue($result['props']['checked']);
        $this->assertSame(['change'], $result['props']['events']);
    }

    public function test_switch_defaults_to_unchecked(): void
    {
        $this->assertFalse((new Serializer)->serialize(SwitchWidget::make('Wifi'))['props']['checked']);
    }

    public function test_switch_onchange_handler_keeps_change_in_allowlist(): void
    {
        $result = (new Serializer)->serialize(SwitchWidget::make('Wifi')->onChange(fn () => null));
        $this->assertContains('change', $result['props']['events']);
    }

    public function test_select_carries_label_options_value_and_change_default(): void
    {
        $result = (new Serializer)->serialize(
            Select::make('Theme')->options(['light' => 'Light', 'dark' => 'Dark'])->value('dark')
        );
        $this->assertSame('select', $result['type']);
        $this->assertSame('Theme', $result['props']['label']);
        $this->assertSame(['light' => 'Light', 'dark' => 'Dark'], $result['props']['options']);
        $this->assertSame('dark', $result['props']['value']);
        $this->assertSame(['change'], $result['props']['events']);
    }

    public function test_select_defaults_to_empty_options_and_value(): void
    {
        $result = (new Serializer)->serialize(Select::make('Theme'));
        $this->assertSame([], $result['props']['options']);
        $this->assertSame('', $result['props']['value']);
    }

    public function test_radiogroup_carries_options_value_and_change_default(): void
    {
        $result = (new Serializer)->serialize(
            RadioGroup::make('Size')->options(['s' => 'Small', 'l' => 'Large'])->value('l')
        );
        $this->assertSame('radiogroup', $result['type']);
        $this->assertSame('Size', $result['props']['label']);
        $this->assertSame(['s' => 'Small', 'l' => 'Large'], $result['props']['options']);
        $this->assertSame('l', $result['props']['value']);
        $this->assertSame(['change'], $result['props']['events']);
    }

    public function test_radiogroup_defaults_to_empty_options_and_value(): void
    {
        $result = (new Serializer)->serialize(RadioGroup::make('Size'));
        $this->assertSame([], $result['props']['options']);
        $this->assertSame('', $result['props']['value']);
    }

    public function test_slider_defaults_and_setters(): void
    {
        $d = (new Serializer)->serialize(Slider::make('Volume'));
        $this->assertSame('slider', $d['type']);
        $this->assertSame('Volume', $d['props']['label']);
        $this->assertSame(0, $d['props']['min']);
        $this->assertSame(100, $d['props']['max']);
        $this->assertSame(1, $d['props']['step']);
        $this->assertSame(0, $d['props']['value']);
        $this->assertSame(['change'], $d['props']['events']);

        $s = (new Serializer)->serialize(Slider::make('Volume')->min(0)->max(11)->step(1)->value(7));
        $this->assertSame(11, $s['props']['max']);
        $this->assertSame(7, $s['props']['value']);
    }

    public function test_tabs_carries_map_active_events_and_panels(): void
    {
        $tree = Tabs::make()
            ->tabs(['general' => 'General', 'advanced' => 'Advanced'])
            ->active('advanced')
            ->content([Label::make('gen panel'), Label::make('adv panel')]);
        $result = (new Serializer)->serialize($tree);

        $this->assertSame('tabs', $result['type']);
        $this->assertSame(['general' => 'General', 'advanced' => 'Advanced'], $result['props']['tabs']);
        $this->assertSame('advanced', $result['props']['active']);
        $this->assertSame(['change'], $result['props']['events']);
        $this->assertSame('label', $result['children'][0]['type']);
        $this->assertSame('adv panel', $result['children'][1]['props']['text']);
    }

    public function test_tabs_defaults_to_empty_map_and_active(): void
    {
        $result = (new Serializer)->serialize(Tabs::make());
        $this->assertSame([], $result['props']['tabs']);
        $this->assertSame('', $result['props']['active']);
    }

    public function test_tabs_onchange_keeps_change_in_allowlist(): void
    {
        $result = (new Serializer)->serialize(Tabs::make()->onChange(fn () => null));
        $this->assertContains('change', $result['props']['events']);
    }

    public function test_toolbar_is_a_propless_container_holding_children(): void
    {
        $tree = Toolbar::make()->content([Button::make('Save'), Button::make('Delete')]);
        $result = (new Serializer)->serialize($tree);
        $this->assertSame('toolbar', $result['type']);
        $this->assertEquals((object) [], $result['props']); // prop-less -> {} not [] (mirror the Box test)
        $this->assertSame('button', $result['children'][0]['type']);
    }

    public function test_dialog_carries_open_title_dismissible_and_body(): void
    {
        $tree = Dialog::make()
            ->open(true)
            ->title('Settings')
            ->dismissible(false)
            ->content([Label::make('body')])
            ->id('settings-dialog');
        $result = (new Serializer)->serialize($tree);

        $this->assertSame('dialog', $result['type']);
        $this->assertSame('settings-dialog', $result['id']);
        $this->assertTrue($result['props']['open']);
        $this->assertSame('Settings', $result['props']['title']);
        $this->assertFalse($result['props']['dismissible']);
        $this->assertSame(['close'], $result['props']['events']);
        $this->assertSame('body', $result['children'][0]['props']['text']);
    }

    public function test_dialog_defaults_are_closed_dismissible_and_empty(): void
    {
        $result = (new Serializer)->serialize(Dialog::make());
        $this->assertFalse($result['props']['open']);
        $this->assertSame('', $result['props']['title']);
        $this->assertTrue($result['props']['dismissible']);
        $this->assertSame(['close'], $result['props']['events']);
    }

    public function test_dialog_onclose_keeps_close_in_allowlist(): void
    {
        // onClose binds the 'close' event; withEvent() is idempotent so it stays a single entry.
        $result = (new Serializer)->serialize(Dialog::make()->onClose(fn () => null));
        $this->assertSame(['close'], $result['props']['events']);
    }

    public function test_menu_button_carries_label_items_and_select_event(): void
    {
        $tree = MenuButton::make('Actions')
            ->items([
                ['label' => 'Save', 'value' => 'save'],
                ['divider' => true],
                ['label' => 'Delete', 'value' => 'delete', 'danger' => true],
            ])
            ->id('actions-menu');
        $result = (new Serializer)->serialize($tree);

        $this->assertSame('menu', $result['type']);
        $this->assertSame('Actions', $result['props']['label']);
        $this->assertSame(['select'], $result['props']['events']);
        $this->assertSame('save', $result['props']['items'][0]['value']);
        $this->assertTrue($result['props']['items'][1]['divider']);
        $this->assertTrue($result['props']['items'][2]['danger']);
    }

    public function test_menu_button_defaults_to_empty_items(): void
    {
        $result = (new Serializer)->serialize(MenuButton::make('X'));
        $this->assertSame([], $result['props']['items']);
        $this->assertSame(['select'], $result['props']['events']);
    }

    public function test_menubar_carries_menus_and_select_event(): void
    {
        $tree = MenuBar::make()
            ->menus([
                ['label' => 'File', 'items' => [['label' => 'New', 'value' => 'file.new']]],
                ['label' => 'Edit', 'items' => [['label' => 'Copy', 'value' => 'edit.copy']]],
            ])
            ->id('app-menubar');
        $result = (new Serializer)->serialize($tree);

        $this->assertSame('menubar', $result['type']);
        $this->assertSame(['select'], $result['props']['events']);
        $this->assertSame('File', $result['props']['menus'][0]['label']);
        $this->assertSame('file.new', $result['props']['menus'][0]['items'][0]['value']);
    }

    public function test_menubar_defaults_to_empty_menus(): void
    {
        $result = (new Serializer)->serialize(MenuBar::make());
        $this->assertSame([], $result['props']['menus']);
        $this->assertSame(['select'], $result['props']['events']);
    }

    public function test_tooltip_carries_text_side_and_children_with_no_events(): void
    {
        $tree = Tooltip::make('Saves your work')
            ->side('right')
            ->content([Label::make('hover me')])
            ->id('save-tip');
        $result = (new Serializer)->serialize($tree);

        $this->assertSame('tooltip', $result['type']);
        $this->assertSame('save-tip', $result['id']);
        $this->assertSame('Saves your work', $result['props']['text']);
        $this->assertSame('right', $result['props']['side']);
        $this->assertSame('hover me', $result['children'][0]['props']['text']);
        $this->assertArrayNotHasKey('events', $result['props']); // display-only
    }

    public function test_tooltip_defaults_to_top_side(): void
    {
        $result = (new Serializer)->serialize(Tooltip::make('hint'));
        $this->assertSame('top', $result['props']['side']);
        $this->assertSame('hint', $result['props']['text']);
    }

    public function test_tooltip_rejects_an_unknown_side(): void
    {
        // A garbage side would stamp a data-side matching no positional CSS rule and silently
        // render the bubble on top of the wrapped child -- fail loudly at build time instead.
        $this->expectException(\InvalidArgumentException::class);
        Tooltip::make('hint')->side('diagonal');
    }

    public function test_chart_serializes_type_categories_series_height(): void
    {
        $node = Chart::make()->bar()
            ->categories(['09:00', '10:00'])
            ->series('Reads', [12, 15])
            ->series('Faults', [1, 0])
            ->height(240);

        $this->assertSame('chart', $node->type);
        $this->assertSame('bar', $node->props['type']);
        $this->assertSame(['09:00', '10:00'], $node->props['categories']);
        $this->assertSame(
            [['label' => 'Reads', 'data' => [12, 15]], ['label' => 'Faults', 'data' => [1, 0]]],
            $node->props['series'],
        );
        $this->assertSame(240, $node->props['height']);
    }

    public function test_chart_defaults_line_type_and_height(): void
    {
        $node = Chart::make();
        $this->assertSame('line', $node->props['type']);
        $this->assertSame(220, $node->props['height']);
        $this->assertSame([], $node->props['categories']);
        $this->assertSame([], $node->props['series']);
    }

    public function test_chart_type_setters_are_exclusive_last_wins(): void
    {
        $this->assertSame('area', Chart::make()->bar()->area()->props['type']);
    }

    public function test_image_serializes_src_and_alt(): void
    {
        $result = (new Serializer)->serialize(Image::make('https://x/plate.jpg')->alt('Plate'));

        $this->assertSame('image', $result['type']);
        $this->assertSame('https://x/plate.jpg', $result['props']['src']);
        $this->assertSame('Plate', $result['props']['alt']);
        $this->assertArrayNotHasKey('enlarge', $result['props']); // omitted when not enlargeable
    }

    public function test_image_enlargeable_sets_flag_and_optional_full(): void
    {
        $plain = (new Serializer)->serialize(Image::make('a.jpg')->enlargeable());
        $this->assertTrue($plain['props']['enlarge']);
        $this->assertArrayNotHasKey('full', $plain['props']); // full omitted when null -> lightbox uses src

        $withFull = (new Serializer)->serialize(Image::make('thumb.jpg')->enlargeable('full.jpg'));
        $this->assertTrue($withFull['props']['enlarge']);
        $this->assertSame('full.jpg', $withFull['props']['full']);
    }
}

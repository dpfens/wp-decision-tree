<?php
namespace BeerTree\Beer;

use BeerTree\Admin\PostFormHelpers;
use BeerTree\Repository;
use WP_Error;

/** Defines the attribute vocabulary shared by all trees. */
final class AttributesPage
{
    use PostFormHelpers;

    public const PAGE = 'bdt-attributes';

    private const TYPES = [
        'string'  => 'Text',
        'number'  => 'Number',
        'boolean' => 'Yes / No',
        'enum'    => 'Choice',
    ];

    private string $hook = '';

    public function __construct(private Repository $repo) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_bdt_add_attribute',    [$this, 'handleAdd']);
        add_action('admin_post_bdt_update_attribute', [$this, 'handleUpdate']);
        add_action('admin_post_bdt_delete_attribute', [$this, 'handleDelete']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_submenu_page(
            'bdt-trees', 'Attributes', 'Attributes', 'manage_options', self::PAGE, [$this, 'render']
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }

        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', <<<'JS'
jQuery(function ($) {
    $('[data-bdt-options]').each(function () {
        var $box  = $(this);
        var $list = $box.find('.bdt-option-list');
        var tpl   = $box.find('template')[0];

        $list.sortable({ handle: '.bdt-handle', axis: 'y', cursor: 'grabbing' });

        $box.on('click', '.bdt-add', function () {
            $list.append(tpl.content.cloneNode(true));
            $list.find('li:last-child input[type=text]').trigger('focus');
        });

        $box.on('click', '.bdt-remove', function () {
            var used = parseInt(this.dataset.used, 10) || 0;
            if (used > 0 && !window.confirm(
                used + ' beer(s) use this option. Removing it clears that value from them. Remove anyway?'
            )) {
                return;
            }
            $(this).closest('li').remove();
        });

        // Enter adds another option instead of submitting the form.
        $box.on('keydown', 'input[type=text]', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $box.find('.bdt-add').trigger('click');
            }
        });
    });

    // Only show the options editor when the type is Choice.
    var $type = $('#bdt-attr-type');
    if ($type.length) {
        var $row = $('[data-bdt-options]').closest('tr');
        var sync = function () { $row.toggle($type.val() === 'enum'); };
        $type.on('change', sync);
        sync();
    }
});
JS);

        wp_add_inline_style('common', <<<'CSS'
.bdt-option-list { margin: 0 0 .5em; max-width: 32em; }
.bdt-option { display: flex; align-items: center; gap: 8px; margin: 0 0 6px; }
.bdt-option input[type=text] { flex: 1; min-width: 0; }
.bdt-handle { cursor: grab; color: #787c82; }
CSS);
    }

    /* ---------- Handlers ---------- */

    public function handleAdd(): void
    {
        $this->requireCapability('bdt_add_attribute');

        $slug    = sanitize_key(wp_unslash($_POST['slug'] ?? ''));
        $label   = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $type    = sanitize_key(wp_unslash($_POST['value_type'] ?? ''));
        $unit    = sanitize_text_field(wp_unslash($_POST['unit'] ?? ''));
        $options = array_values(array_unique(array_column(self::postedOptions(), 'label')));

        if ($slug === '' || $label === '') {
            $result = new WP_Error('bdt_empty', 'Slug and label are required.');
        } elseif (!array_key_exists($type, self::TYPES)) {
            $result = new WP_Error('bdt_bad_type', 'Choose a valid type.');
        } elseif ($type === 'enum' && $options === []) {
            $result = new WP_Error('bdt_no_options', 'Choice attributes need at least one option.');
        } elseif ($this->repo->getAttribute($slug)) {
            $result = new WP_Error('bdt_dup', "The slug '$slug' is already in use.");
        } else {
            $result = $this->repo->defineAttribute(
                $slug, $label, $type,
                $unit !== '' ? $unit : null,
                $type === 'enum' ? $options : null
            );
        }

        $this->redirectWithNotice(self::PAGE, [], $result, 'Attribute added.');
    }

    public function handleUpdate(): void
    {
        $attributeId = absint($_POST['attribute_id'] ?? 0);
        $this->requireCapability('bdt_update_attribute_' . $attributeId);

        $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
        $unit  = sanitize_text_field(wp_unslash($_POST['unit'] ?? ''));

        $result = $label === ''
            ? new WP_Error('bdt_empty', 'Label is required.')
            : $this->repo->updateAttribute($attributeId, $label, $unit !== '' ? $unit : null, self::postedOptions());

        // Stay on the edit screen if something went wrong.
        $query = is_wp_error($result) ? ['edit' => $attributeId] : [];
        $this->redirectWithNotice(self::PAGE, $query, $result, 'Attribute updated.');
    }

    public function handleDelete(): void
    {
        $attributeId = absint($_POST['attribute_id'] ?? 0);
        $this->requireCapability('bdt_delete_attribute_' . $attributeId);

        $result = $this->repo->deleteAttribute($attributeId);
        $this->redirectWithNotice(self::PAGE, [], $result, 'Attribute deleted.');
    }

    /* ---------- Rendering ---------- */

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $editId = absint($_GET['edit'] ?? 0);
        $attr   = $editId > 0 ? $this->repo->getAttributeById($editId) : null;

        if ($attr) {
            $this->openWrap('Edit attribute', ['All attributes', $this->pageUrl()]);
            $this->renderEdit($attr);
        } else {
            $this->openWrap('Attributes');
            $this->renderList();
            $this->renderAddForm();
        }
        echo '</div>';
    }

    private function pageUrl(array $extra = []): string
    {
        return add_query_arg(['page' => self::PAGE] + $extra, admin_url('admin.php'));
    }

    private function renderList(): void
    {
        echo '<p>Attributes describe the details recorded on beers. Each is defined once and shared across all trees.</p>';

        $attributes = $this->repo->listAttributes();

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
           . '<th scope="col" class="column-primary">Label</th>'
           . '<th scope="col">Slug</th>'
           . '<th scope="col">Type</th>'
           . '<th scope="col">Unit</th>'
           . '<th scope="col">Options</th>'
           . '<th scope="col" style="width:10em">Actions</th>'
           . '</tr></thead><tbody>';

        if ($attributes === []) {
            echo $this->emptyRow(6, 'No attributes yet. Add one below.');
        }

        foreach ($attributes as $a) {
            $opts = implode(', ', json_decode((string) $a->enum_options, true) ?: []);
            echo '<tr><td class="column-primary"><strong>'
               . '<a class="row-title" href="' . esc_url($this->pageUrl(['edit' => (int) $a->id])) . '">'
               . esc_html($a->label) . '</a></strong></td>'
               . '<td><code>' . esc_html($a->slug) . '</code></td>'
               . '<td>' . esc_html(self::TYPES[$a->value_type] ?? $a->value_type) . '</td>'
               . '<td>' . esc_html((string) $a->unit) . '</td>'
               . '<td>' . esc_html($opts) . '</td><td>'
               . '<a href="' . esc_url($this->pageUrl(['edit' => (int) $a->id])) . '">Edit</a> | ';
            $this->postForm('bdt_delete_attribute', 'bdt_delete_attribute_' . (int) $a->id,
                ['attribute_id' => (int) $a->id], '', 'Delete',
                'Delete this attribute and every value stored for it?', 'delete');
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderAddForm(): void
    {
        $typeOptions = '';
        foreach (self::TYPES as $value => $label) {
            $typeOptions .= sprintf('<option value="%s">%s</option>', esc_attr($value), esc_html($label));
        }

        echo '<h2>Add attribute</h2>';
        $this->postForm('bdt_add_attribute', 'bdt_add_attribute', [],
            $this->formTable([
                ['Label', '<input type="text" id="bdt-attr-label" name="label" class="regular-text" required>',
                    'bdt-attr-label', 'Shown on screen, e.g. ABV.'],
                ['Slug', '<input type="text" id="bdt-attr-slug" name="slug" class="regular-text code" pattern="[a-z0-9_\-]+" required>',
                    'bdt-attr-slug', 'Lowercase letters, numbers, dashes and underscores, e.g. abv. Cannot be changed later.'],
                ['Type', '<select id="bdt-attr-type" name="value_type">' . $typeOptions . '</select>', 'bdt-attr-type'],
                ['Unit', '<input type="text" id="bdt-attr-unit" name="unit" class="small-text">',
                    'bdt-attr-unit', 'Optional, e.g. %.'],
                ['Choices', $this->optionsEditor([]), '', 'Drag to reorder. Used by the Choice type only.'],
            ]),
            'Add attribute', null, 'primary');
    }

    private function renderEdit(object $attr): void
    {
        $rows = [
            ['Slug', '<code>' . esc_html($attr->slug) . '</code>'],
            ['Type', esc_html(self::TYPES[$attr->value_type] ?? $attr->value_type)],
            ['Label', '<input type="text" id="bdt-attr-label" name="label" class="regular-text" required value="'
                . esc_attr($attr->label) . '">', 'bdt-attr-label'],
            ['Unit', '<input type="text" id="bdt-attr-unit" name="unit" class="small-text" value="'
                . esc_attr((string) $attr->unit) . '">', 'bdt-attr-unit'],
        ];

        if ($attr->value_type === 'enum') {
            $options = array_map('strval', json_decode((string) $attr->enum_options, true) ?: []);
            $rows[]  = [
                'Choices',
                $this->optionsEditor($options, $this->repo->enumUsage((int) $attr->id)),
                '',
                'Drag to reorder. Renaming a choice updates every beer that uses it; removing one clears it from those beers.',
            ];
        }

        $this->postForm('bdt_update_attribute', 'bdt_update_attribute_' . (int) $attr->id,
            ['attribute_id' => (int) $attr->id],
            $this->formTable($rows), 'Save changes', null, 'primary');
    }

    /* ---------- Choices editor ---------- */

    /**
     * Repeater for Choice options: drag to reorder, add, remove.
     * Each row submits options[] (current name) and original[] (name when the
     * page loaded, empty for new rows) so renames can be carried over.
     *
     * @param string[]           $options
     * @param array<string, int> $usage    option => number of beers using it
     */
    private function optionsEditor(array $options, array $usage = []): string
    {
        $rows = '';
        foreach ($options as $opt) {
            $rows .= $this->optionRow($opt, $opt, $usage[$opt] ?? 0);
        }
        if ($options === []) {
            $rows = $this->optionRow('', '', 0) . $this->optionRow('', '', 0);
        }

        return '<div class="bdt-options" data-bdt-options>'
             . '<ul class="bdt-option-list">' . $rows . '</ul>'
             . '<template>' . $this->optionRow('', '', 0) . '</template>'
             . '<button type="button" class="button bdt-add">Add option</button>'
             . '</div>';
    }

    private function optionRow(string $label, string $original, int $used): string
    {
        return '<li class="bdt-option">'
             . '<span class="dashicons dashicons-menu bdt-handle" aria-hidden="true"></span>'
             . '<input type="text" name="options[]" class="regular-text" aria-label="Option" value="' . esc_attr($label) . '">'
             . '<input type="hidden" name="original[]" value="' . esc_attr($original) . '">'
             . ($used > 0 ? '<span class="description">Used by ' . $used . '</span>' : '')
             . '<button type="button" class="button-link button-link-delete bdt-remove" data-used="' . $used . '">Remove</button>'
             . '</li>';
    }

    /**
     * Reads the posted options[] / original[] pairs. Blank rows are dropped,
     * which also means blanking an existing row removes that option.
     *
     * @return array<int, array{original:string,label:string}>
     */
    private static function postedOptions(): array
    {
        $labels    = wp_unslash($_POST['options'] ?? []);
        $originals = wp_unslash($_POST['original'] ?? []);

        if (!is_array($labels)) {
            return [];
        }
        $originals = is_array($originals) ? array_values($originals) : [];

        $rows = [];
        foreach (array_values($labels) as $i => $raw) {
            $label = is_string($raw) ? sanitize_text_field($raw) : '';
            if ($label === '') {
                continue;
            }
            $original = isset($originals[$i]) && is_string($originals[$i])
                ? sanitize_text_field($originals[$i])
                : '';
            $rows[] = ['original' => $original, 'label' => $label];
        }
        return $rows;
    }
}
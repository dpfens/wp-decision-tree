<?php
namespace ItemTree\Item;

use ItemTree\Admin\PostFormHelpers;
use ItemTree\Repository;
use WP_Error;

/** Edits attribute values for item nodes within a tree. */
final class ItemsPage
{
    use PostFormHelpers;

    public const PAGE = 'bdt-items';

    public function __construct(private Repository $repo) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_bdt_save_item', [$this, 'handleSave']);
    }

    public function menu(): void
    {
        add_submenu_page('bdt-trees', 'Items', 'Items', 'manage_options', self::PAGE, [$this, 'render']);
    }

    public function handleSave(): void
    {
        $treeId = absint($_POST['tree_id'] ?? 0);
        $nodeId = absint($_POST['node_id'] ?? 0);
        $this->requireCapability('bdt_save_item_' . $nodeId);

        $input  = wp_unslash($_POST['attr'] ?? []);
        $result = $this->saveAttributes($treeId, $nodeId, is_array($input) ? $input : []);

        $this->redirectWithNotice(self::PAGE, ['tree' => $treeId], $result, 'Item saved.');
    }

    /** @param array<string, mixed> $input  Submitted attr[] values keyed by slug */
    private function saveAttributes(int $treeId, int $nodeId, array $input): true|WP_Error
    {
        $node = $this->repo->getNode($treeId, $nodeId);
        if (!$node || $node->node_type !== ItemMenu::RESULT_TYPE) {
            return new WP_Error('bdt_not_found', 'Item not found in this tree.');
        }

        foreach ($this->repo->listAttributes() as $attr) {
            $slug = $attr->slug;

            if ($attr->value_type === 'boolean') {
                // Unchecked checkboxes send nothing, so absence means false.
                $result = $this->repo->setValue($nodeId, $slug, isset($input[$slug]) ? 1 : 0);
            } else {
                $raw = isset($input[$slug]) && is_string($input[$slug])
                    ? trim(sanitize_text_field($input[$slug]))
                    : '';
                $result = $raw === ''
                    ? $this->repo->clearValue($nodeId, $slug)
                    : $this->repo->setValue($nodeId, $slug, $raw);
            }

            if (is_wp_error($result)) {
                return $result;
            }
        }
        return true;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $treeId = absint($_GET['tree'] ?? 0);

        $this->openWrap('Items');
        $this->renderTreePicker($treeId);

        if ($treeId > 0) {
            $this->renderItems($treeId);
        }
        echo '</div>';
    }

    private function renderTreePicker(int $selected): void
    {
        $options = '<option value="0">Choose a tree</option>';
        foreach ($this->repo->listTrees() as $tree) {
            $options .= sprintf('<option value="%d"%s>%s</option>',
                (int) $tree->id, selected($selected, (int) $tree->id, false), esc_html($tree->name));
        }

        echo '<form method="get"><div class="tablenav top"><div class="alignleft actions">'
           . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '">'
           . '<label for="bdt-tree-picker" class="screen-reader-text">Tree</label>'
           . '<select id="bdt-tree-picker" name="tree">' . $options . '</select> '
           . get_submit_button('Show', 'secondary', '', false)
           . '</div></div></form><br class="clear">';
    }

    private function renderItems(int $treeId): void
    {
        $items = array_values(array_filter(
            $this->repo->listNodes($treeId),
            static fn (object $n): bool => $n->node_type === ItemMenu::RESULT_TYPE
        ));

        if ($items === []) {
            $this->infoNotice('No items in this tree yet. Add item nodes in the Decision Trees editor.');
            return;
        }

        $attributes = $this->repo->listAttributes();
        if ($attributes === []) {
            $attrUrl = add_query_arg(['page' => AttributesPage::PAGE], admin_url('admin.php'));
            printf('<div class="notice notice-info inline"><p>Define attributes first, on the <a href="%s">Attributes</a> screen.</p></div>',
                esc_url($attrUrl));
            return;
        }

        $values = $this->repo->getValuesForNodes(array_map(static fn ($b): int => (int) $b->id, $items));

        foreach ($items as $item) {
            $id      = (int) $item->id;
            $current = $values[$id] ?? [];

            $rows = [];
            foreach ($attributes as $attr) {
                $rows[] = $this->rowFor($attr, $id, $current[$attr->slug] ?? null);
            }

            echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">'
               . esc_html($item->prompt) . '</h2></div><div class="inside">';
            $this->postForm('bdt_save_item', 'bdt_save_item_' . $id,
                ['tree_id' => $treeId, 'node_id' => $id],
                $this->formTable($rows), 'Save item', null, 'primary');
            echo '</div></div>';
        }
    }

    private function infoNotice(string $message): void
    {
        printf('<div class="notice notice-info inline"><p>%s</p></div>', esc_html($message));
    }

    /**
     * One form-table row, with an input appropriate to the attribute's type.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function rowFor(object $attr, int $nodeId, mixed $value): array
    {
        $id    = 'bdt-' . $nodeId . '-' . $attr->slug;
        $name  = 'attr[' . esc_attr($attr->slug) . ']';
        $unit  = $attr->unit ? ' <span class="description">' . esc_html($attr->unit) . '</span>' : '';

        switch ($attr->value_type) {
            case 'boolean':
                $input = sprintf('<label><input type="checkbox" id="%s" name="%s" value="1"%s> Yes</label>',
                    esc_attr($id), $name, checked((bool) $value, true, false));
                break;

            case 'number':
                $input = sprintf('<input type="number" step="any" id="%s" name="%s" value="%s" class="small-text">',
                    esc_attr($id), $name, esc_attr($value === null ? '' : (string) $value)) . $unit;
                break;

            case 'enum':
                $options = '<option value="">—</option>';
                foreach (json_decode((string) $attr->enum_options, true) ?: [] as $opt) {
                    $options .= sprintf('<option value="%s"%s>%s</option>',
                        esc_attr($opt), selected((string) $value, $opt, false), esc_html($opt));
                }
                $input = sprintf('<select id="%s" name="%s">%s</select>', esc_attr($id), $name, $options) . $unit;
                break;

            default:
                $input = sprintf('<input type="text" id="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr($id), $name, esc_attr((string) ($value ?? ''))) . $unit;
        }

        return [$attr->label, $input, $id];
    }
}
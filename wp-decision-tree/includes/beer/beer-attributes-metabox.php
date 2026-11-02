<?php
namespace BeerTree\Beer;

use BeerTree\Admin\PostFormHelpers;
use BeerTree\Repository;
use WP_Post;

/** Typed attribute fields (ABV, style, ...) on the beer edit screen. */
final class BeerAttributesMetabox
{
    use PostFormHelpers;

    private const NONCE_ACTION = 'bdt_save_beer_attributes';
    private const NONCE_FIELD  = 'bdt_beer_attributes_nonce';
    private const FIELD_NAME   = 'bdt_attr';

    public function __construct(private Repository $repo) {}

    public function register(): void
    {
        add_action('add_meta_boxes_' . BeerPostType::POST_TYPE, [$this, 'addMetabox']);
        add_action('save_post_' . BeerPostType::POST_TYPE, [$this, 'save']);
        add_action('admin_notices', [$this, 'showErrors']);
    }

    public function addMetabox(): void
    {
        add_meta_box(
            'bdt-beer-attributes',
            'Beer details',
            [$this, 'render'],
            BeerPostType::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render(WP_Post $post): void
    {
        $attributes = $this->repo->listAttributes();

        if ($attributes === []) {
            $url = add_query_arg(['page' => AttributesPage::PAGE], admin_url('admin.php'));
            printf('<p>No attributes are defined yet. <a href="%s">Define attributes</a> to record details such as ABV or style.</p>',
                esc_url($url));
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $current = $this->repo->getValuesForPosts([$post->ID])[$post->ID] ?? [];

        $rows = [];
        foreach ($attributes as $attr) {
            $rows[] = $this->rowFor($attr, $current[$attr->slug] ?? null);
        }
        echo $this->formTable($rows);
    }

    public function save(int $postId): void
    {
        // Ignore quick edit, bulk edit and anything else that did not come from our box.
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($postId)) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $input = wp_unslash($_POST[self::FIELD_NAME] ?? []);
        $input = is_array($input) ? $input : [];

        $errors = [];
        foreach ($this->repo->listAttributes() as $attr) {
            $slug = $attr->slug;

            if ($attr->value_type === 'boolean') {
                // Unchecked checkboxes send nothing, so absence means false.
                $result = $this->repo->setValue($postId, $slug, isset($input[$slug]) ? 1 : 0);
            } else {
                $raw = isset($input[$slug]) && is_string($input[$slug])
                    ? trim(sanitize_text_field($input[$slug]))
                    : '';
                $result = $raw === ''
                    ? $this->repo->clearValue($postId, $slug)
                    : $this->repo->setValue($postId, $slug, $raw);
            }

            if (is_wp_error($result)) {
                $errors[] = $attr->label . ': ' . $result->get_error_message();
            }
        }

        if ($errors) {
            set_transient($this->errorKey(), $errors, 60);
        }
    }

    /** Shows validation problems from the last save on the next full page load. */
    public function showErrors(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== BeerPostType::POST_TYPE) {
            return;
        }
        $errors = get_transient($this->errorKey());
        if (!$errors) {
            return;
        }
        delete_transient($this->errorKey());

        foreach ($errors as $message) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
        }
    }

    private function errorKey(): string
    {
        return 'bdt_beer_errors_' . get_current_user_id();
    }

    /**
     * One form-table row, with an input appropriate to the attribute's type.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function rowFor(object $attr, mixed $value): array
    {
        $id   = 'bdt-attr-' . $attr->slug;
        $name = self::FIELD_NAME . '[' . esc_attr($attr->slug) . ']';
        $unit = $attr->unit ? ' <span class="description">' . esc_html($attr->unit) . '</span>' : '';

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
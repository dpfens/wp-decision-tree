<?php
namespace BeerTree\Admin;

use WP_Error;

/** Shared helpers for admin screens that save through admin-post.php. */
trait PostFormHelpers
{
    protected function requireCapability(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.', 403);
        }
        check_admin_referer($nonceAction);
    }

    /** Redirects back to a screen with a success or error notice. */
    protected function redirectWithNotice(string $page, array $query, mixed $result, string $success): void
    {
        $args = ['page' => $page] + $query;
        if (is_wp_error($result)) {
            $args['bdt_error'] = $result->get_error_message();
        } else {
            $args['bdt_notice'] = $success;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Opens the standard admin wrapper: title, optional "page title action"
     * button, the header rule that WP uses to position notices, then notices.
     * The caller echoes '</div>' when done.
     *
     * @param array{0:string,1:string}|null $action  [label, url]
     */
    protected function openWrap(string $title, ?array $action = null): void
    {
        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';
        if ($action) {
            printf(' <a href="%s" class="page-title-action">%s</a>', esc_url($action[1]), esc_html($action[0]));
        }
        echo '<hr class="wp-header-end">';
        $this->renderNotices();
    }

    protected function renderNotices(): void
    {
        if (!empty($_GET['bdt_notice'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash($_GET['bdt_notice']))));
        }
        if (!empty($_GET['bdt_error'])) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(sanitize_text_field(wp_unslash($_GET['bdt_error']))));
        }
    }

    /**
     * Builds a standard settings-style form table.
     * Each row is [label, inputHtml, inputId = '', description = ''].
     * Input HTML is trusted markup built by the caller.
     *
     * @param array<int, array{0:string,1:string,2?:string,3?:string}> $rows
     */
    protected function formTable(array $rows): string
    {
        $html = '<table class="form-table" role="presentation"><tbody>';
        foreach ($rows as $row) {
            $for  = $row[2] ?? '';
            $help = $row[3] ?? '';

            $label = $for !== ''
                ? '<label for="' . esc_attr($for) . '">' . esc_html($row[0]) . '</label>'
                : esc_html($row[0]);

            $html .= '<tr><th scope="row">' . $label . '</th><td>' . $row[1]
                   . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '')
                   . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /** The row WP list tables show when there is nothing to list. */
    protected function emptyRow(int $columns, string $message): string
    {
        return '<tr class="no-items"><td class="colspanchange" colspan="' . $columns . '">'
             . esc_html($message) . '</td></tr>';
    }

    /**
     * Builds a POST form with a nonce. $fields is trusted HTML built by the caller.
     *
     * $variant:
     *   'primary'   - blue button (main action on a form)
     *   'secondary' - grey button
     *   'delete'    - red text link, rendered inline (for table rows)
     */
    protected function postForm(
        string $hookAction,
        string $nonceAction,
        array $hidden,
        string $fields,
        string $button,
        ?string $confirm = null,
        string $variant = 'secondary'
    ): void {
        $onsubmit = $confirm
            ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\');"'
            : '';
        $style = $variant === 'delete' ? ' style="display:inline"' : '';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . $style . $onsubmit . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr($hookAction) . '">';
        wp_nonce_field($nonceAction);
        foreach ($hidden as $key => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr((string) $value));
        }
        echo $fields;

        if ($variant === 'delete') {
            printf('<button type="submit" class="button-link button-link-delete">%s</button>', esc_html($button));
        } else {
            submit_button($button, $variant === 'primary' ? 'primary' : 'secondary', '', true);
        }
        echo '</form>';
    }
}
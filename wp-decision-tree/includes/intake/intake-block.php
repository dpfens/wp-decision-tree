<?php
namespace BeerTree\Intake;

/**
 * Registers the bdt/intake block. The admin picks a tree in the editor; the
 * front end renders a small mount point that view.js drives through the REST API.
 */
final class IntakeBlock
{
    public function __construct(private IntakeStore $store) {}

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        register_block_type(BDT_DIR . 'blocks/intake', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /** @param array{treeId?:int, showMatches?:bool} $attributes */
    public function render(array $attributes): string
    {
        $treeId = absint($attributes['treeId'] ?? 0);

        if ($treeId === 0 || !$this->store->treeExists($treeId)) {
            // Visitors see nothing; people who can fix it see why.
            return current_user_can('edit_posts')
                ? '<p class="bdt-intake-notice"><em>Beer Decision Tree: choose a tree in the block settings.</em></p>'
                : '';
        }

        return sprintf(
            '<div %s data-tree-id="%d" data-endpoint="%s" data-show-matches="%d"><noscript>This questionnaire needs JavaScript.</noscript></div>',
            get_block_wrapper_attributes(['class' => 'bdt-intake']),
            $treeId,
            esc_url(rest_url('bdt/v1/intake')),
            !empty($attributes['showMatches']) ? 1 : 0
        );
    }
}

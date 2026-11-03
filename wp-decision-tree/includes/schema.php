<?php
namespace ItemTree;

final class Schema
{
    public const VERSION = '4';
    public const OPTION  = 'bdt_db_version';

    public static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $nodes   = $wpdb->prefix . 'bdt_nodes';
        $edges   = $wpdb->prefix . 'bdt_edges';
        $trees   = $wpdb->prefix . 'bdt_trees';

        dbDelta("CREATE TABLE $trees (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id)
) $charset;");

        // dbDelta is strict: two spaces before PRIMARY KEY, no inline SQL comments.
        //
        // Question nodes carry their text in `prompt`. Result nodes (items)
        // leave `prompt` empty and point at a bdt_item post through `post_id`,
        // which holds the title, rich content and image. An item can appear
        // once per tree; NULL post_ids (questions) do not collide in the
        // unique key.
        dbDelta("CREATE TABLE $nodes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tree_id bigint(20) unsigned NOT NULL,
  node_type varchar(50) NOT NULL DEFAULT 'question',
  prompt text NULL,
  post_id bigint(20) unsigned NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY tree_post (tree_id, post_id),
  KEY post_id (post_id)
) $charset;");

        // `filters` is a JSON list of attribute conditions the answer adds, e.g.
        // [{"attr":"abv","op":"<=","value":5}]. NULL means the answer filters nothing.
        dbDelta("CREATE TABLE $edges (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tree_id bigint(20) unsigned NOT NULL,
  from_node_id bigint(20) unsigned NOT NULL,
  to_node_id bigint(20) unsigned NOT NULL,
  answer_label varchar(255) NOT NULL DEFAULT '',
  filters text NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY from_to (from_node_id, to_node_id),
  KEY tree_id (tree_id),
  KEY to_node_id (to_node_id)
) $charset;");

        $attrs  = $wpdb->prefix . 'bdt_attributes';
        $values = $wpdb->prefix . 'bdt_item_values';

        dbDelta("CREATE TABLE $attrs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(64) NOT NULL,
  label varchar(255) NOT NULL,
  value_type varchar(20) NOT NULL DEFAULT 'string',
  unit varchar(20) NULL,
  enum_options text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $charset;");

        // Attribute values belong to an item post, not to a tree node.
        dbDelta("CREATE TABLE $values (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  attribute_id bigint(20) unsigned NOT NULL,
  value_string varchar(255) NULL,
  value_number decimal(12,4) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY post_attr (post_id, attribute_id),
  KEY attr_number (attribute_id, value_number),
  KEY attr_string (attribute_id, value_string(191))
) $charset;");

        $sessions  = $wpdb->prefix . 'bdt_sessions';
        $responses = $wpdb->prefix . 'bdt_responses';
        $picks     = $wpdb->prefix . 'bdt_picks';

        dbDelta("CREATE TABLE $sessions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tree_id bigint(20) unsigned NOT NULL,
  token_hash char(64) NOT NULL,
  current_node_id bigint(20) unsigned NULL,
  started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY tree_id (tree_id),
  KEY last_activity (last_activity)
) $charset;");

        dbDelta("CREATE TABLE $responses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  tree_id bigint(20) unsigned NOT NULL,
  from_node_id bigint(20) unsigned NOT NULL,
  edge_id bigint(20) unsigned NOT NULL,
  to_node_id bigint(20) unsigned NOT NULL,
  answered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY session_question (session_id, from_node_id),
  KEY edge_id (edge_id),
  KEY tree_answered (tree_id, answered_at)
) $charset;");

        dbDelta("CREATE TABLE $picks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL,
  tree_id bigint(20) unsigned NOT NULL,
  node_id bigint(20) unsigned NOT NULL,
  picked_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY node_id (node_id),
  KEY tree_picked (tree_id, picked_at)
) $charset;");

        update_option(self::OPTION, self::VERSION);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option(self::OPTION) !== self::VERSION) {
            self::install();
        }
    }
}
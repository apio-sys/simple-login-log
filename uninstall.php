<?php
/**
 * Uninstall Simple Login Log
 *
 * Removes all plugin data when the plugin is uninstalled
 *
 * @package Simple Login Log
 */

// Exit if accessed directly or not uninstalling
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit();
}

global $wpdb;

// Delete custom table
$table_name = $wpdb->prefix . 'simple_login_log';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall requires dropping custom table
$sql = "DROP TABLE IF EXISTS {$table_name}";
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is internal, DROP TABLE required for uninstall
$wpdb->query( $sql );

// Delete plugin options
delete_option( 'sll_db_ver' );
delete_option( 'simple_login_log' );

// Remove scheduled cron jobs
wp_clear_scheduled_hook( 'truncate_sll' );
 

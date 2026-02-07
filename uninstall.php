<?php

// If uninstall not called from WordPress, then exit.
if (!defined("WP_UNINSTALL_PLUGIN")) {
  exit();
}

global $wpdb;

// Drop the email log table.
$table_name = $wpdb->prefix . "ahasend_email_log";
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table_name));

// Delete plugin options.
delete_option("ahasend_api_key");
delete_option("ahasend_account_id");
delete_option("ahasend_from_email");
delete_option("ahasend_from_name");
delete_option("ahasend_reply_to_email");
delete_option("ahasend_reply_to_name");
delete_option("ahasend_reply_to_force");

// Clear scheduled cron event.
wp_clear_scheduled_hook("ahasend_log_cleanup");

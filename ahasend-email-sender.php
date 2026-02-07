<?php
/**
 * Plugin Name: Ahasend Email Sender
 * Description: Sends WordPress emails using the Ahasend API and logs the sent emails and their status. Logs are cleared monthly.
 * Version: 2.1
 * Author: Chris Hawes
 * Author URI: https://ghostfishing.co.uk
 * Plugin URI: https://github.com/ghostfishing/ahasend-wordpress-plugin
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: ahasend-email-sender
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

// Prevent direct access to the script
defined("ABSPATH") or die("Denied.");

class AhasendEmailSender
{
  private $ahasend_api_key;
  private $ahasend_account_id;
  private $ahasend_from_email;
  private $ahasend_from_name;
  private $ahasend_reply_to_email;
  private $ahasend_reply_to_name;
  private $ahasend_reply_to_force;

  public function __construct()
  {
    $this->ahasend_api_key = get_option("ahasend_api_key");
    $this->ahasend_account_id = get_option("ahasend_account_id");
    $this->ahasend_from_email = get_option("ahasend_from_email");
    $this->ahasend_from_name = get_option("ahasend_from_name");
    $this->ahasend_reply_to_email = get_option("ahasend_reply_to_email");
    $this->ahasend_reply_to_name = get_option("ahasend_reply_to_name");
    $this->ahasend_reply_to_force = get_option("ahasend_reply_to_force");
    add_filter("pre_wp_mail", [$this, "override_wp_mail"], 10, 2);
    add_action("admin_menu", [$this, "add_admin_menu"]);
    add_action("ahasend_log_cleanup", [$this, "clean_old_logs"]);
    register_activation_hook(__FILE__, [$this, "activate"]);
    register_deactivation_hook(__FILE__, [$this, "deactivate"]);
  }

  public function activate()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            recipient varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            status varchar(20) NOT NULL,
            response text,
            message_id varchar(255) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql);

    if (!wp_next_scheduled("ahasend_log_cleanup")) {
      wp_schedule_event(time(), "ahasend_monthly", "ahasend_log_cleanup");
    }
  }

  public function deactivate()
  {
    wp_clear_scheduled_hook("ahasend_log_cleanup");
  }

  public function override_wp_mail($null, $atts)
  {
    if (empty($this->ahasend_api_key) || empty($this->ahasend_account_id)) {
      return null;
    }

    $to = $atts["to"];
    $subject = $atts["subject"];
    $message = $atts["message"];
    $headers = isset($atts["headers"]) ? $atts["headers"] : [];

    $recipients = [];
    if (is_array($to)) {
      foreach ($to as $recipient_email) {
        $recipients[] = [
          "name" => "",
          "email" => $recipient_email,
        ];
      }
    } else {
      $recipients[] = [
        "name" => "",
        "email" => $to,
      ];
    }

    $data = [
      "from" => [
        "name" => $this->ahasend_from_name,
        "email" => $this->ahasend_from_email,
      ],
      "recipients" => $recipients,
      "subject" => $subject,
      "text_content" => $message,
      "html_content" => nl2br($message),
    ];

    // Set default reply-to from settings
    if ($this->ahasend_reply_to_email) {
      $data["reply_to"] = ["email" => $this->ahasend_reply_to_email];
      if ($this->ahasend_reply_to_name) {
        $data["reply_to"]["name"] = $this->ahasend_reply_to_name;
      }
    }

    // Parse headers for Reply-To (override default unless force is on)
    if (!$this->ahasend_reply_to_force) {
      $header_lines = is_array($headers) ? $headers : explode("\n", $headers);
      foreach ($header_lines as $header_line) {
        $header_line = trim($header_line);
        if (preg_match('/^Reply-To:\s*(.+)$/i', $header_line, $matches)) {
          $reply_to_value = trim($matches[1]);
          if (preg_match('/^(.+)<(.+)>$/', $reply_to_value, $parts)) {
            $data["reply_to"] = [
              "name" => trim($parts[1]),
              "email" => trim($parts[2]),
            ];
          } else {
            $data["reply_to"] = [
              "email" => $reply_to_value,
            ];
          }
          break;
        }
      }
    }

    $url = "https://api.ahasend.com/v2/accounts/{$this->ahasend_account_id}/messages";

    $response = wp_remote_post($url, [
      "headers" => [
        "Authorization" => "Bearer " . $this->ahasend_api_key,
        "Content-Type" => "application/json",
        "Idempotency-Key" => wp_generate_uuid4(),
      ],
      "body" => wp_json_encode($data),
    ]);

    $message_id = "";
    if (is_wp_error($response)) {
      $status = "failed";
      $response_body = $response->get_error_message();
    } else {
      $response_body = wp_remote_retrieve_body($response);
      $status_code = wp_remote_retrieve_response_code($response);
      if ($status_code >= 200 && $status_code < 300) {
        $status = "success";
        $decoded = json_decode($response_body, true);
        if (isset($decoded["data"]) && is_array($decoded["data"])) {
          $ids = array_column($decoded["data"], "id");
          $message_id = implode(",", $ids);
        }
      } else {
        $status = "failed";
      }
    }

    $this->log_email(
      implode(",", array_column($recipients, "email")),
      $subject,
      $status,
      $response_body,
      $message_id
    );

    return true;
  }

  public function log_email($recipient, $subject, $status, $response, $message_id = "")
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert($table_name, [
      "time" => current_time("mysql"),
      "recipient" => $recipient,
      "subject" => $subject,
      "status" => $status,
      "response" => $response,
      "message_id" => $message_id,
    ]);
  }

  public function add_admin_menu()
  {
    add_menu_page(
      __("Ahasend Email Log", "ahasend-email-sender"),
      __("Ahasend Log", "ahasend-email-sender"),
      "manage_options",
      "ahasend-log",
      [$this, "display_log"]
    );
    add_submenu_page(
      "ahasend-log",
      __("Ahasend Settings", "ahasend-email-sender"),
      __("Settings", "ahasend-email-sender"),
      "manage_options",
      "ahasend-settings",
      [$this, "display_settings_page"]
    );
    add_submenu_page(
      "ahasend-log",
      __("Send Test Email", "ahasend-email-sender"),
      __("Send Test Email", "ahasend-email-sender"),
      "manage_options",
      "ahasend-send-test",
      [$this, "display_send_test_page"]
    );
  }

  public function display_log()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM %i ORDER BY time DESC", $table_name)
    );
    echo '<div class="wrap"><h2>' . esc_html__("Ahasend Email Log", "ahasend-email-sender") . '</h2><table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>' . esc_html__("Time", "ahasend-email-sender") . '</th><th>' . esc_html__("Recipient", "ahasend-email-sender") . '</th><th>' . esc_html__("Subject", "ahasend-email-sender") . '</th><th>' . esc_html__("Status", "ahasend-email-sender") . '</th><th>' . esc_html__("Message ID", "ahasend-email-sender") . '</th><th>' . esc_html__("Response", "ahasend-email-sender") . '</th></tr></thead><tbody>';
    foreach ($results as $row) {
      $message_id = isset($row->message_id) ? $row->message_id : "";
      echo '<tr><td>' . esc_html($row->time) . '</td><td>' . esc_html($row->recipient) . '</td><td>' . esc_html($row->subject) . '</td><td>' . esc_html($row->status) . '</td><td>' . esc_html($message_id) . '</td><td>' . esc_html($row->response) . '</td></tr>';
    }
    echo "</tbody></table></div>";
  }

  public function display_settings_page()
  {
    if (
      isset($_POST["ahasend_settings_nonce"]) &&
      wp_verify_nonce(sanitize_text_field(wp_unslash($_POST["ahasend_settings_nonce"])), "ahasend_save_settings") &&
      current_user_can("manage_options")
    ) {
      update_option(
        "ahasend_api_key",
        isset($_POST["ahasend_api_key"]) ? sanitize_text_field(wp_unslash($_POST["ahasend_api_key"])) : "",
        false
      );
      update_option(
        "ahasend_account_id",
        isset($_POST["ahasend_account_id"]) ? sanitize_text_field(wp_unslash($_POST["ahasend_account_id"])) : "",
        false
      );
      update_option(
        "ahasend_from_email",
        isset($_POST["ahasend_from_email"]) ? sanitize_email(wp_unslash($_POST["ahasend_from_email"])) : "",
        false
      );
      update_option(
        "ahasend_from_name",
        isset($_POST["ahasend_from_name"]) ? sanitize_text_field(wp_unslash($_POST["ahasend_from_name"])) : "",
        false
      );
      update_option(
        "ahasend_reply_to_email",
        isset($_POST["ahasend_reply_to_email"]) ? sanitize_email(wp_unslash($_POST["ahasend_reply_to_email"])) : "",
        false
      );
      update_option(
        "ahasend_reply_to_name",
        isset($_POST["ahasend_reply_to_name"]) ? sanitize_text_field(wp_unslash($_POST["ahasend_reply_to_name"])) : "",
        false
      );
      update_option(
        "ahasend_reply_to_force",
        isset($_POST["ahasend_reply_to_force"]) ? "1" : "",
        false
      );
      echo '<div class="updated"><p>' . esc_html__("Settings saved.", "ahasend-email-sender") . '</p></div>';
    }

    $ahasend_api_key = get_option("ahasend_api_key", "");
    $ahasend_account_id = get_option("ahasend_account_id", "");
    $ahasend_from_email = get_option("ahasend_from_email", "");
    $ahasend_from_name = get_option("ahasend_from_name", "");
    $ahasend_reply_to_email = get_option("ahasend_reply_to_email", "");
    $ahasend_reply_to_name = get_option("ahasend_reply_to_name", "");
    $ahasend_reply_to_force = get_option("ahasend_reply_to_force", "");
    echo '<div class="wrap"><h2>' . esc_html__("Ahasend Settings", "ahasend-email-sender") . '</h2><form method="post" action="">';
    wp_nonce_field("ahasend_save_settings", "ahasend_settings_nonce");
    echo '<table class="form-table">';
    echo '<tr valign="top"><th scope="row">' . esc_html__("API Key", "ahasend-email-sender") . '</th><td><input type="password" name="ahasend_api_key" value="' .
      esc_attr($ahasend_api_key) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("Account ID", "ahasend-email-sender") . '</th><td><input type="text" name="ahasend_account_id" value="' .
      esc_attr($ahasend_account_id) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("From Email", "ahasend-email-sender") . '</th><td><input type="email" name="ahasend_from_email" value="' .
      esc_attr($ahasend_from_email) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("From Name", "ahasend-email-sender") . '</th><td><input type="text" name="ahasend_from_name" value="' .
      esc_attr($ahasend_from_name) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("Reply-To Email", "ahasend-email-sender") . '</th><td><input type="email" name="ahasend_reply_to_email" value="' .
      esc_attr($ahasend_reply_to_email) .
      '" class="regular-text"><p class="description">' . esc_html__("Default Reply-To address. Leave blank to disable.", "ahasend-email-sender") . '</p></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("Reply-To Name", "ahasend-email-sender") . '</th><td><input type="text" name="ahasend_reply_to_name" value="' .
      esc_attr($ahasend_reply_to_name) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">' . esc_html__("Force Reply-To", "ahasend-email-sender") . '</th><td><label><input type="checkbox" name="ahasend_reply_to_force" value="1"' .
      checked($ahasend_reply_to_force, "1", false) .
      '> ' . esc_html__("Always use the Reply-To above, ignoring any Reply-To headers set by WordPress or plugins.", "ahasend-email-sender") . '</label></td></tr>';
    echo "</table>";
    echo '<p class="submit"><input type="submit" class="button-primary" value="' . esc_attr__("Save Changes", "ahasend-email-sender") . '"></p></form></div>';
  }

  public function display_send_test_page()
  {
    if (
      isset($_POST["ahasend_test_nonce"]) &&
      wp_verify_nonce(sanitize_text_field(wp_unslash($_POST["ahasend_test_nonce"])), "ahasend_send_test") &&
      current_user_can("manage_options") &&
      isset($_POST["ahasend_test_email"])
    ) {
      $to = sanitize_email(wp_unslash($_POST["ahasend_test_email"]));
      $subject = __("Test Email from Ahasend Plugin", "ahasend-email-sender");
      $body = __("This is a test email sent from the Ahasend Email Sender plugin.", "ahasend-email-sender");

      $headers = [
        "From: " .
        $this->ahasend_from_name .
        " <" .
        $this->ahasend_from_email .
        ">",
      ];

      $sent = wp_mail($to, $subject, $body, $headers);

      if ($sent) {
        echo '<div class="updated"><p>' .
          /* translators: %s: recipient email address */
          sprintf(esc_html__("Test email sent to %s.", "ahasend-email-sender"), esc_html($to)) .
          "</p></div>";
      } else {
        echo '<div class="error"><p>' . esc_html__("Failed to send test email.", "ahasend-email-sender") . '</p></div>';
      }
    }
    echo '<div class="wrap"><h2>' . esc_html__("Send Test Email", "ahasend-email-sender") . '</h2><form method="post" action="">';
    wp_nonce_field("ahasend_send_test", "ahasend_test_nonce");
    echo '<table class="form-table">';
    echo '<tr valign="top"><th scope="row">' . esc_html__("Test Email Address", "ahasend-email-sender") . '</th><td><input type="email" name="ahasend_test_email" value="" class="regular-text"></td></tr>';
    echo "</table>";
    echo '<p class="submit"><input type="submit" class="button-primary" value="' . esc_attr__("Send Test Email", "ahasend-email-sender") . '"></p></form></div>';
  }

  public function clean_old_logs()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
      $wpdb->prepare("DELETE FROM %i WHERE time < NOW() - INTERVAL 1 MONTH", $table_name)
    );
  }
}

new AhasendEmailSender();

add_filter("cron_schedules", function ($schedules) {
  $schedules["ahasend_monthly"] = [
    "interval" => 2592000, // 30 days in seconds
    "display" => __("Once Monthly", "ahasend-email-sender"),
  ];
  return $schedules;
});

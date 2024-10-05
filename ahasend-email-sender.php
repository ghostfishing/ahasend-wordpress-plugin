<?php
/**
 * Plugin Name: Ahasend Email Sender
 * Description: Sends WordPress emails using the Ahasend API and logs the sent emails and their status. Logs are cleared monthly.
 * Version: 1.0
 * Author: Chris Hawes <chris.hawes@ghostfishing.co.uk>
 */

// Prevent direct access to the script
defined("ABSPATH") or die("Denied.");

class AhasendEmailSender
{
  private $ahasend_api_key;
  private $ahasend_from_email;
  private $ahasend_from_name;

  public function __construct()
  {
    $this->ahasend_api_key = get_option("ahasend_api_key");
    $this->ahasend_from_email = get_option("ahasend_from_email");
    $this->ahasend_from_name = get_option("ahasend_from_name");
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
            PRIMARY KEY  (id)
        ) $charset_collate;";

    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    dbDelta($sql);

    if (!wp_next_scheduled("ahasend_log_cleanup")) {
      wp_schedule_event(time(), "monthly", "ahasend_log_cleanup");
    }
  }

  public function deactivate()
  {
    wp_clear_scheduled_hook("ahasend_log_cleanup");
  }

  public function override_wp_mail($null, $atts)
  {
    $to = $atts["to"];
    $subject = $atts["subject"];
    $message = $atts["message"];

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
      "recipients" => $recipients,
      "content" => [
        "subject" => $subject,
        "text_body" => $message,
        "html_body" => nl2br($message),
      ],
      "from" => [
        "name" => $this->ahasend_from_name,
        "email" => $this->ahasend_from_email,
      ],
    ];

    $response = wp_remote_post("https://api.ahasend.com/v1/email/send", [
      "headers" => [
        "X-API-KEY" => $this->ahasend_api_key,
        "Content-Type" => "application/json",
      ],
      "body" => wp_json_encode($data),
    ]);

    $status = is_wp_error($response) ? "failed" : "success";
    $response_body = is_wp_error($response)
      ? $response->get_error_message()
      : wp_remote_retrieve_body($response);
    $this->log_email(
      implode(",", array_column($recipients, "email")),
      $subject,
      $status,
      $response_body
    );

    return true;
  }

  public function log_email($recipient, $subject, $status, $response)
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    $wpdb->insert($table_name, [
      "time" => current_time("mysql"),
      "recipient" => $recipient,
      "subject" => $subject,
      "status" => $status,
      "response" => $response,
    ]);
  }

  public function add_admin_menu()
  {
    add_menu_page(
      "Ahasend Email Log",
      "Ahasend Log",
      "manage_options",
      "ahasend-log",
      [$this, "display_log"]
    );
    add_submenu_page(
      "ahasend-log",
      "Ahasend Settings",
      "Settings",
      "manage_options",
      "ahasend-settings",
      [$this, "display_settings_page"]
    );
    add_submenu_page(
      "ahasend-log",
      "Send Test Email",
      "Send Test Email",
      "manage_options",
      "ahasend-send-test",
      [$this, "display_send_test_page"]
    );
  }

  public function display_log()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    $results = $wpdb->get_results(
      "SELECT * FROM $table_name ORDER BY time DESC"
    );
    echo '<div class="wrap"><h2>Ahasend Email Log</h2><table class="wp-list-table widefat fixed striped">';
    echo "<thead><tr><th>Time</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Response</th></tr></thead><tbody>";
    foreach ($results as $row) {
      echo "<tr><td>{$row->time}</td><td>{$row->recipient}</td><td>{$row->subject}</td><td>{$row->status}</td><td>{$row->response}</td></tr>";
    }
    echo "</tbody></table></div>";
  }

  public function display_settings_page()
  {
    if (
      isset($_POST["ahasend_api_key"]) ||
      isset($_POST["ahasend_from_email"]) ||
      isset($_POST["ahasend_from_name"])
    ) {
      update_option(
        "ahasend_api_key",
        sanitize_text_field($_POST["ahasend_api_key"])
      );
      update_option(
        "ahasend_from_email",
        sanitize_email($_POST["ahasend_from_email"])
      );
      update_option(
        "ahasend_from_name",
        sanitize_text_field($_POST["ahasend_from_name"])
      );
      echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $ahasend_api_key = get_option("ahasend_api_key", "");
    $ahasend_from_email = get_option("ahasend_from_email", "");
    $ahasend_from_name = get_option("ahasend_from_name", "");
    echo '<div class="wrap"><h2>Ahasend Settings</h2><form method="post" action="">';
    echo '<table class="form-table">';
    echo '<tr valign="top"><th scope="row">API Key</th><td><input type="text" name="ahasend_api_key" value="' .
      esc_attr($ahasend_api_key) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">From Email</th><td><input type="email" name="ahasend_from_email" value="' .
      esc_attr($ahasend_from_email) .
      '" class="regular-text"></td></tr>';
    echo '<tr valign="top"><th scope="row">From Name</th><td><input type="text" name="ahasend_from_name" value="' .
      esc_attr($ahasend_from_name) .
      '" class="regular-text"></td></tr>';
    echo "</table>";
    echo '<p class="submit"><input type="submit" class="button-primary" value="Save Changes"></p></form></div>';
  }

  public function display_send_test_page()
  {
    if (isset($_POST["ahasend_test_email"])) {
      $to = sanitize_email($_POST["ahasend_test_email"]);
      $subject = "Test Email from Ahasend Plugin";
      $body = "This is a test email sent from the Ahasend Email Sender plugin.";

      $headers = [
        "From: " .
        $this->ahasend_from_name .
        " <" .
        $this->ahasend_from_email .
        ">",
      ];

      $sent = wp_mail($to, $subject, $body, $headers);

      if ($sent) {
        echo '<div class="updated"><p>Test email sent to ' .
          esc_html($to) .
          ".</p></div>";
      } else {
        echo '<div class="error"><p>Failed to send test email.</p></div>';
      }
    }
    echo '<div class="wrap"><h2>Send Test Email</h2><form method="post" action="">';
    echo '<table class="form-table">';
    echo '<tr valign="top"><th scope="row">Test Email Address</th><td><input type="email" name="ahasend_test_email" value="" class="regular-text"></td></tr>';
    echo "</table>";
    echo '<p class="submit"><input type="submit" class="button-primary" value="Send Test Email"></p></form></div>';
  }

  public function clean_old_logs()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . "ahasend_email_log";
    $wpdb->query(
      "DELETE FROM $table_name WHERE time < NOW() - INTERVAL 1 MONTH"
    );
  }
}

new AhasendEmailSender();

// Add monthly recurrence schedule
add_filter("cron_schedules", function ($schedules) {
  $schedules["monthly"] = [
    "interval" => 2592000, // 30 days in seconds
    "display" => __("Once Monthly"),
  ];
  return $schedules;
});

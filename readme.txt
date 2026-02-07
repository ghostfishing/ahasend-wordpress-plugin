=== Ahasend Email Sender ===
Contributors: chrishawes
Tags: email, smtp, ahasend, transactional, mail
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 2.1
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Sends all WordPress emails through the Ahasend API with logging and status tracking.

== Description ==

Ahasend Email Sender overrides `wp_mail()` to route all outgoing email through the Ahasend v2 API. Every sent email is logged with its recipient, subject, status, message ID, and raw API response.

Features:

* Replaces default WordPress email delivery with the Ahasend API
* Configurable From address, Reply-To address, and Force Reply-To option
* Email log with status tracking viewable in the admin dashboard
* Send test emails from the admin panel
* Automatic monthly log cleanup

== Third-Party Service ==

This plugin sends all WordPress emails through the **Ahasend** transactional email API. When an email is triggered by WordPress, the following data is transmitted to Ahasend's servers at `api.ahasend.com`:

* Recipient email address(es)
* Email subject line
* Email message body (text and HTML)
* Sender name and email address
* Reply-To name and email address (if configured)

An Ahasend account is required to use this plugin. You can create one at [ahasend.com](https://ahasend.com).

* [Ahasend Terms of Service](https://ahasend.com/terms)
* [Ahasend Privacy Policy](https://ahasend.com/privacy)

This plugin is not affiliated with or endorsed by Ahasend.

== Installation ==

1. Upload the `ahasend-email-sender` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Ahasend Log > Settings** and enter your API Key, Account ID, From Email, and From Name.

On activation the plugin creates a database table for email logging. No further setup is needed beyond configuring your settings.

== Configuration ==

Go to **Ahasend Log > Settings** in the WordPress admin and fill in:

* **API Key** - Your Ahasend API key (used as a Bearer token).
* **Account ID** - Your Ahasend account ID (used in the API endpoint URL).
* **From Email** - The sender email address for all outgoing mail.
* **From Name** - The sender display name.
* **Reply-To Email** - Default Reply-To address. Leave blank to disable.
* **Reply-To Name** - Display name for the Reply-To address.
* **Force Reply-To** - When checked, always uses the Reply-To from settings, ignoring any Reply-To headers set by other plugins.

== Frequently Asked Questions ==

= How do I test that the plugin is working? =

Go to **Ahasend Log > Send Test Email**, enter an email address, and send a test message. Check the **Ahasend Log** page to see if it was delivered successfully.

= Where can I find my Ahasend API key and Account ID? =

Log in to your Ahasend dashboard at [ahasend.com](https://ahasend.com) to find your API key and Account ID.

= How long are email logs kept? =

Logs are automatically cleared every 30 days.

== Changelog ==

= 2.1 =
* Added nonce verification and input sanitization to settings and test email forms
* Added output escaping to email log display
* Used prepared statements for all database queries
* Added complete plugin header fields (License, Text Domain, etc.)
* Added readme.txt for WordPress Plugin Directory compatibility

= 2.0 =
* Updated to Ahasend v2 API
* Added Reply-To settings with Force Reply-To option
* Added Account ID setting

= 1.0 =
* Initial release

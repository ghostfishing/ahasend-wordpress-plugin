# WordPress Plugin for Sending Emails with AhaSend

A WordPress plugin that overrides `wp_mail()` to send all outgoing email through the AhaSend v2 API, with logging and status tracking.

## Installation

1. Download or clone this repository.
2. Copy the folder into `wp-content/plugins/` on your WordPress installation.
3. Activate the plugin from the **Plugins** page in the WordPress admin.

On activation the plugin creates a database table for email logging. No further setup is needed beyond configuring your settings.

## Updating

When updating a manually installed plugin:

1. Deactivate the plugin from the **Plugins** page.
2. Replace the plugin folder in `wp-content/plugins/` with the new version.
3. Activate the plugin again.

Reactivating runs the activation hook, which uses `dbDelta()` to apply any database schema changes (e.g. new columns) without losing existing data.

## Configuration

Go to **AhaSend Log > Settings** in the WordPress admin and fill in:

| Setting | Description |
|---|---|
| **API Key** | Your AhaSend API key (used as a Bearer token). |
| **Account ID** | Your AhaSend account ID (used in the API endpoint URL). |
| **From Email** | The sender email address for all outgoing mail. |
| **From Name** | The sender display name. |
| **Reply-To Email** | Default Reply-To address. Leave blank to disable. |
| **Reply-To Name** | Display name for the Reply-To address. |
| **Force Reply-To** | When checked, always uses the Reply-To from settings, ignoring any Reply-To headers set by WordPress or other plugins. |

## Usage

Once configured, all WordPress emails sent via `wp_mail()` are automatically routed through the AhaSend API. No code changes are needed in your theme or other plugins.

### Test Email

Go to **AhaSend Log > Send Test Email** to verify your configuration by sending a test message.

### Logging

All sent emails are logged with their recipient, subject, status, message ID, and raw API response. View the log at **AhaSend Log** in the admin menu.

Logs are automatically cleared every 30 days.

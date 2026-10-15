=== Pandora Cloud Monitoring ===
Contributors: articast
Tags: monitoring, uptime, observability, metrics, security, performance, site health, alerts, notifications, maintenance
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor your WordPress with Pandora Cloud: versions, updates, performance, content and security — with a local history and charts.

== Description ==

**Pandora Cloud Monitoring** watches what matters in your WordPress and shows it at a glance. It works **locally out of the box** (it stores a history and draws charts inside your own WordPress, no account needed) and, optionally, connects to **Pandora Cloud** to add independent monitoring and alerts.

**What it monitors**

* **Core and patches**: WordPress version, pending core update, PHP version, MySQL/MariaDB version.
* **Plugins and themes**: how many are installed/active, how many have updates available, and **who changed a plugin and when** (installed, activated, updated, deleted).
* **Performance**: response time (TTFB), PHP memory usage (% of the limit), database size, `wp_options` autoload size, object cache, overdue WP-Cron tasks and recent PHP errors.
* **Content**: published/draft/scheduled posts, pending/spam comments and users.
* **Security**: XML-RPC enabled, open user registration, file editor enabled, `WP_DEBUG`.

**Why connect to Pandora Cloud**

* **Independent monitoring**: even if your WordPress or its hosting goes down, Pandora Cloud keeps checking from the outside and alerts you.
* **Alerts** over email, SMS, Telegram, Slack, Teams or webhook, with on-call escalation.
* **All your WordPress sites on one screen**: history, availability (SLA) and incidents in the same dashboard.

**What it stores locally**

A history of the collected metrics (last 30 days) in a custom table, plus the list of recent plugin changes. Nothing is sent anywhere unless you connect to Pandora Cloud.

**How connection works**

You enter the email and password of your Pandora Cloud account **once**. The plugin obtains a **scoped access token** bound only to this site and **does not store your password**. From then on it sends the collected metrics and a heartbeat every 5 minutes.

== External service ==

This plugin connects to Pandora FMS services:

* **Cloud Master** — `https://cloudmaster.pandorafms.com`
  Used **only when you connect the plugin**, to locate your Pandora Cloud node from your email and password. The password is sent over HTTPS exclusively to validate your account and obtain an access token; it is **not stored** by the plugin.
* **Your Pandora Cloud node** — the URL shown after connecting (e.g. `https://your-host/your-instance`)
  The plugin sends the collected metrics and a periodic heartbeat, authenticated with a **scoped token** that only allows reading and writing the data of this site's agent.

**Data sent**: WordPress/PHP/MySQL versions, update and plugin/theme counters, content counters, performance and security flags, and the recent plugin-change log. **No visitor or personal data is collected.** No data is sent until you connect the plugin.

The plugin is fully functional **without** connecting (local history and charts).

* Legal information & licenses: https://pandorafms.com/en/legal-information-licenses/
* Privacy Policy: https://pandorafms.com/en/privacy-policy/
* Source code: https://github.com/slerena/pandorafms-WP

== Installation ==

1. Upload the plugin to your site (Plugins → Add New → Upload Plugin) and activate it.
2. Go to the **Pandora Cloud** menu.
3. (Optional) Connect with your Pandora Cloud account: enter your email and password.
4. Choose what to monitor and save. Metrics are collected every 5 minutes.

== Frequently Asked Questions ==

= Does it work without a Pandora Cloud account? =

Yes. The plugin collects the metrics and keeps a local history with charts on its own. Connecting to Pandora Cloud is optional and only adds independent monitoring, alerts and the multi-site dashboard.

= Do you store my Pandora Cloud password? =

No. It is used once, over HTTPS, to obtain a scoped access token; the password is not saved.

= Does it collect visitor data? =

No. Only technical and content counters of your own site.

== Screenshots ==

1. Connect your site to Pandora Cloud with your account.
2. Choose what to monitor (grouped by topic).
3. Local history with charts and status.
4. Your WordPress site monitored in the Pandora Cloud dashboard.

== Changelog ==

= 1.0.4 =
* Send a human-readable description with each module.
* PHP memory usage shown as a percentage of the limit.

= 1.0.3 =
* Send the metrics right after connecting.
* Link to the site's page in the Pandora Cloud dashboard.

= 1.0.2 =
* Per-metric status (OK / warning / critical) with WordPress rules.
* Local history with status badges.

= 1.0.1 =
* Connect with email and password only (the node is resolved through the Cloud Master).

= 1.0.0 =
* First release: connection, metric collection, local history and charts, plugin-change log.

== Upgrade Notice ==

= 1.0.4 =
Adds module descriptions and clearer memory metrics.

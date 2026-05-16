# WPStatic

Convert your WordPress site into a blazing-fast, static HTML site and deploy anywhere — no PHP, no database, reduced attack surface in production.

## Description

WPStatic converts your WordPress site into a fully static HTML website — eliminating PHP, databases, and server-side dependencies from your production host. The result: faster load times, a reduced attack surface, and the freedom to deploy on any web server or CDN.

⚡ Benefits: Why WPStatic?
 
* 🚀 **Faster page loads:** Static websites load 3 to 5 times faster than WordPress sites by serving pre-rendered HTML without PHP execution or database queries.
* 🔒 **Reduced attack surface:** Static sites have no login page, database connection, or server-side code on your production host. This eliminates primary attack vectors such as SQL injection and brute-force attacks.
* 🌍 **Deploy anywhere:** Host on Cloudflare Pages, GitHub Pages, AWS S3, or any standard web server. No PHP, MySQL, or WordPress dependency is required in production.
* 💰 **Lower hosting costs:** Static files can be served from free or low-cost CDN-based hosts instead of a PHP-capable server. Managed WordPress hosting typically costs $25 to $100 or more per month, while static hosting is often free or very affordable on platforms like Cloudflare Pages or Netlify, which offer unmetered bandwidth on their free tiers.
* 🛠️ **Zero production maintenance:** Your live site is pure HTML. With no WordPress running in production, there are no security patches, plugin updates, or server-side dependencies to manage on your public host.
* 🎛️ **Live export control:** Start, pause, resume, or abort the export from the admin screen and monitor progress in real time.
* 📦 **One-click download:** The entire static site is packaged as a ZIP file, ready for extraction and upload.
* 🔄 **Auto-resume on interruption:** If internet connectivity drops during export, the job resumes automatically once the connection is restored.
 
🔍 SEO Benefits
 
* 📊 **Better Core Web Vitals scores:** Static HTML loads faster, directly improving Time to First Byte (TTFB) and Largest Contentful Paint (LCP), which are metrics Google uses as ranking signals.
* 🤖 **Improved crawl efficiency:** Search engine bots fetch plain HTML instantly, with no server-side rendering delay. This signals high crawl health and allows engines to index content more effectively.
* ⏱️ **Higher uptime reliability:** Static files served from a CDN experience near-zero downtime, helping avoid Google ranking penalties for frequently unreachable URLs.
* 🧹 **No WordPress footprint in production:** Common WordPress paths such as /wp-admin and /wp-login.php are removed from the live site, reducing spam crawling and preserving crawl budget.


The admin screen provides the following capabilities:

- Start a static export job
- Monitor live export status in real time
- Pause, resume, or abort the export
- Download the generated static site as a ZIP file
- Download or delete export logs
- Delete temporary export directories

WPStatic systematically crawls website URLs, renders content, rewrites links for static output, and packages the results for deployment.

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.2 |
| PHP | 7.4 |
| Tested up to | 6.9 |

## Installation

1. [Download the WPStatic plugin from here.](https://github.com/use-wpstatic/wpstatic/releases) Upload the plugin to the plugins folder (`wp-content/plugins/`), or install it via **WordPress Admin → Plugins → Add Plugin → Upload Plugin → Choose the downloaded ZIP → Click 'Install Now'.**
2. Activate the plugin from the **Installed Plugins** screen.
3. Open **WPStatic** from the WordPress admin menu.
4. Click **Generate/Export Static Site** and wait for completion.
5. Download the ZIP and deploy the exported files to your chosen host.

## Typical Workflow

1. Build and finalize your WordPress site — content, settings, everything.
2. Open the WPStatic admin screen and click **Generate/Export Static Site**.
3. After the export finishes, download the generated ZIP file.
4. Extract and upload the files to your static hosting provider (e.g., Cloudflare Pages, GitHub Pages, AWS S3, or your own web server's document root).

## Important Requirements

- If your WordPress site is on `example.com` and you want the static site on `example.com` too, move WordPress to a subdomain (protected with HTTP Basic Auth) or to localhost using a migration plugin such as Duplicator.
- Permalink structure must **not** be set to **Plain**.
- Keep the WPStatic export tab open while an export is running.

## Limitations

WPStatic does **not** work with websites that generate content dynamically based on user interaction, such as e-commerce or subscription-based sites. Contact form submissions also require backend processing and will not function in a static export (support for this is on the roadmap).

## HTTP Basic Authentication

If your WordPress install is protected with HTTP Basic Auth (for example, a staging or subdomain install), enter your credentials under **WPStatic → Settings → Security** so WPStatic can authenticate during export. 

Credentials are encrypted before storage and never exposed in logs or diagnostics output.

## Advanced Opt-In Options

WPStatic includes two optional safety/compatibility flags, both disabled by default and configurable from the **WPStatic → Settings → General** screen.

* **Allow insecure local HTTP fetch**
Enable this only for expired, invalid, or self-signed certificate environments where same-site HTTPS fetches fail TLS verification. This turns off SSL verification only for local same-site fetches.

* **Prefer temporary storage above document root**
Enable this only if you explicitly want WPStatic working directories outside `wp_upload_dir()`. By default, WPStatic uses WordPress uploads paths. Please note that this option works only if the intended directory is readable and writable by the web server user.

Both flags can also be set directly in the options table if preferred.

## Frequently Asked Questions

**Does this plugin replace my live WordPress site automatically?**

No. WPStatic creates a static export for you to deploy manually.

**Why can I not start an export?**

Common reasons:
- Your permalink structure is set to **Plain**.
- File permissions prevent writing inside the uploads/temp directories.
- Another export job is currently active.

**Can I pause and continue later?**

Yes. Export jobs can be paused and resumed from the export screen.

**Can I download export diagnostics/logs?**

Yes. Logs can be downloaded using the **Download Export Log** button, and you can include system information using the **Include/Append system information** checkbox.

## Changelog

### [1.1.0] – 2026-05-13

#### Features
- Added HTTP Basic Authentication support — WPStatic now forwards Basic Auth headers during export, enabling crawling of password-protected WordPress installs.
- Introduced a Settings page in the admin interface with General and Security tabs.
- Advanced opt-in flags (`wpstatic_allow_insecure_local_http_fetch` and `wpstatic_prefer_temp_storage_above_document_root`) are now configurable directly from the admin Settings page, without requiring manual database edits.

#### Security
- HTTP Basic Auth credentials are encrypted before storage and decrypted only at runtime.
- HTTP Basic Auth credentials are redacted from diagnostics output and export logs.

#### Improvements
- Settings query now excludes transient options, improving performance and ensuring cleaner data retrieval.

#### Fixes
- Logger now returns accurate content-length headers and preserves log content correctly in plain text responses.

#### Maintenance
- Relicensed plugin metadata and license text to GPLv2 or later.

### 1.0.2
- **Fix:** Uninstall flow now reliably loads required helper functions before cleanup.
- **Improvement:** Kept uninstall cleanup logic centralized by reusing existing helper APIs instead of duplicating cleanup behavior.

### 1.0.1
- **Security:** Added path traversal detection to reject unsafe URLs during export.
- **Security:** Blocked export of PHP and other server-side executable files.
- **Security:** Added symlink escape prevention during log file and directory deletion.
- **Security:** Refactored SSL verification handling for local HTTP fetches.
- **Feature:** Export job now enqueues allowed static files from the site document root, excluding PHP files.
- **Feature:** Implemented auto-resume functionality when connectivity is restored during an export.
- **Feature:** Added support for two advanced opt-in flags.
- **Improvement:** Various code quality and maintainability improvements.
- **Fix:** Moved `admin.css` to `assets/css/` and updated the enqueue reference.

### 1.0.0
- Initial stable release.
- Admin export interface with live status updates.
- Start, pause, resume, and abort export controls.
- Static ZIP download and export log download.
- Tools to delete logs and temporary export directories.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

=== WPStatic ===
Contributors: speedify
Tags: static site generator, performance, security, jamstack, cache
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate a static HTML version of your WordPress website and download it as a ZIP archive.


== Description ==

WPStatic helps you create a static copy of your WordPress website, facilitating faster content delivery and reducing the security risks on production hosting.

The admin screen provides the following capabilities:

* Start a static export job
* Monitor live export status in real time
* Pause, resume, or abort the export
* Download the generated static site as a ZIP file
* Download or delete export logs
* Delete temporary export directories

WPStatic systematically crawls website URLs, renders content, rewrites links for static output, and packages the results for deployment.

= Typical workflow =

1. First, build and set up your site as you normally do in WordPress. Make sure all content and settings are finalized before exporting.
2. Next, open the WPStatic admin screen and select the **Generate/Export Static Site** button to start the export process.
3. After the export finishes, download the generated ZIP file containing your static website.
4. Extract the files from the ZIP archive. Upload the extracted files to your chosen static hosting provider. For example, use your website's document root on a web hosting server, Cloudflare Pages,  GitHub Pages, AWS S3, etc.

= Important Requirements =

* If the current WordPress website is on example.com and you want to make the static website available on example.com as well, move the WordPress website to a subdomain and protect it with HTTP Basic Auth, or move it to your localhost computer using a backup and migration plugin such as Duplicator.
* Permalink structure must not be set to **Plain**.
* Keep the WPStatic export tab open while export is running.

= Not for e-commerce, etc. =

WPStatic will not work on any WordPress website that generates content dynamically based on user interaction, e.g., an e-commerce or subscription-based website.

Contact forms will not work. The contact form requires immediate backend processing upon submission by the user. However, this is on our roadmap.

= Advanced Opt-In Options =

WPStatic includes two optional safety/compatibility flags (both disabled by default):

* `wpstatic_prefer_temp_storage_above_document_root`  
Set to `true` (in the options table) only if you explicitly want WPStatic working directories outside `wp_upload_dir()`. By default, WPStatic uses WordPress uploads paths.

* `wpstatic_allow_insecure_local_http_fetch`  
Set to `true` (in the options table) only for local/self-signed certificate environments where same-site HTTPS fetches fail TLS verification. This disables SSL verification for local same-site fetches.

= Need Help? =

If you face any issues after installing the WPStatic plugin, please open a support ticket with detailed information, and we will be happy to fix the issue.

== Installation ==

1. Upload the plugin to the plugins folder (`wp-content/plugins/`), or install it through the WordPress Plugins screen, click 'Add Plugin', and search with **WPStatic**.
2. Click the 'Activate' button, or activate the plugin from the **Installed Plugins** screen.
3. Open **WPStatic** from the WordPress admin menu.
4. Click the **Generate/Export Static Site** button and wait for completion.
5. Download the ZIP and deploy the exported files to your chosen host.

== Frequently Asked Questions ==

= Does this plugin replace my live WordPress site automatically? =

No. WPStatic creates a static export for you to deploy manually.

= Why can I not start an export? =

Common reasons:
* Your permalink structure is set to **Plain**.
* File permissions prevent writing inside the uploads/temp directories.
* Another export job is currently active.

= Can I pause and continue later? =

Yes. Export jobs can be paused and resumed from the export screen.

= Can I download export diagnostics/logs? =

Yes. Logs can be downloaded using the **Download Export Log** button, and you can include system information using the 'Include/Append system information' checkbox.

== Screenshots ==

1. Track the export progress in real-time with Export Status Log.
2. Export, Download, and Delete buttons.

== Changelog ==

= 1.0.2 =
* Fix: Uninstall flow now reliably loads required helper functions before cleanup.
* Improvement: Kept uninstall cleanup logic centralized by reusing existing helper APIs instead of duplicating cleanup behavior.

= 1.0.1 =
* Security: Added path traversal detection to reject unsafe URLs during export.
* Security: Blocked export of PHP and other server-side executable files.
* Security: Added symlink escape prevention during log file and directory deletion.
* Security: Refactored SSL verification handling for local HTTP fetches — insecure fetches are now only permitted when wpstatic_allow_insecure_local_http_fetch is explicitly and correctly set.
* Feature: Export job now enqueues allowed static files from the site document root, excluding PHP files.
* Feature: Implemented auto-resume functionality when connectivity is restored during an export, with configurable retry attempts and delays.
* Feature: Added support for two advanced opt-in flags — wpstatic_prefer_temp_storage_above_document_root and wpstatic_allow_insecure_local_http_fetch.
* Improvement: Introduced wpstatic_get_option_bool helper for consistent boolean option retrieval across the codebase.
* Improvement: Minimum required PHP and WordPress versions are now defined as plugin constants for more maintainable version checks.
* Improvement: Upload directory handling now supports explicit opt-in for above-webroot temporary storage with clearer error logging.
* Improvement: Uninstaller now removes directories based on the configured upload path for thorough cleanup.
* Improvement: Installation timestamp now uses WordPress current_time() for consistency.
* Improvement: Few export log messages in the admin UI are now translatable.
* Fix: Moved admin.css to assets/css/ and updated the enqueue reference accordingly.

= 1.0.0 =
* Initial stable release.
* Added admin export interface with live status updates.
* Added start, pause, resume, and abort export controls.
* Added static ZIP download and export log download.
* Added tools to delete logs and temporary export directories.

== Upgrade Notice ==

= 1.0.2 =
Recommended update. Improves plugin deletion reliability by fixing uninstall bootstrap loading.

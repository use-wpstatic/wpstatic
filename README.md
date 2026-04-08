# WPStatic

Generate a static HTML version of your WordPress website and download it as a ZIP archive.

## Description

WPStatic helps you create a static copy of your WordPress website, facilitating faster content delivery and reducing the security risks on production hosting.

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

1. Upload the plugin to `wp-content/plugins/`, or install it via **WordPress Admin → Plugins → Add Plugin** and search for **WPStatic**.
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

## Advanced Opt-In Options

Two optional flags are available (both disabled by default):

- **`wpstatic_prefer_temp_storage_above_document_root`** — Set to `true` in the options table only if you explicitly want WPStatic working directories placed outside `wp_upload_dir()`.
- **`wpstatic_allow_insecure_local_http_fetch`** — Set to `true` only for local/self-signed certificate environments where same-site HTTPS fetches fail TLS verification. Disables SSL verification for local same-site fetches only.

## Changelog

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

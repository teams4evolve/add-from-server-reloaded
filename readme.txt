=== Add From Server Reloaded ===
Contributors: dd32, elearningevolve
Donate link: https://link.elearningevolve.com/self-pay
Tags: upload-limit, large-files, ftp, import, upload
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 6.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Bypass WordPress upload limits and import large files or folders from your server straight into the Media Library.

== Description ==

**Dedicated Support**
For quick support, please contact us [here](https://elearningevolve.com/contact/).

**Add From Server Reloaded (AFS Lite)**

Stuck on WordPress upload limits? Large videos, RAW photos, PDFs, and bulk product images often fail in the browser uploader. Add From Server Reloaded (AFS Lite) lets you move those files to the server with FTP or SSH, then import them into the Media Library from wp-admin. No php.ini edits and no host tickets required.

Works on shared hosting, multisite, WooCommerce stores, and current WordPress releases.

= How It Works =

1. Upload files to your server with FTP or SSH (no browser size limit).
2. Open AFS Lite in the WordPress admin and browse to the folder.
3. Select files or a whole folder and import them into the Media Library.

= Features =

**File import and management**

* One-click folder import, including subfolders
* Smart duplicate detection so repeat imports stay clean
* Real-time file search
* Filters and pagination for large directories
* Configurable root directory in Settings
* Show or hide hidden / non-importable files
* Last modified column
* Chunked batch import progress (safer on long imports)
* Keyboard shortcuts (Ctrl+A to select all, Esc to clear)
* Import summary with links to new Media Library items

**User interface**

* Clear folder vs file layout with checkboxes
* Clickable breadcrumbs
* Import actions at the top and bottom of the list
* Selected file and folder counts

**Security and performance**

* Directory traversal protection
* Dangerous file type blocking (PHP, EXE, and similar)
* CSRF protection with nonces on forms and AJAX
* Real path validation before browse or import
* Input sanitization and output escaping
* Memory-conscious scanning for large trees

**Compatibility**

* WordPress 6.0 to 7.1
* PHP 7.4 to 8.3
* WooCommerce compatible
* Multisite compatible
* Works on shared hosting

= Add From Server Reloaded Pro (AFS Pro) =

The free plugin covers browsing and importing from the local server. [AFS Pro](https://elearningevolve.com/products/add-from-server-reloaded-pro/) unlocks extras when you need more control at scale:

* Background imports (leave the page while jobs continue)
* Scheduled and recurring imports
* Import history with per-file results
* Pause, resume, and retry failed items
* Folder structure preserve options
* Remote FTP / SFTP and cloud (S3) sources
* REST API and WP-CLI tools
* Email alerts for import jobs
* Role-based access controls
* Priority email support with your license

[Get Add From Server Reloaded Pro](https://elearningevolve.com/products/add-from-server-reloaded-pro/)

= Our Other Plugins =

1. [WPZoomy](https://wpzoomy.com/)
2. [Virtual Classroom for WordPress (Free)](https://wordpress.org/plugins/video-conferencing-with-bbb/)
3. [Virtual Classroom for WordPress (Pro)](https://elearningevolve.com/products/wp-virtual-classroom/)
4. [LearnDash Student Voice](https://elearningevolve.com/products/learndash-student-voice/)
5. [Simple Email Scheduler](https://wordpress.org/plugins/simple-email-scheduler/)
6. [UpdateGuard](https://wordpress.org/plugins/updateguard/)
7. [Topbar Buddy](https://wordpress.org/plugins/topbar-buddy/)
8. [MasterQuiz AI](https://masterquiz.io/)

== Installation ==

Here is a quick overview of the installation.

1. Log in to WordPress as an administrator and open **Plugins** in the sidebar.
2. Click **Add New**.
3. Search for **Add From Server Reloaded**.
4. Find the plugin by eLearning evolve and click **Install Now**.
5. Click **Activate**.
6. In the admin sidebar, open **AFS Lite** (or **AFS Pro** if the Pro add-on is also active).
7. Optional: open **Settings** under that menu and set the root directory you want to browse.
8. Upload your large files to that area of the server with FTP or SSH.
9. Use **Import Files** to select files or folders and import them into the Media Library.

You can also upload the plugin zip from Plugins > Add New > Upload Plugin if you downloaded it from WordPress.org.

== Frequently Asked Questions ==

= How do I bypass WordPress upload limits? =

Upload the files to your server with FTP or SSH, then import them with this plugin. The browser upload limit no longer applies.

= Can I upload large videos or RAW photos? =

Yes. Any size that fits on your disk works, including large video, PDF, and RAW files, as long as WordPress supports the file type.

= Can I bulk import product images for WooCommerce? =

Yes. Upload the image folder by FTP, then import the folder into the Media Library and attach the media to your products as usual.

= Does this require server access? =

You need FTP or SSH access to place files on the server. You do not need root access or php.ini changes.

= Is it safe for shared hosting? =

Yes. No server config changes are required. The plugin blocks directory traversal and dangerous file types by default.

= What is the difference between AFS Lite and AFS Pro? =

AFS Lite (this free plugin) imports from folders on the same server. AFS Pro adds background and scheduled imports, history, remote FTP/S3, REST/CLI, email alerts, RBAC, and more. Details: [Add From Server Reloaded Pro](https://elearningevolve.com/products/add-from-server-reloaded-pro/).

= Do I need Pro for basic imports? =

No. Folder browse and import to the Media Library are included in the free plugin.

= Where do I get a Pro license? =

Purchase from [eLearning evolve](https://elearningevolve.com/products/add-from-server-reloaded-pro/). Your license key is listed under My Account after checkout. Activate it under AFS Lite / AFS Pro Settings on your site.

== Screenshots ==

1. Browse server files with the modern import interface.
2. Real-time search filter in action.
3. One-click folder import including subfolders.
4. Import success message with Media Library links.

== Changelog ==

= 6.0.0 =
* Major free release: freemium AFS Lite / AFS Pro experience, refreshed Import UI, and Pro feature teasers in the free plugin.
* Chunked AJAX bulk import engine with folder scanning and cancel support.
* Settings screen for root directory and related options; Import Files and Settings links on the Plugins screen.
* Plugin display name updated to Add From Server Reloaded (AFS Lite).
* Security: Duplicate-check AJAX (`afsrreloaded_check_duplicate`) now requires a real file under the configured root (Path_Guard). Thanks to comradezephyr for responsible disclosure.
* Security: File hashing skips non-regular files and files larger than 64 MiB by default (filter: afsrreloaded_file_hash_max_bytes) to limit request-time DoS via md5_file.
* Security hardening for path boundaries, open_basedir-safe root checks, and safer handling of files already under uploads.
* Pro-ready modules (history, schedules, remote FTP/SFTP/S3, email alerts, RBAC, REST/CLI) ship in Free and unlock with a valid AFS Pro license.
* Tested up to WordPress 7.1.

= 5.3.0 =
* Freemium Free/Pro experience groundwork and Import UI refresh.
* Security fixes for path checks, hashing, and root fallbacks.

= 5.2.2 =
* Security: Path checks now require files to sit under the configured root (fixes sibling-prefix cases such as /path/app vs /path/app2).
* Fixed: More consistent root path checks when browsing and importing.

= 5.2.1 =
* Fixed: "Unable to determine root directory" on hosts where the old home-directory guess was not readable. Falls back to ABSPATH and uploads.

= 5.2.0 =
* Changed: Imports use the default WordPress year/month uploads structure.
* Fixed: Original file dates preserved; empty upload folders avoided; Media Library paths stay correct.

= 5.1.0 =
* Fixed: Invalid date folders and empty upload folders.
* Added: Clearer skip messages for restricted file types.

= 5.0.0 =
* Major UI and security overhaul: folder import, duplicate detection, search, configurable root, and related improvements.

= 4.1.2 =
* Critical fix for folder name display and navigation.
* Plugin Check and i18n improvements.

= 4.1.0 =
* Namespace and PHP 8+ compatibility updates.

= 4.0.0 =
* Initial Add From Server Reloaded release.

== Upgrade Notice ==

= 6.0.0 =
Major free update with the AFS Lite / AFS Pro freemium flow, chunked imports, Settings improvements, and security hardening. Update recommended for all sites.

= 5.2.2 =
Important path-boundary security fix. Please update.

= 5.0.0 =
Major UI and feature update.

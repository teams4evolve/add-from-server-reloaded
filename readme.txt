=== Add From Server Lite ===
Contributors: dd32, elearningevolve
Donate link: https://link.elearningevolve.com/self-pay
Tags: upload-limit, large-files, ftp, import, upload
Requires at least: 6.0
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 6.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Import large files into the Media Library from your server. Skip browser upload limits without touching php.ini.

== Description ==

## Stop fighting WordPress upload limits

Hosting caps and PHP limits should not decide whether your video, RAW photo, or product image set makes it into WordPress. **Add From Server Lite (AFS Lite)** lets you place files on the server with FTP or SSH, then import them into the Media Library from wp-admin.

No php.ini edits. No host tickets. Works on shared hosting, multisite, and WooCommerce stores.

Need help? [Contact eLearning evolve](https://elearningevolve.com/contact/).

---

### How it works

1. Upload files to your server with FTP or SSH (no browser size limit).
2. Open **AFS Lite** in WordPress and browse to the folder.
3. Select files or a whole folder and import them into the Media Library.

That is the whole idea: put the files on disk first, then let WordPress register them properly.

---

### Built for real media libraries

- **Folder import** — bring in a whole tree, including subfolders, in one pass.
- **Chunked imports** — long jobs stay safer on shared hosts; cancel when you need to.
- **Duplicate awareness** — reduce accidental re-imports of the same file.
- **Search, filters, and pagination** — find what you need in large directories.
- **Configurable root** — choose which area of the server the plugin may browse.
- **Clear import summary** — jump straight to the new Media Library items.

---

### A UI that stays out of your way

Browse folders and files with checkboxes, breadcrumbs, and selection counts. Import actions sit at the top and bottom of the list so you are not scrolling forever on big directories. Keyboard shortcuts help when you are selecting a lot at once (Ctrl+A to select all, Esc to clear).

---

### Security that matches the job

Importing from the server means path safety matters. AFS Lite is built with that in mind:

- Files must sit under your configured root (path boundary checks).
- Dangerous types such as PHP and executables are blocked by default.
- Forms and AJAX use WordPress nonces.
- Paths are validated before browse or import.
- Output is escaped; inputs are sanitized.

---

### Add From Server Pro when you outgrow the basics

Lite covers local browse and import. **[Add From Server Pro (AFS Pro)](https://elearningevolve.com/products/add-from-server-pro/)** unlocks the heavier workflow tools:

- Background imports that keep going after you leave the page
- Scheduled and recurring folder imports
- Import history with per-file results
- Pause, resume, and retry failed items
- Folder structure preserve options
- Remote FTP / SFTP and S3-compatible sources
- REST API and WP-CLI
- Email alerts and role-based access control

[Get Add From Server Pro](https://elearningevolve.com/products/add-from-server-pro/)

---

### Our other plugins

1. [WPZoomy](https://wpzoomy.com/)
2. [Virtual Classroom for WordPress (Free)](https://wordpress.org/plugins/video-conferencing-with-bbb/)
3. [Virtual Classroom for WordPress (Pro)](https://elearningevolve.com/products/wp-virtual-classroom/)
4. [LearnDash Student Voice](https://elearningevolve.com/products/learndash-student-voice/)
5. [Simple Email Scheduler](https://wordpress.org/plugins/simple-email-scheduler/)
6. [UpdateGuard](https://wordpress.org/plugins/updateguard/)
7. [Topbar Buddy](https://wordpress.org/plugins/topbar-buddy/)
8. [MasterQuiz AI](https://masterquiz.io/)

== Installation ==

Getting started is straightforward.

1. In WordPress admin, open **Plugins → Add New**.
2. Search for **Add From Server Lite**.
3. Install and activate the plugin by eLearning evolve.
4. Open **AFS Lite** in the admin menu (or **AFS Pro** if the Pro add-on is also active).
5. Optional: under **Settings**, set the root directory you want to browse.
6. Upload large files to that area with FTP or SSH.
7. Use **Import Files** to select files or folders and send them into the Media Library.

You can also upload a zip from Plugins → Add New → Upload Plugin if you downloaded it from WordPress.org.

== Frequently Asked Questions ==

= How do I bypass WordPress upload limits? =

Upload the files to your server with FTP or SSH, then import them with AFS Lite. The browser upload limit no longer applies to those files.

= Can I import large videos, PDFs, or RAW photos? =

Yes. If the file fits on disk and WordPress supports the type, you can import it — including large video, PDF, and RAW files.

= Can I bulk import WooCommerce product images? =

Yes. Upload the image folder by FTP, import the folder into the Media Library, then attach media to products as usual.

= Do I need root access or php.ini changes? =

No. You need FTP or SSH access to place files on the server. You do not need root access or PHP config edits.

= Is it safe on shared hosting? =

Yes. No server config changes are required. The plugin blocks directory traversal and dangerous file types by default.

= What is the difference between AFS Lite and AFS Pro? =

AFS Lite imports from folders on the same server. AFS Pro adds background and scheduled imports, history, remote FTP/S3, REST/CLI, email alerts, RBAC, and more. Details: [Add From Server Pro](https://elearningevolve.com/products/add-from-server-pro/).

= Do I need Pro for basic imports? =

No. Browse and import to the Media Library are included in the free plugin.

= Where do I get a Pro license? =

Purchase from [eLearning evolve](https://elearningevolve.com/products/add-from-server-pro/). Your key appears under My Account after checkout. Activate it under AFS Lite / AFS Pro → Settings.

== Screenshots ==

1. Browse server files with the modern import interface.
2. Real-time search filter in action.
3. One-click folder import including subfolders.
4. Import success message with Media Library links.

== Changelog ==

= 6.0.0 =
* Major Upgrade: freemium AFS Lite / AFS Pro experience, refreshed Import UI, and Pro feature teasers in the free plugin. Get [Add From Server Pro](https://elearningevolve.com/products/add-from-server-pro/).
* Chunked AJAX bulk import engine with folder scanning and cancel support.
* Settings screen for root directory and related options; Import Files and Settings links on the Plugins screen.
* Plugin display name updated to Add From Server Lite.
* Security: Duplicate-check AJAX (`afsrreloaded_check_duplicate`) now requires a real file under the configured root (Path_Guard). Thanks to comradezephyr for responsible disclosure.
* Security: File hashing skips non-regular files and files larger than 64 MiB by default (filter: afsrreloaded_file_hash_max_bytes) to limit request-time DoS via md5_file.
* Security hardening for path boundaries, open_basedir-safe root checks, and safer handling of files already under uploads.
* Pro-ready modules (history, schedules, remote FTP/SFTP/S3, email alerts, RBAC, REST/CLI) ship in Free and unlock with a valid AFS Pro license.

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
* Initial Add From Server Lite release.

== Upgrade Notice ==

= 6.0.0 =
Major free update with the AFS Lite / AFS Pro freemium flow, chunked imports, Settings improvements, and security hardening. Update recommended for all sites.

= 5.2.2 =
Important path-boundary security fix. Please update.

= 5.0.0 =
Major UI and feature update.

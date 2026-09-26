#!/usr/bin/env bash
# Quick verification for Add From Server Reloaded Free (run on host or via ddev).
# Usage from wordpress-test project:
#   ddev exec bash wp-content/plugins/add-from-server-reloaded-free/bin/quick-verify.sh
# Or from plugin dir with WP-CLI available:
#   bash bin/quick-verify.sh

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

echo "== Unit tests (path CVE + filters) =="
if [[ -x vendor/bin/phpunit ]]; then
	vendor/bin/phpunit --testdox
else
	echo "SKIP: vendor/bin/phpunit missing (composer install)"
fi

echo ""
echo "== Path_Guard smoke (PHP) =="
php -r '
require "includes/class-path-guard.php";
if (!function_exists("wp_normalize_path")) {
  function wp_normalize_path($p){ return preg_replace("#/+#","/",str_replace("\\\\","/",$p)); }
}
if (!function_exists("untrailingslashit")) {
  function untrailingslashit($v){ return rtrim($v,"/\\\\"); }
}
use AFSRReloaded\Path_Guard;
$ok = Path_Guard::path_has_root_boundary("/var/www/cve-testapp/x.txt","/var/www/cve-testapp");
$bad = Path_Guard::path_has_root_boundary("/var/www/cve-testapp2/x.txt","/var/www/cve-testapp");
if (!$ok || $bad) { fwrite(STDERR,"Path_Guard FAILED\n"); exit(1); }
echo "Path_Guard sibling-prefix: PASS\n";
'

echo ""
echo "== WP-CLI checks (if wp available) =="
if command -v wp >/dev/null 2>&1; then
	wp eval '
	if (!class_exists("AFSRReloaded\\Path_Guard")) { echo "Path_Guard not loaded\n"; exit(1); }
	$root = "/tmp/afsr-cve-testapp";
	$sib  = "/tmp/afsr-cve-testapp2";
	@mkdir($root); @mkdir($sib);
	file_put_contents("$root/ok.txt","ok");
	file_put_contents("$sib/evil.txt","evil");
	$ok  = AFSRReloaded\Path_Guard::is_under_root($root, "$root/ok.txt");
	$bad = AFSRReloaded\Path_Guard::is_under_root($root, "$sib/evil.txt");
	echo $ok && !$bad ? "WP Path_Guard: PASS\n" : "WP Path_Guard: FAIL\n";
	$bg = class_exists("AFSRReloaded\\Features") && AFSRReloaded\Features::enabled("background");
	echo "Background feature: " . ($bg ? "ON (Pro)" : "OFF (Free)") . "\n";
	$next = wp_next_scheduled("afsrreloaded_process_import_jobs");
	echo "Recurring cron: " . ($next ? date("c",$next) : "NOT SCHEDULED") . "\n";
	' --skip-plugins=0 2>/dev/null || echo "WP eval skipped/failed (run inside WP root with plugins loaded)"
else
	echo "SKIP: wp CLI not in PATH"
fi

echo ""
echo "Done. Manual retests: E Cancel, G Background, K Schedules, N Remote, H History Retry."

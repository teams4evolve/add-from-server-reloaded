<?php
/**
 * Path_Guard unit tests (CVE sibling-prefix boundary).
 *
 * @package AFSRReloaded
 */

namespace AFSRReloaded\Tests\Unit;

use AFSRReloaded\Path_Guard;
use PHPUnit\Framework\TestCase;

/**
 * Path boundary tests.
 */
class Path_GuardTest extends TestCase {

	/**
	 * Sibling prefix must not match (CVE-style /path/app vs /path/app2).
	 */
	public function test_rejects_sibling_prefix_bypass() {
		$root = '/var/www/cve-testapp';
		$this->assertTrue( Path_Guard::path_has_root_boundary( '/var/www/cve-testapp/marker.txt', $root ) );
		$this->assertTrue( Path_Guard::path_has_root_boundary( '/var/www/cve-testapp', $root ) );
		$this->assertFalse( Path_Guard::path_has_root_boundary( '/var/www/cve-testapp2/cve-test-marker.txt', $root ) );
		$this->assertFalse( Path_Guard::path_has_root_boundary( '/var/www/cve-testapp2', $root ) );
		$this->assertFalse( Path_Guard::path_has_root_boundary( '/etc/passwd', $root ) );
	}

	/**
	 * Empty inputs rejected.
	 */
	public function test_rejects_empty() {
		$this->assertFalse( Path_Guard::path_has_root_boundary( '', '/tmp' ) );
		$this->assertFalse( Path_Guard::path_has_root_boundary( '/tmp/x', '' ) );
	}

	/**
	 * open_basedir unset / empty → any non-empty path allowed.
	 */
	public function test_open_basedir_empty_allows_paths() {
		$this->assertTrue( Path_Guard::is_path_allowed( '/var/www/html', '' ) );
		$this->assertFalse( Path_Guard::is_path_allowed( '', '' ) );
	}

	/**
	 * Paths outside open_basedir entries are rejected without calling is_dir().
	 */
	public function test_open_basedir_rejects_parent_outside_jail() {
		$ob = '/var/www/html/wordpress-test/wordpress' . PATH_SEPARATOR . '/tmp';
		$this->assertFalse(
			Path_Guard::is_path_allowed( '/var/www/html/wordpress-test', $ob )
		);
		$this->assertTrue(
			Path_Guard::is_path_allowed( '/var/www/html/wordpress-test/wordpress', $ob )
		);
		$this->assertTrue(
			Path_Guard::is_path_allowed( '/var/www/html/wordpress-test/wordpress/wp-content', $ob )
		);
	}
}

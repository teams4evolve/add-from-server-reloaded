<?php
/**
 * Unit tests for File_Filters.
 *
 * @package AFSRReloaded
 */

namespace AFSRReloaded\Tests\Unit;

use AFSRReloaded\File_Filters;
use PHPUnit\Framework\TestCase;

/**
 * File_FiltersTest
 */
class File_FiltersTest extends TestCase {

	/**
	 * Temp directory.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Setup fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/afsr-filters-' . uniqid( '', true );
		mkdir( $this->dir );
		mkdir( $this->dir . '/images' );
		file_put_contents( $this->dir . '/images/a.jpg', str_repeat( 'x', 100 ) );
		file_put_contents( $this->dir . '/doc.pdf', str_repeat( 'p', 200 ) );
		file_put_contents( $this->dir . '/big.bin', str_repeat( 'b', 2 * 1024 * 1024 ) );
		file_put_contents( $this->dir . '/notes.txt', 'hello' );
	}

	/**
	 * Cleanup fixtures.
	 */
	protected function tearDown(): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->dir );
		parent::tearDown();
	}

	/**
	 * All types passes.
	 */
	public function test_passes_all_types() {
		$this->assertTrue( File_Filters::passes( $this->dir . '/images/a.jpg', array( 'file_types' => 'all' ) ) );
	}

	/**
	 * Images filter.
	 */
	public function test_images_filter() {
		$this->assertTrue( File_Filters::passes( $this->dir . '/images/a.jpg', array( 'file_types' => 'images' ) ) );
		$this->assertFalse( File_Filters::passes( $this->dir . '/doc.pdf', array( 'file_types' => 'images' ) ) );
	}

	/**
	 * Documents filter.
	 */
	public function test_documents_filter() {
		$this->assertTrue( File_Filters::passes( $this->dir . '/doc.pdf', array( 'file_types' => 'documents' ) ) );
		$this->assertTrue( File_Filters::passes( $this->dir . '/notes.txt', array( 'file_types' => 'documents' ) ) );
		$this->assertFalse( File_Filters::passes( $this->dir . '/images/a.jpg', array( 'file_types' => 'documents' ) ) );
	}

	/**
	 * Custom extensions.
	 */
	public function test_custom_extensions() {
		$opts = array(
			'file_types'   => 'custom',
			'allowed_exts' => 'pdf,txt',
		);
		$this->assertTrue( File_Filters::passes( $this->dir . '/doc.pdf', $opts ) );
		$this->assertFalse( File_Filters::passes( $this->dir . '/images/a.jpg', $opts ) );
	}

	/**
	 * Max size filter.
	 */
	public function test_max_size_mb() {
		$opts = array(
			'file_types'       => 'all',
			'max_file_size_mb' => 1,
		);
		$this->assertTrue( File_Filters::passes( $this->dir . '/images/a.jpg', $opts ) );
		$this->assertFalse( File_Filters::passes( $this->dir . '/big.bin', $opts ) );
	}

	/**
	 * Missing file fails.
	 */
	public function test_missing_file_fails() {
		$this->assertFalse( File_Filters::passes( $this->dir . '/missing.jpg', array() ) );
	}

	/**
	 * Preserve subdir from relative path.
	 */
	public function test_preserve_subdir() {
		$this->assertSame( 'folder/sub', File_Filters::preserve_subdir_from_relative( 'folder/sub/file.png' ) );
		$this->assertSame( 'afsr-imports', File_Filters::preserve_subdir_from_relative( 'file.png' ) );
		$this->assertSame( 'afsr-imports', File_Filters::preserve_subdir_from_relative( '../evil/../../safe/x.jpg' ) );
		$this->assertSame( 'afsr-imports', File_Filters::preserve_subdir_from_relative( '' ) );
	}

	/**
	 * Path jail helper.
	 */
	public function test_path_is_under_root() {
		$inside = $this->dir . '/images/a.jpg';
		$this->assertTrue( File_Filters::path_is_under_root( $this->dir, $inside ) );
		$this->assertFalse( File_Filters::path_is_under_root( $this->dir, '/etc/passwd' ) );
		$this->assertFalse( File_Filters::path_is_under_root( $this->dir, $this->dir . '/../' . basename( $this->dir ) . '/../../etc/passwd' ) );
	}
}

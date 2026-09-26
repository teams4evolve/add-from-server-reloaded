<?php
/**
 * Unit tests for Features filter defaults.
 *
 * @package AFSRReloaded
 */

namespace AFSRReloaded\Tests\Unit;

use AFSRReloaded\Features;
use PHPUnit\Framework\TestCase;

/**
 * FeaturesTest
 */
class FeaturesTest extends TestCase {

	/**
	 * Defaults are false without Pro filters.
	 */
	public function test_defaults_are_false() {
		$this->assertFalse( Features::enabled( 'background' ) );
		$this->assertFalse( Features::enabled( 'scheduled_imports' ) );
		$this->assertFalse( Features::is_pro() );
	}

	/**
	 * Unknown feature stays false.
	 */
	public function test_unknown_feature_false() {
		$this->assertFalse( Features::enabled( 'not_a_real_feature' ) );
	}

	/**
	 * Pro filter can unlock a feature.
	 */
	public function test_filter_can_enable_feature() {
		$callback = static function ( $enabled, $feature ) {
			return ( 'history' === $feature ) ? true : $enabled;
		};
		add_filter( 'afsrreloaded_pro_feature', $callback, 10, 2 );
		$this->assertTrue( Features::enabled( 'history' ) );
		$this->assertFalse( Features::enabled( 'background' ) );
		remove_filter( 'afsrreloaded_pro_feature', $callback, 10 );
	}
}

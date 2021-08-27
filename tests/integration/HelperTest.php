<?php

use SkyVerge\WooCommerce\PluginFramework\v5_10_8 as Framework;

/**
 * Tests for the helper class.
 *
 * @see \SkyVerge\WooCommerce\PluginFramework\v5_10_8\SV_WC_Plugin_Compatibility
 */
class HelperTest extends \Codeception\TestCase\WPTestCase {


	/**
	 * Tests {@see Framework\SV_WC_Helper::is_rest_api_request()}.
	 *
	 * @param string $route endpoint
	 * @param bool $expected result
	 *
	 * @dataProvider provider_is_rest_api_request
	 */
	public function test_is_rest_api_request( $route, $expected ) {

		$_SERVER['REQUEST_URI'] = $route;

		$is_api_request = Framework\SV_WC_Helper::is_rest_api_request();

		$this->assertSame( $is_api_request, $expected );
	}


	/**
	 * Data provider for {@see HelperTest::test_is_rest_api_request()}.
	 *
	 * @return array[]
	 */
	public function provider_is_rest_api_request() {

		return [
			[ '/wp-json/', true ],
			[ '/', false ],
		];
	}


	/**
	 * Tests that can convert an array of IDs to a comma separated list.
	 *
	 * @dataProvider can_get_escaped_id_list
	 *
	 * @param array $ids
	 * @param string $expected
	 */
	public function can_get_escaped_id_list( array $ids, string $expected ) {

		$this->assertSame( $expected, Framework\SV_WC_Helper::get_escaped_id_list($ids) );
	}


	/** @see can_get_escaped_id_list **/
	public function provider_get_escaped_id_list() : array {

		return [
			'Non-numbers'                => [[null, false, true, 'test'], '0,0,1,1'],
			'Integers'                   => [[1, 2, 3], '1,2,3'],
			'Negative integers'          => [[-1, -2, -3], '1,2,3'],
			'Numerical strings'          => [['1', '2', '3'], '1,2,3'],
			'Negative numerical strings' => [['-1', '-2', '-3'], '1,2,3'],
		];
	}


}

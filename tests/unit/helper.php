<?php

namespace SkyVerge\WC_Plugin_Framework\Unit_Tests;

use \WP_Mock as Mock;

/**
 * Helper Class Unit Tests
 *
 * @package SkyVerge\WC_Plugin_Framework\Unit_Tests
 * @since 4.0.1-1
 */
class Helper extends Test_Case {


	/**
	 * @dataProvider provider_test_str_starts_with_true
	 */
	public function test_str_starts_with_true( $haystack, $needle ) {

		$this->assertTrue( \SV_WC_Helper::str_starts_with( $haystack, $needle ) );
	}

	/**
	 *
	 * @dataProvider provider_test_str_starts_with_false
	 */
	public function test_str_starts_with_false( $haystack, $needle ) {

		$this->assertFalse( \SV_WC_Helper::str_starts_with( $haystack, $needle ) );
	}


	/**
	 * Test SV_WC_Helper::wc_notice_count()
	 *
	 * @since 4.3.0-dev
	 */
	public function test_wc_notice_count() {

		// test 0 return value if function doens't exist
		$this->assertEquals( 0, \SV_WC_Helper::wc_notice_count() );

		// mock wc_notice_count() function
		Mock::wpFunction( 'wc_notice_count', array(
			'args' => array( 'error' ),
			'return' => 666,
		) );

		// test the return value is as expected
		$this->assertEquals( 666, \SV_WC_Helper::wc_notice_count( 'error' ) );
	}


	/**
	 * Test SV_WC_Helper::wc_add_notice()
	 *
	 * @since 4.3.0-dev
	 */
	public function test_wc_add_notice() {

		// mock wc_add_notice() function
		Mock::wpFunction( 'wc_add_notice', array(
			'args' => array( 'This is a success message.', 'success' ),
			'return' => null,
		) );

		$this->assertNull( \SV_WC_Helper::wc_add_notice( 'This is a success message.', 'success' ) );
	}


	/**
	 * Test SV_WC_Helper::wc_print_notice()
	 *
	 * @since 4.3.0-dev
	 */
	public function test_wc_print_notice() {

		// mock wc_print_notice() function
		Mock::wpFunction( 'wc_print_notice', array(
			'args' => array( 'This is a notice message.', 'notice' ),
			'return' => null,
		) );

		$this->assertNull( \SV_WC_Helper::wc_print_notice( 'This is a notice message.', 'notice' ) );
	}


	/**
	 * Test SV_WC_Helper::get_wc_log_file_url()
	 *
	 * @since 4.3.0-dev
	 */
	public function test_get_wc_log_file_url() {

		// set up args
		$admin_url = 'http://skyverge.dev/wp-admin/';
		$handle    = 'plugin';
		$path      = 'admin.php?page=wc-status&tab=logs&log_file=' . $handle . '-' . $handle . '-log';

		// mock wp_hash() function
		Mock::wpPassthruFunction( 'wp_hash' );

		// mock sanitize_file_name() function
		Mock::wpPassthruFunction( 'sanitize_file_name' );

		// mock admin_url() function
		Mock::wpFunction( 'admin_url', array(
			'args' => array( $path ),
			'return' => 'http://skyverge.dev/wp-admin/' . $path,
		) );

		$this->assertEquals( $admin_url . $path, \SV_WC_Helper::get_wc_log_file_url( $handle ) );
	}


	public function provider_test_str_starts_with_true() {

		return [
			[ 'SkyVerge', 'Sky' ],
			[ 'SkyVerge', '' ],
			[ 'ಠ_ಠ', 'ಠ' ], // UTF-8
		];
	}

	public function provider_test_str_starts_with_false() {

		return [
			[ 'SkyVerge', 'verge' ],
			[ 'SkyVerge', 'sky' ], //case-sensitive
		];
	}


	/**
	 * Test \SV_WC_Helper::get_post
	 */
	public function test_get_post() {

		$key = 'sv_test_key';

		// Test for an unset key
		$this->assertEquals( '', \SV_WC_Helper::get_post( $key ) );

		$_POST[ $key ] = 'value';

		// Check that a value is returned
		$this->assertEquals( 'value', \SV_WC_Helper::get_post( $key ) );

		$_POST[ $key ] = ' untrimmed-value ';

		// Check that the value is trimmed
		$this->assertEquals( 'untrimmed-value', \SV_WC_Helper::get_post( $key ) );
	}


	/**
	 * Test \SV_WC_Helper::test_get_request
	 */
	public function test_get_request() {

		$key = 'sv_test_key';

		// Test for an unset key
		$this->assertEquals( '', \SV_WC_Helper::get_request( $key ) );

		$_REQUEST[ $key ] = 'value';

		// Check that a value is returned
		$this->assertEquals( 'value', \SV_WC_Helper::get_request( $key ) );

		$_REQUEST[ $key ] = ' untrimmed-value ';

		// Check that the value is trimmed
		$this->assertEquals( 'untrimmed-value', \SV_WC_Helper::get_request( $key ) );
	}
}

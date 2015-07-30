/* jshint node:true */
module.exports = function( grunt ) {
	'use strict';

	// load all grunt tasks matching the `grunt-*` pattern
	require( 'load-grunt-tasks' )( grunt );

	// Show elapsed time
	require( 'time-grunt' )( grunt );

	var _    = require( 'underscore' );
	var path = require( 'path' );

	var gruntConfig = {};

	// options
	gruntConfig.options = {};

	gruntConfig.pkg = grunt.file.readJSON( 'package.json' ),

	// Set folder templates
	gruntConfig.dirs = {
		lang: 'woocommerce/i18n/languages',
		general: {
			css: 'woocommerce/assets/css',
			js: 'woocommerce/assets/js'
		},
		gateway : {
			css: 'woocommerce/payment-gateway/assets/css',
			js: 'woocommerce/payment-gateway/assets/js'
		}
	};

	function loadConfig( filepath ) {
		var object = {};
		var key;

		filepath = path.normalize( path.resolve( process.cwd(), filepath ) + '/' )

		var files = grunt.file.glob.sync( '*', { cwd: filepath } );

		files.forEach( function( option ) {
			key = option.replace(/\.js$/,'');
			object = _.extend( object, require( filepath + option )( grunt ) );
		});

		return object;
	};

	// load task configs
	gruntConfig = _.extend( gruntConfig, loadConfig( './grunt/configs/' ) );

	// Init Grunt
	grunt.initConfig( gruntConfig );

	// Load custom tasks
	// Disabled, because there are no custom tasks yet. Enable when needed :)
	// grunt.loadTasks( 'grunt/tasks/' );

	// Register update_translations task
	grunt.registerTask( 'update_translations', [
		'makepot',
		'shell:transifex_push',
		'shell:transifex_pull',
		'potomo'
	] );

	// Register default task
	grunt.registerTask( 'default', [
		'coffee',
		'uglify',
		'sass',
		'makepot',
		'clean'
	] );

};

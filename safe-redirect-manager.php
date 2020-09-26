<?php
/**
 * Plugin Name: cleancoded Redirect URL
 * Plugin URI: https://github.com/cleancoded/redirect-url
 * Description: Easily and safely manage HTTP redirects.
 * Author: cleancoded
 * Version: 1.0
 * Text Domain: redirect-url
 * Author URI: https://cleancoded.com
 */
function cleancoded_textdomain() {
	load_plugin_textdomain( 'redirect-url', false, dirname( __FILE__ ) . '/lang' );
}
add_action( 'plugins_loaded', 'cleancoded_textdomain' );

require_once dirname( __FILE__ ) . '/inc/functions.php';
require_once dirname( __FILE__ ) . '/inc/classes/class-cleancoded-post-type.php';
require_once dirname( __FILE__ ) . '/inc/classes/class-cleancoded-redirect.php';


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once dirname( __FILE__ ) . '/inc/classes/class-cleancoded-wp-cli.php';
	WP_CLI::add_command( 'redirect-url', 'cleancoded_WP_CLI' );
}

cleancoded_Post_Type::factory();
cleancoded_Redirect::factory();

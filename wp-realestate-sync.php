<?
/*
Plugin Name: WP RealEstate Sync
Description: Synchronizes Wordpress custom posts, from Gedeon API
Author: Studionet (c)
Version: 0.2.10
Requires at least: 3.8
Author URI: http://www.logiciel-immobilier.com/
License: LGPL
Text Domain: wpres
Domain Path: /lang
*/

/**
 * Callback on activation.
 * 
 * Wrote with "old" php style in order to display prety message if plugin
 * is not compatible  with current PHP version instead of Fatal error.
 * 
 * @return void
 */
function cbWpRealEstateSyncOnActivate() {
	global $wp_version;

	// Check failures messages are stored here.
	$errors = array();

	$minPhpVersion = "5.3.0";
	$minWpVersion  = "3.8";

	if (version_compare(PHP_VERSION, $minPhpVersion, '<'))
		$errors[] = sprintf(
			__("You must have PHP version %s", "wpres"),
			$minPhpVersion);

	if (version_compare($wp_version, $minWpVersion, '<'))
		$errors[] = sprintf(
			__("You must have Wordpress version %s", "wpres"),
			$minWpVersion);
	
	if (empty($errors)) {
		// All went well.
		
		// Set "just activated" state
		update_option('wp-re-sync-just-activated', true);
		
		// Include main singleton
		require_once dirname(__FILE__) . "/" . "WpRealEstateSync.php";
		
		return;

	}
	
	// Disabled this plugin.
	deactivate_plugins(basename(__FILE__));

	// Die, explaining why.
	$text = sprintf(
		__("Unable to activate the plugin <b>%s</b>:", "wpres"),
		"WpRealEstateSync");

	$text .= "<ul><li>" . join("</li><li>", $errors) . "</li></ul>";

	wp_die($text, 'Plugin Activation Error', array(
		'response'  => 200,
		'back_link' => true
	));
	
	
}

// Register activation hook (yup)
register_activation_hook(__FILE__, "cbWpRealEstateSyncOnActivate");

if (get_option('wp-re-sync-is-active')) {
	
	// If plugin is active and passed tests, include main singleton
	require_once dirname(__FILE__) . "/" . "WpRealEstateSync.php";
	
}

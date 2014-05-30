<?php

/*
Plugin Name: Gedeon Sync
Description: Synchronizes Wordpress custom posts, from Gedeon API
Author: Studionet (c)
Version: 0.1
Requires at least: 3.5
Author URI: http://www.logiciel-immobilier.com/
License: LGPL
Text Domain: wpgedeon
Domain Path: /lang
*/

require_once dirname(__FILE__) . "/" . "LsiPhpApi/LsiPhpApi.php";
require_once dirname(__FILE__) . "/" . "GenericSync.class.php";

/**
 * Plugin's main singleton.
 *
 * Most of the time "ad" is a synonym of "property".
 *
 * By now, this plugin only works with "wpcasa" themes.
 * It's meant to be compatible with other "real estate" themes in future
 * versions.
 *
 * @author Christophe Badoit <c.badoit@lesiteimmo.com>
 */
class WpPluginGedeonSync {

	/**
	 * Singleton instance.
	 *
	 * @var WpPluginGedeonSync
	 */
	private $lsiApi;

	/**
	 * Contains logs messages; see log().
	 *
	 * @var array
	 */
	private $logMessages = array();

	/**
	 * WpPluginGedeonSync singleton instance
	 *
	 * @var WpPluginGedeonSync
	 */
	private static $instance = null;
	
	/**
	 * Sychronizer matching with current theme.
	 * 
	 * @var mixed
	 */
	private $synchronizer = null;

	/**
	 * Constructor, private because i'm singleton
	 *
	 * @return void
	 */
	private function __construct() {

		$this->initCron();

		add_action('plugins_loaded', array($this, 'cbPluginsLoaded'));

		$me = $this;
		add_action('wp_loaded', function() use ($me) {
			
			$me->initializeBestSynchoniser();

			
			if (isset($_GET['gedeon-sync-now'])) {

				try {
					$me->doSync();
				} catch (Exception $e) {
					printf('<p class="error">%s</p>', $e->getMessage());
					die();
				}

			} else {

				if (isset($_GET['gedeon-sync-now-bg'])) {
					$this->launchSyncInBackground();
				}

			}

		});

		if (get_option('gedeon-sync-just-activated')) {
			add_action('admin_notices', array($this, "cbOnActivateAdminNotice"));
			delete_option('gedeon-sync-just-activated');
		}
		
	}

	/**
	 * Returns the singleton instance
	 *
	 * @return WpPluginGedeonSync instance
	 */
	public static function getInstance() {

		if (self::$instance === null) {
			self::$instance = new WpPluginGedeonSync();
		}
		return self::$instance;

	}

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init() {

		add_action('admin_menu', array($this, 'cbAdminMenu'));
		add_action('admin_init', array($this, "cbAdminInit"));


	}

	/**
	 * Initialize the cron hook.
	 *
	 * @return void
	 */
	private function initCron() {

		add_action('gedeon-sync-cron', array($this, 'cbGedeonSyncCron'));

	}

	/**
	 * Updates the cron (scheduled event) based on options.
	 *
	 * @param string $interval hourly,twicedaily,daily or disabled
	 * @return void
	 */
	private function updateCron($interval = null) {

		if ($interval === null) {
			// Read $interval from options
			$options = (array)get_option('gedeon-sync', null);
			$interval = $options['auto-sync-interval'];
		}

		if (wp_next_scheduled('gedeon-sync-cron')) {
			// Clear current cron.
			wp_clear_scheduled_hook('gedeon-sync-cron');
		}

		if ($interval == "disabled")
			// Don't set any cron.
			return;

		// Set the cron as requested.
		wp_schedule_event(time() + 3600, $interval, 'gedeon-sync-cron');

	}

	/**
	 * Callback when plugins are loaded ('plugins_loaded')
	 *
	 * @return void
	 */
	public function cbPluginsLoaded() {
		// Load plugin textdomain.
		load_plugin_textdomain('wpgedeon', false,
			dirname(plugin_basename( __FILE__ )) . '/lang/'); 
	}

	/**
	 * Initialize the plugin's admin section.
	 *
	 * @return void
	 */
	public function cbAdminInit() {

		register_setting('gedeon-sync', 'gedeon-sync',
		  	array($this, 'cbSettingChanged'));

		add_settings_section('parameters',
			__("Parameters", "wpgedeon"),
			array($this, "cbSettingsParameters"),
		  	'gedeon-sync');

		add_settings_field('api-key',
		  	__("Api Key", "wpgedeon"),
			array($this, 'cbFieldApiKey'), 'gedeon-sync', 'parameters');

		add_settings_field('api-url',
		  	__("Api Url", "wpgedeon"),
			array($this, 'cbFieldApiUrl'), 'gedeon-sync', 'parameters');

		add_settings_field('auto-sync-interval',
		  	__("AutoSync Interval", "wpgedeon"),
		  	array($this, 'cbFieldAutoSyncInterval'), 'gedeon-sync', 'parameters');

		wp_enqueue_style('gedeon-sync-stylesheet',
		  	plugins_url('css/admin.css', __FILE__));

	}

	/**
	 * Callback for "parameters" section.
	 *
	 * Outputs help/intro text.
	 *
	 * @return void
	 */
	public function cbSettingsParameters() {
		echo "";
	}

	/**
	 * Callback when settings have changed.
	 *
	 * @param mixed $options
	 * @return void
	 */
	public function cbSettingChanged($options) {

		$this->updateCron($options['auto-sync-interval']);
		return $options;

	}

	/**
	 * Callback for "field api key" setting option.
	 *
	 * @return void
	 */
	public function cbFieldApiKey() {
		$options = (array)get_option('gedeon-sync', null);
		$apiKey = $options['api-key'];
		echo <<<EOHTML
		<input type="text" id="api-key"
		size="35"
		name="gedeon-sync[api-key]" value="$apiKey" />
EOHTML;
	}

	/**
	 * Callback for "field api url" setting option.
	 *
	 * @return void
	 */
	public function cbFieldApiUrl() {
		$options = (array)get_option('gedeon-sync', null);
		$apiUrl = $options['api-url'];
		echo <<<EOHTML
		<input type="text" id="api-url"
		size="35"
		name="gedeon-sync[api-url]" value="$apiUrl" />
EOHTML;
	}

	/**
	 * Callback for "field auto-sync-interval" setting option.
	 *
	 * @return void
	 */
	public function cbFieldAutoSyncInterval() {
		$options = (array)get_option('gedeon-sync', null);

		$name = 'auto-sync-interval';
		$value = $options[$name];

		$list = array(
			"disabled"   => __("Disabled"   , "wpgedeon"),
			"hourly"     => __("Hourly"     , "wpgedeon"),
			"twicedaily" => __("Twice Daily", "wpgedeon"),
			"daily"      => __("Daily"      , "wpgedeon"),
		);

		$html = "<select id=\"$name\" name=\"gedeon-sync[$name]\">";

		foreach ($list as $k => $v) {
			$html .= "<option value=\"$k\" "
				. selected($value, $k, false)
				. " >$v</option>";
		}
		$html .= '</select>';

		$nextCron = wp_next_scheduled('gedeon-sync-cron');
		if ($nextCron) {
			$html .= '<p class="description">'
				. __("Next Sync will be at : ", "wpgedeon")
				. date("d/m/Y H:i", $nextCron) . "</p>";
		}

		echo $html;

	}

	/**
	 * Call back for the admin menu.
	 *
	 * @return void
	 */
	public function cbAdminMenu() {

		add_options_page('Gédéon Sync', 'Gédéon Sync',
		  	'manage_options', 'gedeon-sync', array($this, "cbAdminPage"));

	}

	/**
	 * Callback for the admin page.
	 *
	 * @return void
	 */
	public function cbAdminPage() {

		if (isset($_REQUEST['launch-sync-bg'])) {
			// Request to launch the sync, in background.
			$this->launchSyncInBackground();
		}

		$logHistory = (array)get_option('gedeon-sync-log-dates', null);

		// Load details about a date if they are requested
		$details = false;
		if (isset($_REQUEST['details'])) {
			$logDate = $_REQUEST['details'];
			$details = get_transient("gedeon-sync-log-$logDate");
			if ($details == false)
				$details = array(
					__("No sync log available for this date.", "wpgedeon"));
		}

		$syncIsRunning = $this->hasLock();

		include __DIR__ . "/templates/admin.php";

	}

	/**
	 * Set a lock, to prevent concurrent syncing.
	 *
	 * @param bool $value true to set the lock, false to remove it.
	 * @return void
	 */
	private function setLock($value = true) {
		if ($value) {
			set_transient("gedeon-sync-lock", true, 120);
		} else {
			delete_transient("gedeon-sync-lock");
		}
	}

	/**
	 * Returns true if the syncing is currently locked.
	 *
	 * @return bool
	 */
	private function hasLock() {
		return (bool)get_transient("gedeon-sync-lock");
	}
	
	/**
	 * Load the matching synchronizer.
	 * 
	 * @return void
	 */
	public function initializeBestSynchoniser() {
		$basePath = ABSPATH . "wp-content/plugins/wp-plugin-gedeon-sync/synchronizers";		
		$dir = opendir($basePath);
		
		while ($f = readdir($dir)) {
			
			// Check if file  name  match with synchronisers class name pattern
			if (!preg_match("/(.*)Sync\.class\.php/", $f, $m))
				continue;
			
			// Include classfile			
			include_once($basePath . "/" . $f);
			
			// Build classname
			$className = $m[1] . "Sync";
			
			// Check if synchronizer is compatible with current theme
			$isCompatible = call_user_func($className . '::checkCompatibilty');
			
			if (!$isCompatible)
				continue;
			
			// Instanciate compatible sync
			$this->synchronizer = new $className();
			
			break;
			
		}
		
		
	}

	/**
	 * Launch doSync, in background.
	 *
	 * FIXME Should we use spawn_cron instead ?
	 *
	 * @return void
	 */
	private function launchSyncInBackground() {

		$syncUrl = site_url("?gedeon-sync-now");
		$context = stream_context_create(array(
			'http' => array(
				'method' => 'HEAD',
				'timeout' => 1,
			)
		));

		$fd = fopen($syncUrl, 'rb', false, $context);

		fclose($fd);

	}

	/**
	 * Callback for cron event 'gedeon-sync-cron'
	 *
	 * @return void
	 */
	public function cbGedeonSyncCron() {
		$this->doSync();
	}

	/**
	 * Synchronizes from "Gedeon's Ads" to "WpCasa's Properties".
	 *
	 * @return void
	 */
	public function doSync() {
		
		if ($this->synchronizer === null)
			throw new Exception("No synchronizer loaded");
		
		$this->synchronizer->doSync();

	}

	/**
	 * Adds a log message.
	 *
	 * Supports sprintf format.
	 *
	 * @param string $format
	 * @return void
	 */
	public function log($format) {

		$args = func_get_args();
		if (count($args) == 1) {
			$msg = $format;
		} else {
			$msg = call_user_func_array('sprintf', $args);
		}

		$this->logMessages[] = $msg;

		echo nl2br($msg);
		wp_ob_end_flush_all();
		for($i = 0; $i < 1024; $i++)
			echo "\xe2\x80\x8b";
		ob_flush();
		flush();

	}

	/**
	 * Disable (at least, tries) the output buffering.
	 *
	 * @return void
	 */
	public function disableBuffering() {

		// Turn off output buffering
		ini_set('output_buffering', 'off');

		// Turn off PHP output compression
		ini_set('zlib.output_compression', false);

		// Flush (send) the output buffer and turn off output buffering
		while (@ob_end_flush());

		// Implicitly flush the buffer(s)
		ini_set('implicit_flush', true);
		ob_implicit_flush(true);

		// Prevent apache from buffering it for deflate/gzip
		header('Content-type: text/html; charset=utf-8');

		// Recommended to prevent caching of event data.
		header('Cache-Control: no-cache');

		for($i = 0; $i < 1024; $i++)
			echo ' ';
		echo "\n";

	}

	/**
	 * Permissive version of "intval".
	 *
	 * Used to convert " 1 850 m&sup2;" to 1850, for example.
	 *
	 * @param mixed $val
	 * @return int
	 */
	public function toInt($val) {

		if (is_int($val))
			return $val;

		try {
			return (int)preg_replace('/^\D*|\s/', '', $a);
		} catch (Exception $e) {
			return 0;
		}

	}

	/**
	 * Calls wp_upload_dir, customized for property photos.
	 *
	 * Use wp_upload_dir with a temporary filter.
	 *
	 * @return array see wp_upload_dir
	 */
	public function getUploadDir() {
		add_filter('upload_dir', array($this, 'propertyPhotoUploadDirFilter'));
		$upload = wp_upload_dir();
		remove_filter('upload_dir', array($this, 'propertyPhotoUploadDirFilter'));
		return $upload;
	}

	/**
	 * Callback for 'upload_dir' filter.
	 *
	 * see getUploadDir().
	 *
	 * @param array $upload
	 * @return array
	 */
	public function propertyPhotoUploadDirFilter($upload) {
		$upload['subdir'] = '/property-photos';
		$upload['path']   = $upload['basedir'] . $upload['subdir'];
		$upload['url']    = $upload['baseurl'] . $upload['subdir'];
		return $upload;
	}

	/**
	 * Apply default options for this plugin.
	 *
	 * @return void
	 */
	public function applyDefaultOptions() {

		$options = (array)get_option('gedeon-sync', null);
		$options += array(
			'api-key'            => '',
			'api-url'            => 'http://api.gedeon.im',
			'auto-sync-interval' => 'disabled',
		);
		update_option('gedeon-sync', $options);

	}

	/**
	 * Callback to display notice on successful plugin Activation.
	 *
	 * @return void
	 */
	public function cbOnActivateAdminNotice() {

		include __DIR__ . "/templates/plugin-activated-notice.php";

	}

	/**
	 * Called when this plugin is activated.
	 *
	 * Checks php version.
	 *
	 * @return void
	 */
	public static function onActivate() {

		global $wp_version;

		// Check failures messages are stored here.
		$errors = array();

		$minPhpVersion = "5.3.0";
		$minWpVersion  = "3.5";

		if (version_compare(PHP_VERSION, $minPhpVersion, '<'))
			$errors[] = sprintf(
				__("You must have PHP version %s", "wpgedeon"),
			  	$minPhpVersion);

		if (version_compare($wp_version, $minWpVersion, '<'))
			$errors[] = sprintf(
				__("You must have Wordpress version %s", "wpgedeon"),
				$minWpVersion);

		if (empty($errors)) {
			// All went well.

			$inst = self::getInstance();

			$inst->applyDefaultOptions();

			update_option('gedeon-sync-just-activated', true);
			return;

		}

		// Disabled this plugin.
		deactivate_plugins(basename(__FILE__));

		// Die, explaining why.
		$text = sprintf(
			__("Unable to activate the plugin <b>%s</b>:", "wpgedeon"),
			"WpPluginGedeonSync");

		$text .= "<ul><li>" . join("</li><li>", $errors) . "</li></ul>";

		wp_die($text, 'Plugin Activation Error', array(
			'response'  => 200,
			'back_link' => true
		));

	}

}



/**
 * Dedicated Exception for WpPluginGedeonSync
 *
 * @author Christophe Badoit <c.badoit@lesiteimmo.com>
 */
class WpPluginGedeonSyncException extends Exception {}


// Hook when plugin is activated.
register_activation_hook(__FILE__, array('WpPluginGedeonSync', 'onActivate'));

// Initialize the plugin
$instance = WpPluginGedeonSync::getInstance();
add_action('init', array($instance, "init"));

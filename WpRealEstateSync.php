<?php

require_once dirname(__FILE__) . "/" . "LsiPhpApi/LsiPhpApi.php";
require_once dirname(__FILE__) . "/" . "lib/vendor/Drasill/DrQuickTools.inc.php";
require_once dirname(__FILE__) . "/" . "GenericSync.class.php";
require_once dirname(__FILE__) . "/widgets/WpReSyncWidget.class.php";
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php'); 
/**
 * Plugin's main singleton.
 *
 * Most of the time "ad" is a synonym of "property".
 *
 * @author Christophe Badoit <c.badoit@lesiteimmo.com>
 */
class WpRealEstateSync {

	/**
	 * Contains logs messages; see log().
	 *
	 * @var array
	 */
	public $logMessages = array();

	/**
	 * WpRealEstateSync singleton instance
	 *
	 * @var WpRealEstateSync
	 */
	private static $instance = null;
	
	/**
	 * Sychronizer matching with current theme.
	 * 
	 * @var mixed
	 */
	private $synchronizer = null;
	
	/**
	 * Plugin URL.
	 * 
	 * @var string
	 */
	private $pluginUrl = null;
	
	/**
	 * Absolute plugin path.
	 * @var string
	 */
	private $pluginPath = null;

	/**
	 * Constructor, private because i'm singleton
	 *
	 * @return void
	 */
	private function __construct() {

		$this->initCron();
		
		// Initialize plugin path and URL
		$this->pluginPath = plugin_dir_path( __FILE__ );
		$this->pluginUrl = plugins_url( '', __FILE__ );		
		

		add_action('plugins_loaded', array($this, 'cbPluginsLoaded'));
		
		$me = $this;
		
		add_action('init', function() use ($me) {

			if (isset($_GET['wp-re-sync-now'])) {

				try {
					$me->doSync();
				} catch (Exception $e) {
					printf('<p class="error">%s</p>', $e->getMessage());
					die();
				}

			} else {

				if (isset($_GET['wp-re-sync-now-bg'])) {
					$this->launchSyncInBackground();
				}

			}

		}, 100);
		
		add_action('after_setup_theme', function() use ($me) {
			
			$me->initializeBestSynchoniser();
			
		});

		

		if (get_option('wp-re-sync-just-activated')) {

			// Echo admin notice on plugin activation
			add_action('admin_notices', array($this, "cbOnActivateAdminNotice"));
			delete_option('wp-re-sync-just-activated');
			
			// Initialize options
			$this->applyDefaultOptions();
			
			// Set plugin as active
			update_option('wp-re-sync-is-active',true);
			
		}
		
	}

	/**
	 * Returns the singleton instance
	 *
	 * @return WpRealEstateSync instance
	 */
	public static function getInstance() {

		if (self::$instance === null) {
			self::$instance = new WpRealEstateSync();
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

		add_action('wp-re-sync-cron', array($this, 'cbGedeonSyncCron'));

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
			$options = (array)get_option('wp-re-sync', null);
			$interval = $options['auto-sync-interval'];
		}

		if (wp_next_scheduled('wp-re-sync-cron')) {
			// Clear current cron.
			wp_clear_scheduled_hook('wp-re-sync-cron');
		}

		if ($interval == "disabled")
			// Don't set any cron.
			return;

		// Set the cron as requested.
		wp_schedule_event(time() + 3600, $interval, 'wp-re-sync-cron');

	}

	/**
	 * Callback when plugins are loaded ('plugins_loaded')
	 *
	 * @return void
	 */
	public function cbPluginsLoaded() {
		// Load plugin textdomain.
		load_plugin_textdomain('wpres', false,
			dirname(plugin_basename( __FILE__ )) . '/lang/');
	}

	/**
	 * Initialize the plugin's admin section.
	 *
	 * @return void
	 */
	public function cbAdminInit() {

		register_setting('wp-re-sync', 'wp-re-sync',
			array($this, 'cbSettingChanged'));

		add_settings_section('parameters',
			__("Parameters", "wpres"),
			array($this, "cbSettingsParameters"),
			'wp-re-sync');

		add_settings_field('api-key',
			__("Api Key", "wpres"),
			array($this, 'cbFieldApiKey'), 'wp-re-sync', 'parameters');

		add_settings_field('api-url',
			__("Api Url", "wpres"),
			array($this, 'cbFieldApiUrl'), 'wp-re-sync', 'parameters');

		add_settings_field('auto-sync-interval',
			__("AutoSync Interval", "wpres"),
			array($this, 'cbFieldAutoSyncInterval'), 'wp-re-sync', 'parameters');
		
		add_settings_field('photos-quality',
			__("Quality of pictures", "wpres"),
			array($this, 'cbFieldPhotosQuality'), 'wp-re-sync', 'parameters');
		
		add_settings_field('photos-size',
			__("Size of pictures", "wpres"),
			array($this, 'cbFieldPhotosSize'), 'wp-re-sync', 'parameters');

		wp_enqueue_style('wp-re-sync-stylesheet',
			plugins_url('css/admin.css', __FILE__));

	}
	
	/**
	 * Callback when plugin is desactivate.
	 *
	 * @return void
	 */
	public function cbOnDesactivate() {
		
		delete_option('wp-re-sync-is-active');
		
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
		$options = (array)get_option('wp-re-sync', null);
		$apiKey = $options['api-key'];
		echo <<<EOHTML
		<input type="text" id="api-key"
		size="35"
		name="wp-re-sync[api-key]" value="$apiKey" />
EOHTML;
	}

	/**
	 * Callback for "field api url" setting option.
	 *
	 * @return void
	 */
	public function cbFieldApiUrl() {
		$options = (array)get_option('wp-re-sync', null);
		$apiUrl = $options['api-url'];
		echo <<<EOHTML
		<input type="text" id="api-url"
		size="35"
		name="wp-re-sync[api-url]" value="$apiUrl" />
EOHTML;
	}

	/**
	 * Callback for "field auto-sync-interval" setting option.
	 *
	 * @return void
	 */
	public function cbFieldAutoSyncInterval() {
		$options = (array)get_option('wp-re-sync', null);

		$name = 'auto-sync-interval';
		$value = $options[$name];

		$list = array(
			"disabled"   => __("Disabled"   , "wpres"),
			"hourly"     => __("Hourly"     , "wpres"),
			"twicedaily" => __("Twice Daily", "wpres"),
			"daily"      => __("Daily"      , "wpres"),
		);

		$html = "<select id=\"$name\" name=\"wp-re-sync[$name]\">";

		foreach ($list as $k => $v) {
			$html .= "<option value=\"$k\" "
				. selected($value, $k, false)
				. " >$v</option>";
		}
		$html .= '</select>';

		$nextCron = wp_next_scheduled('wp-re-sync-cron');
		if ($nextCron) {
			$html .= '<p class="description">'
				. __("Next Sync will be at : ", "wpres")
				. date("d/m/Y H:i", $nextCron) . "</p>";
		}

		echo $html;

	}
	
	/**
	 * Callback for "field photos-quality" setting option.
	 *
	 * @return void
	 */
	public function cbFieldPhotosQuality() {
		
		$options = (array)get_option('wp-re-sync', null);

		$name = 'photos-quality';
		$value = $options[$name];
		
		$html = "<select id=\"$name\" name=\"wp-re-sync[$name]\">";
		
		for ($i=5;$i<=100;$i += 5) {
			$html .= "<option value=\"$i\" "
				. selected($value, $i, false)
				. " >$i</option>";
		}
		
		$html .= '</select>';

		echo $html;
		
	}
	
		/**
		 * Callback for "field photo size" setting option.
		 *
		 * @return void
		 */
		public function cbFieldPhotosSize() {
			$options = (array)get_option('wp-re-sync', null);
			$size = $options['photos-size'];
			echo <<<EOHTML
			<input type="text" id="photos-size"
			size="35"
			name="wp-re-sync[photos-size]" value="$size" />
EOHTML;
		}

	/**
	 * Call back for the admin menu.
	 *
	 * @return void
	 */
	public function cbAdminMenu() {

		add_options_page('RealEstate Sync', 'RealEstate Sync',
			'manage_options', 'wp-re-sync', array($this, "cbAdminPage"));

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

		$logHistory = (array)get_option('wp-re-sync-log-dates', null);

		// Load details about a date if they are requested
		$details = false;
		if (isset($_REQUEST['details'])) {
			$logDate = $_REQUEST['details'];
			$details = get_transient("wp-re-sync-log-$logDate");
			if ($details == false)
				$details = array(
					__("No sync log available for this date.", "wpres"));
		}

		$syncIsRunning = $this->hasLock();
		$imgUrl = $this->pluginUrl . "/img";

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
			set_transient("wp-re-sync-lock", true, 120);
		} else {
			delete_transient("wp-re-sync-lock");
		}
	}

	/**
	 * Returns true if the syncing is currently locked.
	 *
	 * @return bool
	 */
	private function hasLock() {
		return (bool)get_transient("wp-re-sync-lock");
	}
	
	/**
	 * Load the matching synchronizer.
	 * 
	 * @return void
	 */
	public function initializeBestSynchoniser() {
		$basePath = $this->pluginPath . "/synchronizers";		
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

		$syncUrl = site_url("?wp-re-sync-now");
		$context = stream_context_create(array(
			'http' => array(
				// 'method' => 'HEAD',
				'timeout' => 1,
			)
		));

		$fd = fopen($syncUrl, 'rb', false, $context);		
		
		// while ($contents = fread($fd, 1000)) {
		// 	echo $contents;
		// }

		fclose($fd);

	}

	/**
	 * Callback for cron event 'wp-re-sync-cron'
	 *
	 * @return void
	 */
	public function cbGedeonSyncCron() {
		$this->doSync();
	}

	/**
	 * Synchronizes from "Gedeon's Ads" to theme ads storage.
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
			return (int)preg_replace('/^\D*|\s/', '', $val);
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

		$options = (array)get_option('wp-re-sync', null);
		$options += array(
			'api-key'            => '',
			'api-url'            => 'http://api.gedeon.im',
			'auto-sync-interval' => 'disabled',
			'photos-quality'     => 55,
			'photos-size'        => "1024x768"
		);
		update_option('wp-re-sync', $options);

	}

	/**
	 * Callback to display notice on successful plugin Activation.
	 *
	 * @return void
	 */
	public function cbOnActivateAdminNotice() {

		include __DIR__ . "/templates/plugin-activated-notice.php";

	}

}

/**
 * Dedicated Exception for WpRealEstateSync
 *
 * @author Christophe Badoit <c.badoit@lesiteimmo.com>
 */
class WpRealEstateSyncException extends Exception {}


// Initialize the plugin
$instance = WpRealEstateSync::getInstance();
add_action('init', array($instance, "init"));

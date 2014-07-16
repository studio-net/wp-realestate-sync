<?
/**
 * Generic synchroniser.
 *
 * @author Olivier Barou <olivier@studio-net.fr>
 */
abstract class GenericSync {
	
	/**
	 * Singleton instance.
	 * @var WpRealEstateSync
	 */
	protected $plugin;
	
	/**
	 * Custom post name.
	 * 
	 * @var string
	 */
	protected $adPostType;
	
	/**
	 * Synchronizer's slug.
	 * 
	 * @var string
	 */
	protected $slug;
	
	/**
	 * Options cache.
	 * 
	 * @var array
	 */
	protected $options;
	
	/**
	 * LsiPhpApi instance.
	 * 
	 * @var LsiPhpApi
	 */
	private $lsiApi;
	
	/**
	 * Constructor.
	 * 
	 * @return void
	 */
	public function __construct() {
		
		$this->plugin = WpRealEstateSync::getInstance();
		$this->loadWidgets();
		
		// Read Options
		$this->options = (array)get_option('wp-re-sync', null);
		
	}
	
	/**
	 * Check if current sync is compatible with theme.
	 * 
	 * @return bool
	 */
	public abstract static function checkCompatibilty();
	
	/**
	 * Synchronize properties with API.
	 * 
	 * @return void
	 */
	public abstract function doSync();
	
	/**
	 * Check if all tools are OK to sync.
	 * 
	 * @return bool
	 */
	protected function initializeSync() {
		
		if ($this->hasLock())
			throw new WpRealEstateSyncException(
				__("The syncing is already running; try again in a moment",
			  	"wpres"));
		
		$this->setLock();
		
		// Some conf to ensure sync will go to the end
		ignore_user_abort(true);
		set_time_limit(0);
		ini_set('display_errors', 1);
		
		$this->plugin->disableBuffering();

		// Make sure that this file is included,
		// as wp_generate_attachment_metadata() depends on it.
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		
		// Read Options
		$options = (array)get_option('wp-re-sync', null);
		
		// Check api key is not empty
		if (empty($options['api-key']))
			throw new WpRealEstateSyncException(
				__("Api Key is empty", "wpres"));
		
	}
	
	/**
	 * Set a lock, to prevent concurrent syncing.
	 *
	 * @param bool $value true to set the lock, false to remove it.
	 * @return void
	 */
	protected function setLock($value = true) {
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
	protected function hasLock() {
		return (bool)get_transient("wp-re-sync-lock");
	}
	
	/**
	 * Parse synchronizers widgets dir, read these dirs to find widgets.
	 *
	 * @return void
	 */
	protected function loadWidgets(){

		$rep = dirname(__FILE__) ."/widgets/". $this->slug;
		
		if ( !is_dir($rep))
			return;
		
		$widgets = array();

		// Load widgets
		$iterator = new DirectoryIterator($rep);
		foreach ($iterator as $fileinfo) {

			if ($fileinfo->isFile()) {

				require_once $rep .'/'. $fileinfo->getFilename();
				$widgets[] = str_replace(".class.php", "",
						  $fileinfo->getFilename());


			}
			
		}
				
		// If some widgets were found
		if (empty($widgets))
			return;
		
		// Register widgets
		add_action("widgets_init", function() use ($widgets) {
			
			foreach ($widgets as $widget)
				register_widget($widget);
			
		});


	}
	
	/**
	 * Get LsiPhpApi instance.
	 * 
	 * @return LsiPhpApi
	 */
	protected function getLsiApi() {
		
		if ($this->lsiApi == null) {
			$this->lsiApi = new LsiPhpApi($this->options['api-key']);
			$this->lsiApi->setApiUrl($this->options['api-url']);
		}
		
		return $this->lsiApi;
	
	}
	
	/**
	 * Get WP user for agencies.
	 * 
	 * This is used for themes wich use post author as contact.
	 * So we need to associate a post with a user wich have agency contact infos.
	 *   
	 * @return array (of WP_User)
	 */
	protected function getUsersForAgencies() {
		
		$agencies = $this->getLsiApi()->get('agencies', array());
				
		$users = array();
		
		foreach ($agencies->results as $agency) {
			
			$userId = username_exists($agency->id);
			
			if ( !$userId) {
				$userId = wp_create_user($agency->id, wp_generate_password(), 
					_first($agency->contact->emails));
			}
			
			$users[$agency->id] = get_user_by('id', $userId);
			
		}
		
		return $users;
		
	}
	
	/**
	 * Get a valid photo format from options.
	 * 
	 * Throw WpRealEstateSyncException if data are invalid.
	 * 
	 * @param  string $size
	 * @param  int $quality
	 * @return string
	 */
	protected function getValidPhotoFormatFromOptions($options) {
		
		$size = $options["photos-size"];
		$quality = $options["photos-quality"];
		
		// Valid size format
		if (!preg_match("#\dx\d#", $size)) {
			throw new WpRealEstateSyncException(
				__("Invalid photo size : withxheight expected (ex : 1024x768)",
					"wpres"));
		}
		
		// Valid quality format
		if (!is_int($quality) and ($quality < 0 or $quality > 100)) {
			throw new WpRealEstateSyncException(
				__("Invalid photo quality, choose a value between 5 and 100",
					"wpres"));
		}
		
		// Build full format
		return "{$size}q{$quality}>";
		
		
	}

}
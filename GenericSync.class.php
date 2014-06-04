<?
/**
 * Generic synchroniser.
 *
 * @author Olivier Barou <olivier@studio-net.fr>
 */
abstract class GenericSync {
	
	/**
	 * Singleton instance.
	 * @var WpPluginGedeonSync
	 */
	protected $plugin;
	
	/**
	 * Custom post name.
	 * 
	 * @var string
	 */
	protected $adPostType;
	
	/**
	 * Constructor.
	 * 
	 * @return void
	 */
	public function __construct() {
		
		$this->plugin = WpPluginGedeonSync::getInstance();
		
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
		
		// Some conf to ensure sync will go to the end
		ignore_user_abort(true);
		set_time_limit(0);
		ini_set('display_errors', 1);
		
		$this->plugin->disableBuffering();

		// Make sure that this file is included,
		// as wp_generate_attachment_metadata() depends on it.
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		
		// Read Options
		$options = (array)get_option('gedeon-sync', null);
		
		// Check api key is not empty
		if (empty($options['api-key']))
			throw new WpPluginGedeonSyncException(
				__("Api Key is empty", "wpgedeon"));
		
		
		
		
	}
	
	/**
	 * Set a lock, to prevent concurrent syncing.
	 *
	 * @param bool $value true to set the lock, false to remove it.
	 * @return void
	 */
	protected function setLock($value = true) {
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
	protected function hasLock() {
		return (bool)get_transient("gedeon-sync-lock");
	}
	
	
}
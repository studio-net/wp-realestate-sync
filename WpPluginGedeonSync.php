<?php

/*
Plugin Name: Gedeon Sync
Description: Synchronizes Wordpress custom posts, from Gedeon API
Author: Studionet (c)
Version: 0.1
Requires at least: 3.5
Author URI: http://www.logiciel-immobilier.com/
License: LGPL
*/

require_once dirname(__FILE__) . "/" . "LsiPhpApi/LsiPhpApi.php";

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
	 * Constructor, private because i'm singleton
	 *
	 * @return void
	 */
	private function __construct() {

		$this->initCron();

		$me = $this;
		add_action('wp_loaded', function() use ($me) {

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
			$options = (array)get_option('gedeon-sync');
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
		$options = (array)get_option('gedeon-sync');
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
		$options = (array)get_option('gedeon-sync');
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
		$options = (array)get_option('gedeon-sync');

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

		$logHistory = (array)get_option('gedeon-sync-log-dates');

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
	private function doSync() {

		if ($this->hasLock())
			throw new WpPluginGedeonSyncException(
				__("The syncing is already running; try again in a moment",
			  	"wpgedeon"));

		$this->setLock();

		try {

			ignore_user_abort(true);
			set_time_limit(0);
			ini_set('display_errors', 1);

			$this->disableBuffering();

			// Make sure that this file is included,
			// as wp_generate_attachment_metadata() depends on it.
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			// Read Options
			$options = (array)get_option('gedeon-sync');
			if (empty($options['api-key']))
				throw new WpPluginGedeonSyncException(
					__("Api Key is empty", "wpgedeon"));

			// The "post type" used for realestate properties
			$adPostType = null;

			if (function_exists('wpsight_listing_post_type'))
				$adPostType = wpsight_listing_post_type();

			if (empty($adPostType))
				throw new WpPluginGedeonSyncException(
					__("Could not guess Properties post type", "wpgedeon"));

			// Let's query the Properties posts
			$query = new WP_Query(array(
				"post_type"      => $adPostType,
				"posts_per_page" => -1,
				"post_status"    => "publish,trash",
			));

			$postsById = array();

			// Now parse the posts, to have them by their "ref".
			// Also set the custom fields in to the post's $customFields variable.
			foreach ($query->posts as $post) {

				$customFields = get_post_custom($post->ID);

				$post->customFields = $customFields;

				$postsById[$customFields['_gedeon_id'][0]] = $post;

			}

			$this->setLock();

			// Ends the WP_Query's query.
			wp_reset_postdata();


			// Now, load Ads from gedeon API, and do the effective sync.
			$lsiApi = new LsiPhpApi($options['api-key']);
			$lsiApi->setApiUrl($options['api-url']);

			// We store here posts Ids we have "seen".
			// We'll remove, after this, unseen posts.
			$seenPostsIds = array();

			// Load ads by batches, work until all results are fetched
			$offset = 0;
			$limit  = 100;
			$adCnt = 0;
			while (true) {

				try {

					// Make request
					$r = $lsiApi->get('ads', array(
						"transaction" => "O,R,L,S,H",
						"sort"        => "ad_id asc",
						"offset"      => $offset,
						"limit"       => $limit,
					));

					// Stop if no result.
					if ($r->nb_results === 0)
						break;

					// Parse results.
					foreach ($r->results as $ad) {

						$this->setLock();

						$adCnt++;

						$this->log("\n === [%3d/%3d] : Ad: %s mandat %s (ref %s) ===\n",
							$adCnt, $r->total_results,
							$ad->id, $ad->mandate, $ad->ref);

						$isNewPost = null;

						// $post is the "custom post" linked to the gedeon's "ad".
						$post = $postsById[$ad->id];
						$postData = array();

						if (!$post) {

							// This is a new property, create it
							$postData = array(
								'ID'        => null,
								'post_type' => $adPostType,
							);
							$isNewPost = true;

						} else {

							// Remember we've seen this post.
							$seenPostsIds[(string)$post->ID] = true;

							// Update existing property.
							$postData['ID'] = $post->ID;
							$isNewPost = false;

							// Skip it if api's as modified before last post update.
							$postTime = new DateTime($post->post_modified);
							$adTime = $ad->stats->modified->modify('+ 2 hours');

							if ($postTime > $adTime) {
								$this->log(__("Already up to date (%s > %s)\n", 'wpgedeon'),
									$postTime->format('c'), $adTime->format('c'));
								continue;
							} else {
								$this->log(__("Not up to date (%s < %s)\n", 'wpgedeon'),
									$postTime->format('c'), $adTime->format('c'));
							}

						}

						// Set post data according to ad.
						$postData['post_content'] = $ad->text;
						$postData['post_name']    = $ad->ref;
						$postData['post_title']   = $ad->transaction_type
							. " " . $ad->type->name . " " . $ad->localization->city;

						$postData['post_status'] = 'publish';
						$postData['post_date']   = $ad->stats->created->epoch;


						// Create or Update Post.
						if (empty($postData['ID'])) {

							$this->log(
								__("New property : %s", "wpgedeon") . "\n", $ad->id);
							$postId = wp_insert_post($postData, true);
							if (is_wp_error($postId)) {
								throw new WpPluginGedeonSyncException(sprintf(
									__("Could not create property %s: %s", "wpgedeon"),
									$ad->id, $postId->get_error_message()));
							}

						} else {

							$this->log(
								__("Updating property : %s", "wpgedeon") . "\n", $ad->id);
							$res = wp_update_post($postData, true);
							if (is_wp_error($res)) {
								throw new WpPluginGedeonSyncException(sprintf(
									__("Could not update property %s : %s", "wpgedeon"),
									$ad->id, $res->get_error_message()));
							}
							$postId = $postData['ID'];

						}

						$this->log(__("Saved %s post %s", "wpgedeon") . "\n",
							$adPostType, $postId);

						// Bulk-update of post's metas
						$x = $ad->extras;

						$metas = array(
							"_gedeon_id" => $ad->id,
							"_listing_id" => $ad->mandate,
							"_price"      => ($ad->price > 0 ? $ad->price : null),
							// Bedrooms
							"_details_1"  => $x->nb_chambres->value,
							// Bathrooms
							"_details_2"  => $x->nb_sdb->value + $x->nb_sde->value,
							// Plot Size
							"_details_3"  => $this->toInt($x->surface_terrain->value),
							// Living Area
							"_details_4"  => $ad->surface,
							// Terrace
							"_details_5"  => $this->toInt($x->surface_terrasse->value),
							// Parking
							"_details_6"  => $x->type_parking->value,
							// Heating
							"_details_7"  => $x->chauffage->value,
							// Built in
							"_details_8"  => $x->annee_construction->value,
						);

						// Set transaction_type (in _price_status).
						// FIXME: we should add more status, with the filter
						// "wpsight_listing_statuses".
						switch ($ad->transaction_type) {
						case "Location":
						case "Location de Vacances":
							$metas['_price_status'] = "rent";
							break;
						case "Vente":
						case "Viager":
						case "Bien Vendu":
						default:
							$metas['_price_status'] = "sale";
							break;
						}

						// Sold/rented, based on transaction type.
						$metas['_price_sold_rented'] =
							(int)($ad->transaction_type == "Bien Vendu");


						$loc = $ad->localization;
						$address = "";
						if (!empty($loc->address))
							$address .= "$loc->address, ";
						$address .= "$loc->zip_code $loc->city";
						$metas["_map_address"] = trim($address);

						// Create / update post metas
						foreach ($metas as $key => $value)
							update_post_meta($postId, $key, $value);


						// Create / update post photos.
						// They're wordpress attachments, with postname:
						// "property_photo_{$ad->id}_{$photoCnt}"
						//
						// They're updated according to the "post_modified" timestamp.

						// Read current photos, for updating post.
						$attachments = get_posts(array(
							'post_type' => 'attachment',
							'numberposts' => -1,
							'post_status' => null,
							'post_parent' => $postId,
							'orderby' => 'menu_order',
						));
						$currentPhotos = array();
						foreach ($attachments as $attachment) {
							if (!preg_match('/^property_photo_(\d+)_(\d+)(-\d)?$/',
								$attachment->post_name, $m)) {
									// This does not look like a property photo.
									continue;
								}
							$currentPhotos[] = $attachment;
						}

						sort($currentPhotos);

						$this->log(__("%d photo(s) : ", "wpgedeon"), count($ad->photos));
						foreach ($ad->photos as $photoCnt => $photo) {

							// Read photo timestamp, from URL last path :
							// http://photos.lesiteimmo.com/9099042/0/large/1394097932
							// FIXME: this should be in the api as a "timestamp" field !
							$timeStamp = 0;
							if (preg_match('/\/(\d+)$/', $photo->url, $m))
								$timeStamp = (int)$m[1];

							// Check if there's already a photo at this position,
							// and if "modified" timestamp is more recent than new photo.
							$attachment = null;
							if (!empty($currentPhotos[$photoCnt])) {
								$attachment = $currentPhotos[$photoCnt];
								$oldTimeStamp = strtotime($attachment->post_modified);
								if ($oldTimeStamp > $timeStamp) {
									// Photo is alreay up to date (Valid).
									$this->log("V");
									continue;
								}
							}

							$fileName = "{$ad->id}_{$photoCnt}.jpg";
							$fileType = "image/jpeg";

							// Copy the photo into the upload directory.
							$wpUploadDir = $this->getUploadDir();
							$filePath = "$wpUploadDir[path]/$fileName";
							$url = str_replace('/large/', '/original/', $photo->url);
							file_put_contents($filePath, file_get_contents($url));

							if ($attachment !== null) {

								// Update existing attachment.
								$attachId = $attachment->ID;
								$attachment->post_title    = $ad->desc ?: "photo $photoCnt";
								$now = date('Y-m-d H:i:s');
								$attachment->post_modified = $now;
								$attachment->post_modified_gmt = $now;
								wp_update_post($attachment);

								// Generate the metadata for the attachment,
								// and update the database record.
								$attachData = wp_generate_attachment_metadata(
									$attachId, $filePath);
								wp_update_attachment_metadata($attachId, $attachData);

								$this->log("U"); // Updated

							} else {

								// Create new attachment.

								// Prepare an array of post data for the attachment.
								$attachment = array(
									'guid'           => "$wpUploadDir[url]/$fileName",
									'post_mime_type' => $fileType,
									'post_title'     => $ad->desc ?: "photo $photoCnt",
									'post_content'   => '',
									'post_status'    => 'inherit',
									'post_name'      => "property_photo_{$ad->id}_{$photoCnt}",
								);

								// Insert the attachment.
								$attachId = wp_insert_attachment(
									$attachment, $filePath, $postId);

								// Generate the metadata for the attachment,
								// and update the database record.
								$attachData = wp_generate_attachment_metadata(
									$attachId, $filePath);
								wp_update_attachment_metadata($attachId, $attachData);

								// Set this attachment as the post thumbnail if it's the first
								// photo.
								if ($photoCnt === 0)
									set_post_thumbnail($postId, $attachId);

								$this->log("N"); // (New)
							}

						}

						// Now let's remove photos which were not transmitted.
						if (!$isNewPost) {
							$nbPhotos = count($ad->photos);
							$attachmentsToRemove = array_slice($currentPhotos, $nbPhotos);
							foreach ($attachmentsToRemove as $num =>$attachment) {
								wp_delete_attachment($attachment->ID, true);
								$this->log("D");
							}
						}

						$this->log("\n");

						// Taxonomy : location <-> localization
						wp_set_object_terms($postId, $ad->localization->city, "location");

						// Taxonomy : type <-> localization
						$types = array_unique(array(
							$ad->type->category,
							$ad->type->name,
						));
						wp_set_object_terms($postId, $types, "property-type");

						// Taxonomy : "feature"
						// Add all "extras" with type "bool" and display "true"
						$features = array();
						foreach ($ad->extras as $extra) {
							if ($extra->value === true and $extra->display)
								$features[] = $extra->name;
						}
						wp_set_object_terms($postId, $features, "feature");

					}

					// Increment results
					$offset += $r->nb_results;

					// Reached end
					if ($offset >= $r->total_results)
						break;

				} catch (Exception $e) {
					$this->log(__('Error : %s', "wpgedeon"), $e->getMessage());
				}

			}

			// Now, let's delete (move to trash, actually) the posts we did not see.
			$this->log(__("\n\nLooking up obsolete properties.", "wpgedeon"));

			$trashedCnt = 0;

			foreach ($postsById as $post) {

				if ($post->post_status == "trash")
					continue;

				try {

					if ($seenPostsIds[(string)$post->ID])
						// This post has been seen.
						continue;

					$trashedCnt++;

					$this->setLock();

					// Move this post to trash.
					$this->log(__("Moving Post %s to trash\n", "wpgedeon"),
						$post->ID);

					$postData = array(
						'ID'          => $post->ID,
						'post_status' => 'trash',
					);
					$res = wp_update_post($postData, true);

					if (is_wp_error($res)) {

						throw new WpPluginGedeonSyncException(sprintf(
							__("Could not trash post %s : %s", "wpgedeon"),
							$post->ID, $res->get_error_message()));
					}

				} catch (Exception $e) {
					$this->log(__('Error : %s', "wpgedeon"), $e->getMessage());
				}

			}


			if ($trashedCnt == 0) {
				$this->log("\n\nNo property deleted.");
			} else {
				$this->log("\n\nMoved %d property(ies) to trash", $trashedCnt);
			}


		} catch (Exception $e) {

			$this->log(__('Fatal Error : %s', "wpgedeon"), $e->getMessage());
			$this->log($e->getTraceAsString());

		}

		$this->setLock(false);


		// Save log in a transient
		$logDate = date('c');
		set_transient("gedeon-sync-log-$logDate",
			$this->logMessages, WEEK_IN_SECONDS);

		// Save timestamp in an option
		$logHistory = (array)get_option('gedeon-sync-log-dates');
		$logHistory[] = $logDate;
		// But keep only 30 last logs
		$logHistory = array_slice($logHistory, -30);
		update_option('gedeon-sync-log-dates', $logHistory);

		die('<p>Done Syncing</p>');

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
	private function toInt($val) {

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
	private function getUploadDir() {
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

		$options = (array)get_option('gedeon-sync');
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

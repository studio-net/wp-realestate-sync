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
	 * Taxonomy features name.
	 * @var string
	 */
	protected $featureTagName = "feature";
	
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
	public function doSync() {
			
		try {
			
			// Check sync is ready
			$this->initializeSync();

			$photoFormat = $this->getValidPhotoFormatFromOptions($this->options);

			if (empty($this->adPostType))
				throw new WpRealEstateSyncException(
					__("Could not guess Properties post type", "wpres"));

			// Let's query the Properties posts
			$query = new WP_Query(array(
				"post_type"      => $this->adPostType,
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
			$lsiApi = $this->getLsiApi();

			// We store here posts Ids we have "seen".
			// We'll remove, after this, unseen posts.
			$seenPostsIds = array();
			$users = $this->getUsersForAgencies();

			do_action("wp_re_sync_before_ads_import");

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

						$this->plugin->log("\n === [%3d/%3d] : Ad: %s mandat %s (ref %s) ===\n",
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
								'post_type' => $this->adPostType,
							);
							$isNewPost = true;

						} else {

							// Remember we've seen this post.
							$seenPostsIds[(string)$post->ID] = true;

							// Update existing property.
							$postData['ID'] = $post->ID;
							$isNewPost = false;
							
							// Check if ad is up to date
							if (isset($post->customFields['_gedeon_lastupdate'])) {
								
								// Get lastupdate 
								$lastUpdate = new DateTime(
									_first($post->customFields['_gedeon_lastupdate']));
									
								if ($lastUpdate >=  $ad->stats->modified) {
									
									$this->plugin->log(__("Already up to date (%s > %s)\n", 'wpres'),
										$lastUpdate->format('c'), 
										$ad->stats->modified->format('c'));
									
									continue;
									
								}
								
								$this->plugin->log(__("Not up to date (%s < %s)\n", 'wpres'),
									$lastUpdate->format('c'),
									$ad->stats->modified->format('c'));
								
							}
							
							

						}

						// Set post data according to ad.
						$postData['post_content'] = $ad->text;
						$postData['post_name']    = $ad->ref;
						$postData['post_title']   = $ad->transaction_type
							. " " . $ad->type->name . " " . $ad->localization->city;

						$postData['post_status'] = 'publish';
						$postData['post_date']   = $ad->stats->created->epoch;
						$postData['post_author'] = $users[$ad->agency->id]->ID;

						// Create or Update Post.
						if (empty($postData['ID'])) {

							$this->plugin->log(
								__("New property : %s", "wpres") . "\n", $ad->id);
							$postId = wp_insert_post($postData, true);
							if (is_wp_error($postId)) {
								throw new WpRealEstateSyncException(sprintf(
									__("Could not create property %s: %s", "wpres"),
									$ad->id, $postId->get_error_message()));
							}

						} else {

							$this->plugin->log(
								__("Updating property : %s", "wpres") . "\n", $ad->id);
							$res = wp_update_post($postData, true);
							if (is_wp_error($res)) {
								throw new WpRealEstateSyncException(sprintf(
									__("Could not update property %s : %s", "wpres"),
									$ad->id, $res->get_error_message()));
							}
							$postId = $postData['ID'];

						}

						$this->plugin->log(__("Saved %s post %s", "wpres") . "\n",
							$this->adPostType, $postId);
						
						// These meta should existe, whatever synchronizer is used
						$metas = array(
							"_gedeon_id"         => $ad->id,
							"_gedeon_lastupdate" => $ad->stats->modified->format("c"),
						);

						
						$metas = apply_filters("wp_re_sync_ad_meta_import", 
							$metas, $ad, $postId);

						
						// Create / update post metas
						foreach ($metas as $key => $value) {
							
							if ($value) {
								// Add post meta only if filled
								update_post_meta($postId, $key, $value);
							} else {
								// Else drop it
								delete_post_meta($postId, $key);
							}
						
						}


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

						$this->plugin->log(__("%d photo(s) : ", "wpres"), count($ad->photos));
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
									if ($attachment->menu_order != $photoCnt) {
										// Assert menu_order.
										$attachment->menu_order = $photoCnt;
										wp_update_post($attachment);
										$this->plugin->log("v"); // valid
									} else {
										// Nothing to do
										$this->plugin->log("V"); // Valid
									}
									continue;
								}
							}

							$fileName = "{$ad->id}_{$photoCnt}.jpg";
							$fileType = "image/jpeg";

							// Copy the photo into the upload directory.
							$wpUploadDir = $this->plugin->getUploadDir();
							$filePath = "$wpUploadDir[path]/$fileName";
							$url = str_replace('/large/', "/$photoFormat/", $photo->url);
							file_put_contents($filePath, file_get_contents($url));

							if ($attachment !== null) {

								// Update existing attachment.
								$attachId = $attachment->ID;
								$attachment->post_title    = $ad->desc ?: "photo $photoCnt";
								$now = date('Y-m-d H:i:s');
								$attachment->post_modified = $now;
								$attachment->post_modified_gmt = $now;
								$attachment->menu_order = $photoCnt;
								wp_update_post($attachment);

								// Generate the metadata for the attachment,
								// and update the database record.
								$attachData = wp_generate_attachment_metadata(
									$attachId, $filePath);
								wp_update_attachment_metadata($attachId, $attachData);

								$this->plugin->log("U"); // Updated

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
									'menu_order'     => $photoCnt,
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

								$this->plugin->log("N"); // (New)
							}
							
							do_action("wp_re_sync_after_ad_photo_import", 
								$postId, $attachId);

						}

						// Now let's remove photos which were not transmitted.
						if (!$isNewPost) {
							$nbPhotos = count($ad->photos);
							$attachmentsToRemove = array_slice($currentPhotos, $nbPhotos);
							foreach ($attachmentsToRemove as $attachment) {
								wp_delete_attachment($attachment->ID, true);
								$this->plugin->log("D");
							}
						}

						$this->plugin->log("\n Features for post $postId...");
						
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

						// Also adds "favorite" and "exclusivite"
						if ($ad->extras->type_mandat->value == "exclusif")
							$features[] = "Exclusivité";

						if ($ad->extras->coup_de_coeur->value)
							$features[] = "Coup de coeur";

						wp_set_object_terms($postId, $features, 
							$this->featureTagName);
						
						$this->plugin->log("OK\n");

					}

					// Increment results
					$offset += $r->nb_results;

					// Reached end
					if ($offset >= $r->total_results)
						break;

				} catch (Exception $e) {
					$this->plugin->log(__('Error : %s', "wpres"), $e->getMessage());
				}

			}

			// Now, let's delete (move to trash, actually) the posts we did not see.
			$this->plugin->log(__("\n\nLooking up obsolete properties.", "wpres"));

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
					$this->plugin->log(__("Moving Post %s to trash\n", "wpres"),
						$post->ID);

					$postData = array(
						'ID'          => $post->ID,
						'post_status' => 'trash',
					);
					$res = wp_update_post($postData, true);

					if (is_wp_error($res)) {

						throw new WpRealEstateSyncException(sprintf(
							__("Could not trash post %s : %s", "wpres"),
							$post->ID, $res->get_error_message()));
					}

				} catch (Exception $e) {
					$this->plugin->log(__('Error : %s', "wpres"), $e->getMessage());
				}

			}


			if ($trashedCnt == 0) {
				$this->plugin->log("\n\n" . __("No property deleted.", "wpres"));
			} else {
				$this->plugin->log("\n\n"
					. __("Moved %d property(ies) to trash", "wpres"),$trashedCnt);
			}


		} catch (Exception $e) {

			$this->plugin->log(__('Fatal Error : %s', "wpres"), $e->getMessage());
			$this->plugin->log($e->getTraceAsString());
			$this->setLock(false);

		}

		$this->setLock(false);


		// Save log in a transient
		$logDate = date('c');
		set_transient("wp-re-sync-log-$logDate",
			$this->plugin->logMessages, WEEK_IN_SECONDS);

		// Save timestamp in an option
		$logHistory = (array)get_option('wp-re-sync-log-dates', null);
		$logHistory[] = $logDate;
		// But keep only 30 last logs
		$logHistory = array_slice($logHistory, -30);
		update_option('wp-re-sync-log-dates', $logHistory);

		die('<p>Done Syncing</p>');
	}
	
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
			
			$userId = username_exists(sanitize_user($agency->id));
			
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

<?
/**
 * Generic synchroniser.
 *
 * @author Olivier Barou <olivier@studio-net.fr>
 */
class WpCasaSync extends GenericSync {
	
	/**
	 * Constructor.
	 * 
	 * @return void
	 */
	public function __construct() {
		parent::__construct();

	}
	
	/**
	 * Override.
	 */
	public static function checkCompatibilty() {
		return function_exists('wpsight_listing_post_type');
		
	}
	
	/**
	 * Override. 
	 */
	public function doSync() {
		
		$this->setLock();
		
		if ($this->hasLock())
			throw new WpPluginGedeonSyncException(
				__("The syncing is already running; try again in a moment",
			  	"wpgedeon"));

		$this->setLock();

		try {

			ignore_user_abort(true);
			set_time_limit(0);
			ini_set('display_errors', 1);

			$this->plugin->disableBuffering();

			// Make sure that this file is included,
			// as wp_generate_attachment_metadata() depends on it.
			require_once(ABSPATH . 'wp-admin/includes/image.php');

			// Read Options
			$options = (array)get_option('gedeon-sync', null);
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
								$this->plugin->log(__("Already up to date (%s > %s)\n", 'wpgedeon'),
									$postTime->format('c'), $adTime->format('c'));
								continue;
							} else {
								$this->plugin->log(__("Not up to date (%s < %s)\n", 'wpgedeon'),
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

							$this->plugin->log(
								__("New property : %s", "wpgedeon") . "\n", $ad->id);
							$postId = wp_insert_post($postData, true);
							if (is_wp_error($postId)) {
								throw new WpPluginGedeonSyncException(sprintf(
									__("Could not create property %s: %s", "wpgedeon"),
									$ad->id, $postId->get_error_message()));
							}

						} else {

							$this->plugin->log(
								__("Updating property : %s", "wpgedeon") . "\n", $ad->id);
							$res = wp_update_post($postData, true);
							if (is_wp_error($res)) {
								throw new WpPluginGedeonSyncException(sprintf(
									__("Could not update property %s : %s", "wpgedeon"),
									$ad->id, $res->get_error_message()));
							}
							$postId = $postData['ID'];

						}

						$this->plugin->log(__("Saved %s post %s", "wpgedeon") . "\n",
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
							"_details_3"  => $this->plugin->toInt($x->surface_terrain->value),
							// Living Area
							"_details_4"  => $ad->surface,
							// Terrace
							"_details_5"  => $this->plugin->toInt($x->surface_terrasse->value),
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

						$this->plugin->log(__("%d photo(s) : ", "wpgedeon"), count($ad->photos));
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
									$this->plugin->log("V");
									continue;
								}
							}

							$fileName = "{$ad->id}_{$photoCnt}.jpg";
							$fileType = "image/jpeg";

							// Copy the photo into the upload directory.
							$wpUploadDir = $this->plugin->getUploadDir();
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

						}

						// Now let's remove photos which were not transmitted.
						if (!$isNewPost) {
							$nbPhotos = count($ad->photos);
							$attachmentsToRemove = array_slice($currentPhotos, $nbPhotos);
							foreach ($attachmentsToRemove as $num =>$attachment) {
								wp_delete_attachment($attachment->ID, true);
								$this->plugin->log("D");
							}
						}

						$this->plugin->log("\n");

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

						wp_set_object_terms($postId, $features, "feature");

					}

					// Increment results
					$offset += $r->nb_results;

					// Reached end
					if ($offset >= $r->total_results)
						break;

				} catch (Exception $e) {
					$this->plugin->log(__('Error : %s', "wpgedeon"), $e->getMessage());
				}

			}

			// Now, let's delete (move to trash, actually) the posts we did not see.
			$this->plugin->log(__("\n\nLooking up obsolete properties.", "wpgedeon"));

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
					$this->plugin->log(__("Moving Post %s to trash\n", "wpgedeon"),
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
					$this->plugin->log(__('Error : %s', "wpgedeon"), $e->getMessage());
				}

			}


			if ($trashedCnt == 0) {
				$this->plugin->log("\n\n" . __("No property deleted.", "wpgedeon"));
			} else {
				$this->plugin->log("\n\n"
					. __("Moved %d property(ies) to trash", "wpgedeon"),$trashedCnt);
			}


		} catch (Exception $e) {

			$this->plugin->log(__('Fatal Error : %s', "wpgedeon"), $e->getMessage());
			$this->plugin->log($e->getTraceAsString());
			$this->setLock(false);

		}

		$this->setLock(false);


		// Save log in a transient
		$logDate = date('c');
		set_transient("gedeon-sync-log-$logDate",
			$this->plugin->logMessages, WEEK_IN_SECONDS);

		// Save timestamp in an option
		$logHistory = (array)get_option('gedeon-sync-log-dates', null);
		$logHistory[] = $logDate;
		// But keep only 30 last logs
		$logHistory = array_slice($logHistory, -30);
		update_option('gedeon-sync-log-dates', $logHistory);

		die('<p>Done Syncing</p>');
	}
	
}
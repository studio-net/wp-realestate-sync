<?
/**
 * Synchronise ads for WPCasa themes : http://wpcasa.com/.
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
		
		$this->slug = "wpcasa";
		
		parent::__construct();
		
		// Set hooks
		$this->addSyncHooks();
		
		// Set post_type name
		$this->adPostType = wpsight_listing_post_type();

	}
	
	/**
	 * Override.
	 */
	public static function checkCompatibilty() {
		return function_exists('wpsight_listing_post_type');
		
	}
	
	/**
	 * Hooks for specific things to import.
	 */
	private function addSyncHooks() {
		
		// Filter for metas
		add_filter("wp_re_sync_ad_meta_import", function($metas, $ad, $postId){
			
			// Bulk-update of post's metas
			$x = $ad->extras;

			$metas = $metas + array(
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
				// DPE
				"_dpe" => $x->dpe_conso_en->value ?: $x->dpe_conso_en_lettre->value,
				// GES
				"_ges" => $x->dpe_ges->value ?: $x->dpe_ges_lettre->value,
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
			
			// Categories (mainly for i18n)
			$categories = array(
				"rent" <= __("Rent", "wpres"),
				"sale" <= __("Sale", "wpres"),
			);
			
			// Create category or return ID of existing category
			$catId = wp_create_category(
				$categories[$metas['_price_status']]);
			
			// Add category to post 
			// (endind true mean "append category")
			wp_set_post_categories($postId, $catId, true);
			

			$loc = $ad->localization;
			$address = "";
			if (!empty($loc->address))
				$address .= "$loc->address, ";
			$address .= "$loc->zip_code $loc->city";
			$metas["_map_address"] = trim($address);
			
			return $metas;
			
		}, 10, 3);

	}
	
}
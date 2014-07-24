<?
/**
 * Synchronise ads for Realto theme : 
 * 
 * http://themeforest.net/item/realto-wordpress-theme-for-real-estate-companies
 *
 * @author Olivier Barou <olivier@studio-net.fr>
 */
class RealtoSync extends GenericSync {
	
	/**
	 * Constructor.
	 * 
	 * @return void
	 */
	public function __construct() {		
		
		$this->slug = "realto";
		
		parent::__construct();
		
		// Set hooks
		$this->addSyncHooks();
		
		// Set post_type name
		$this->adPostType = "property";
		
		// Set feature name
		$this->featureTagName = "features";

	}
	
	/**
	 * Override.
	 */
	public static function checkCompatibilty() {
		return function_exists('realto_setup');
	}
	
	/**
	 * Hooks for specific things to import.
	 */
	private function addSyncHooks() {
		
		// Filter for metas
		add_filter("wp_re_sync_ad_meta_import", function($metas, $ad, $postId) {
			
			// Bulk-update of post's metas
			$x = $ad->extras;
			
			$metas = $metas + array(
				"nt_prop_id" => $ad->mandate,
				"nt_listprice"  => ($ad->price > 0 ? $ad->price : null),
				// Rent period
				"nt_period" => $ad->transaction_type == "Location" ? "month" : null,
				// Bedrooms
				"nt_bedrooms"  => $x->nb_chambres->value,
				// Bathrooms
				"nt_bathrooms"  => $x->nb_sdb->value + $x->nb_sde->value,
				// Plot Size
				"nt_plot_size"  => $this->plugin->toInt($x->surface_terrain->value),
				// Living Area
				"nt_living_area"  => $ad->type->category != "Terrain" ? $ad->surface : null,
				// Terrace
				"nt_terrace"  => $this->plugin->toInt($x->surface_terrasse->value),
				// Parking
				"nt_parking"  => $x->type_parking->value,
				// Heating
				"nt_heating"  => $x->chauffage->value,
				// Built in
				"nt_builtin"  => $x->annee_construction->value,
				// Phone
				"nt_property_contact_phone" => $ad->contact->phone,
				// Mail
				"nt_property_contact_email" => $ad->contact->mail,
				// Put favorites on homepage
				"nt_homepage" => $ad->extras->coup_de_coeur->value ? 1 : null,
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
				$metas['nt_status'] = "for-rent";
				break;
			case "Bien Vendu":
				$metas['nt_status'] = "sold";
				break;
			case "Vente":
			case "Viager":
			default:
				$metas['nt_status'] = "for-sale";
				break;
			}
			
			
			// Categories (mainly for i18n)
			$categories = array(
				"for-rent" <= __("Rent", "wpres"),
				"for-sale" <= __("Sale", "wpres"),
			);
			
			// Create category or return ID of existing category
			$catId = wp_create_category(
				$categories[$metas['nt_status']]);
			
			// Add category to post 
			// (endind true mean "append category")
			wp_set_post_categories($postId, $catId, true);
			

			$loc = $ad->localization;
			$address = "";
			if (!empty($loc->address))
				$address .= "$loc->address, ";
			$address .= "$loc->zip_code $loc->city";
			$metas["nt_gmap"] = trim($address);
			
			return $metas;
			
		}, 10, 3);
		
		// Action after photo import
		add_action("wp_re_sync_after_ad_photo_import", 
			function($postId, $attachId) {
				// Realto adds a link beetwen attachment and post
				// Dunno why
				add_post_meta($postId, "nt_propertyimages", 
					$attachId);
			}, 10, 2);
	
	}

}
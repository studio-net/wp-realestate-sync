<?php

/**
 * Displays DPE in WP Casa themes.
 *
 * @author olivier@lesiteimmo.com
 */
class WpReSyncDpe extends WpReSyncWidget {

	private $module;

	/** 
	 * Constructor.
	 * 
	 * @return void
	 */
	public function __construct() {

		parent::__construct(false, '::wpCasa DPE', array(
				"classname"	=> "listing-dpe section clearfix clear",
				"description" => __("Display DPE for given Ad", "wpres"),
			));

	}

	/**
	 * Print front side HTML code.
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget($args, $instance) {

		echo $args["before_widget"];
		echo $args["before_title"];
		echo $instance["title"];
		echo $args["after_title"];
		
		$metas = get_post_meta(get_the_ID());
		
		if (!empty($metas["_dpe"]))
			printf('<img src="http://dpe.lesiteimmo.com/en/%s?size=180x180" />',
				$metas["_dpe"][0]);
		
		if (!empty($metas["_ges"]))
			printf('<img src="http://dpe.lesiteimmo.com/ges/%s?size=180x180" />',
				$metas["_ges"][0]);
					
		
		echo $args["after_widget"];
	}


	/**
	 * Store widget's options in DB
	 *
	 * @param array $new_instance
	 * @param array $old_instance
	 * @return array
	 *
	 * @see WP_Widget::update
	 */
	public function update($new_instance, $old_instance) {
		
		$new_instance['title'] = strip_tags($new_instance['title']);

		return $new_instance;

	}


	/**
	 * print admin side HTML code
	 *
	 * @param array $instance
	 *
	 * @see WP_Widget::form
	 */
	public function form($instance) {

		$instance = wp_parse_args((array) $instance, array());
		$title 	  = isset( $instance['title'] ) ? strip_tags( $instance['title'] ) : false; ?>

		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title', 'wpsight' ); ?>:</label><br />
			<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php

	}


}

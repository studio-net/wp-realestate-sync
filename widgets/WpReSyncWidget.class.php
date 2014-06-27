<?php

/**
 * SubClasses WP_Widget.
 *
 * @uses WP_Widget
 * @package Lesiteimmo
 * @author Olivier Barou <olivier@studio-net.fr>
 */
class WpReSyncWidget extends WP_Widget {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct($id_base = false, $name, $widget_options = array(), $control_options = array()) {
	
		parent::__construct($id_base, $name, $widget_options, $control_options);
	
	}

}

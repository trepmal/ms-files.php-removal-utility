<?php
/*
 * Plugin Name: ms-files.php removal utility
 * Plugin URI: http://trepmal.com/2013/03/24/removing-ms-files-php-dependency/
 * Description: Aids in removing a pre-3.5 Multisite's dependency on ms-files.php
 * Author: Kailey Lampert
 * Author URI: kaileylampert.com
 * License: GPLv2 or later
 *
*/

$msfiles_removal_utility = new MSFiles_Removal_Utility();

class MSFiles_Removal_Utility {
	/**
	 * MSFiles_Removal_Utility::__construct
	 * 
	 * get hooked in
	 *
	 * @return void
	 */
	function __construct() {
		add_action( 'network_admin_menu', array( &$this, 'network_admin_menu' ) );
	}

	/**
	 * MSFiles_Removal_Utility::network_admin_menu
	 * 
	 * Create a page in the Network Admin
	 *
	 * @return void
	 */
	function network_admin_menu() {
		add_submenu_page( 'settings.php', 'ms-files.php removal utility', 'ms-files.php removal utility', 'edit_posts', __FILE__, array( &$this, 'page' ) );
	}

	/**
	 * MSFiles_Removal_Utility::page
	 * 
	 * Page content
	 *
	 * @return void
	 */
	function page() {
		?><div class="wrap">
		<h2><?php _e('ms-files.php removal utility'); ?></h2>

		<p>First, move (or copy, and delete later when you're sure) your images from <code>blogs.dir/BLOG_ID/files/</code> to <code>/uploads/sites/BLOG_ID/</code>.</p>
<pre>mkdir /path/to/wp-content/sites
cd /path/to/wp-content/sites
cp -r /path/to/wp-content/blogs.dir/BLOG_ID/files .
mv files BLOG_ID</pre>

		<p>When you press the button a few things will happen on each site</p>
		<ol>
		<li>Old file urls in post_content will be replaced with the new url</li>
		<li>If you have domain mapping, this will be repeated for those urls</li>
		<li>theme_mods will be rewritten with the urls replaced</li>
		<li>relative urls <strong>will not</strong> be replaced</li>
		<li>the upload_path, upload_url_path, and fileupload_url options will be emptied</li>
		</ol>
		<p>You may wish to run a bigger SQL find-and-replace on your own with <a href="http://interconnectit.com/products/search-and-replace-for-wordpress-databases/">http://interconnectit.com/products/search-and-replace-for-wordpress-databases/</a></p>
		<p>Afterward, <code>ms_files_rewriting</code> will be set to false</p>

		<form method='post'>
		<input type="hidden" name="do_eet" value="do_eet" />
		<?php 
			wp_nonce_field( 'na_nmmsf', 'nn_nmmsf');
			submit_button( 'Go' );
		?>
		</form>

		<?php

		//this does a test
		if ( ! isset( $_POST['do_eet'] ) || 'do_eet' != $_POST['do_eet'] ) {
			echo '<h2>Test mode:</h2>';
			$this->update_all_blogs();
		} else {
			if ( wp_verify_nonce( $_POST['nn_nmmsf'], 'na_nmmsf' ) ){
				$this->update_all_blogs( false );
				update_site_option( 'ms_files_rewriting', 0);
			}
			else {
				echo '<strong>bad nonce</strong>';
			}
		}

		?>

		</div><?php
	}

	/**
	 * MSFiles_Removal_Utility::update_all_blogs
	 *
	 * Perform the updates per-blog
	 *
	 * @param bool $test true to keep things safe. false to perform changes
	 * @return void
	 */
	function update_all_blogs( $test = true ) {

		global $wpdb;
		$query =  "SELECT * FROM {$wpdb->blogs}, {$wpdb->registration_log}
							WHERE site_id = '{$wpdb->siteid}'
							AND {$wpdb->blogs}.blog_id = {$wpdb->registration_log}.blog_id";

		$blog_list = $wpdb->get_results( $query, ARRAY_A ); //get blogs
		$wpdb->flush();
		unset( $wpdb );
		/**
		 * taken from a script where we want all blogs
		 * for the sake of removeing ms-files.php, we DON'T want the primary site
		 * add main site to beginning
		 */
		// $blog_list[-1] = (array) get_blog_details( 1 );
		ksort($blog_list);

		foreach( $blog_list as $k => $info ) {

			$bid = $info['blog_id'];

			switch_to_blog( $bid );
			// global $wpdb;

			echo '<div class="blog-option-group">';
			echo '<h3>'. get_option( 'blogname' );

				//general info
				$plugins = site_url('/wp-admin/plugins.php');
				$dash = site_url('/wp-admin/');
				$view = home_url();
				$edit = network_admin_url( "site-info.php?id=$bid" );

				$edit_label = __( 'Edit' );
				$view_label = __( 'View' );
				$dashboard_label = __( 'Dashboard' );
				$plugins_label = __( 'Plugins page' );

				echo " <small class='alignright'>[<a href='$edit'>($bid) $edit_label</a>] [<a href='$view'>$view_label</a>] [<a href='$dash'>$dashboard_label</a>] [<a href='$plugins'>$plugins_label</a>] </small>";
			echo '</h3>';


			// replace normal urls
			$home = home_url();
			$old = $home . '/files/';
			$new = $home . "/wp-content/uploads/sites/$bid/";
			echo '<p>replace urls. <br />';
			var_dump( $this->update_db_tables( $old, $new, $test ) );
			echo '</p>';

			$mods = get_theme_mods();

			// multidimensional find-and-replace
			$mods = $this->str_replace_json( $old, $new, $mods);

			// if domain mapping, replace those too
			if ( function_exists('dm_text_domain') ) {
				$dm = $this->get_mapped_url( $bid );
				if ( !empty( $dm ) ) {
					$home = untrailingslashit( esc_url( $dm ) );
					$old = $home . '/files/';
					$new = $home . "/wp-content/uploads/sites/$bid/";
					echo '<p>replace mapped urls. <br />';
					var_dump( $this->update_db_tables( $old, $new, $test ) );
					echo '</p>';
					$mods = $this->str_replace_json( $old, $new, $mods);
				}
			}

			// cast parts for $mods back into arrays
			$mods = $this->mods_restore_types( $mods );

			if ( !$test ) {
				$this->update_theme_mods( $mods );
			} else {
				echo '<p>update theme mods</p>';
			}

			// empty paths/urls
			if ( $test ) {
				echo '<p>empty <em>upload_path</em></p>';
				echo '<p>empty <em>upload_url_path</em></p>';
				echo '<p>empty <em>fileupload_url</em></p>';
			} else {
				update_option( 'upload_path', '' );
				update_option( 'upload_url_path', '' );
				update_option( 'fileupload_url', '' );
				echo '<p>updated options</p>';
			}

			echo '</div><hr />';

			restore_current_blog();

			// break; // just do the first one
		}

	}

	/**
	 * MSFiles_Removal_Utility::mods_restore_types
	 * 
	 * make sure the parts that need to be arrays not objects (json's fault) are arrays
	 *
	 * @param object|array $mods Theme mods 
	 * @return array Theme modes with correct casting
	 */
	function mods_restore_types( $mods ) {
		$mods = (array) $mods;

		// make sure the parts that need to be arrays not objects (json's fault) are arrays
		foreach( array( 'nav_menu_locations', 'header_image_data') as $key )
			if ( isset( $mods[ $key ] ) )
				$mods[ $key ] = (array) $mods[ $key ];

		return $mods;
	}

	/**
	 * MSFiles_Removal_Utility::update_theme_mods
	 * 
	 * Update all theme mods at once
	 *
	 * @param array $mods Theme mods
	 * @return void
	 */
	function update_theme_mods( $mods ) {
		$theme_slug = get_option( 'stylesheet' );
		update_option( "theme_mods_$theme_slug", $mods );
	}

	/**
	 * MSFiles_Removal_Utility::str_replace_json
	 * 
	 * Convert multi-dimensional array to json, peform str_replace, convert back
	 *
	 * @param string $search What to find
	 * @param string $replace What to replace in
	 * @param object|array $subject Multi-dimensional array or object
	 * @return object
	 */
	function str_replace_json( $search, $replace, $subject ) {

		$json = json_encode($subject);

		$search = str_replace( '/', '\/', $search );
		$replace = str_replace( '/', '\/', $replace );

		$replaced = str_replace( $search, $replace,  $json );

		return json_decode( $replaced );
	}


	/**
	 * MSFiles_Removal_Utility::update_db_tables
	 * 
	 * get hooked in
	 *
	 * @param string $old What to find
	 * @param string $new What to replace in
	 * @param bool $test Is this a test or real
	 * @return int|string Number of rows affected by query, or the SQL string if testing 
	 */
	function update_db_tables( $old, $new, $test=true ) {
		global $wpdb;

// doing a straight swap. If you need to be more specific, go for it, but take care to make other changes for the theme mods updates
$q = "
	UPDATE $wpdb->posts 
	SET post_content = 
	REPLACE ( post_content, '$old', '$new' )
	";
		if ( $test ) {
			$return = $q;
		} else {
			$return = $wpdb->query( $q );
		}
		$wpdb->flush();
		return $return;
	}

	/**
	 * MSFiles_Removal_Utility::get_mapped_url
	 * 
	 * Get domain mapped URL
	 *
	 * @param int $blog_id Blog ID to get domain of
	 * @return string Domain name
	 */
	function get_mapped_url( $blog_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->dmtable} WHERE blog_id = %d", $blog_id ) );
		$wpdb->flush();
		return $row->domain;
	}

}

// never know when this will be handy
if ( ! function_exists( 'printer') ) {
	function printer( $input ) {
		echo '<pre>' . print_r( $input, true ) . '</pre>';
	}
}
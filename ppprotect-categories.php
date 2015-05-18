<?php 
class PPProtect
{
	/*
	 * Admin functions & Plugin setup
	 */
    function __construct() 
	{
		if(defined("ABSPATH")) 
		{
			require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );
		}
	}

	// Add new hooks into WP
	public function ppprotectHooks() 
	{
		// Creates the PPProtect table
		register_activation_hook( __FILE__, array($this, 'ppprotectCreateTable') );

		// Adds admin styles
		add_action( 'admin_enqueue_scripts', array($this, 'ppprotectAdminStyles') );

		// Add new options into edit-tags.php?taxonomy=category
		add_action( 'category_edit_form_fields', array($this, 'ppprotectEditFormFields') );
		add_action( 'category_add_form_fields', array($this, 'ppprotectEditFormFields') );

		// Saves new ppp category options
		add_action ( 'created_category', array($this, 'ppprotectSaveFields') );
		add_action ( 'edited_category', array($this, 'ppprotectSaveFields') );

		// Protect categories by hooking into any loops
		add_action ( 'loop_start', array($this, 'ppprotectCategory') );

		// Protect posts by hooking into any loops
		add_action ( 'the_post', array($this, 'ppprotectPost') );

		// Add admin area warning that post permission levels are being overridden by a category
		add_action ( 'edit_form_after_editor', array($this, 'ppprotectPostWarning') );

		// Buffer stuff... to allow for the redirect
		add_action( 'init', array($this, 'ppprotectObStart') );
		add_action( 'wp_footer', array($this, 'ppprotectObEnd') );

		// Add AJAX function to allow users to override each post manually and ignore the category override
		add_action( 'wp_ajax_pp_category_override', array($this, 'wp_ajax_ppprotectAllowOverride') );

		$categories = get_current_screen();
		if ( $categories->base == 'edit-tags' )
		{
			// Add JS to alert user when they perform certain actions
			add_action( 'admin_footer', array($this, 'ppprotectCategoryJS') );
		}
	}

	// Create a custom table for PPProtect
	public function ppprotectCreateTable() 
	{
		global $wpdb;
		global $ppprotectDbVersion;
		$ppprotectDbVersion = '1.0';

		$table_name = $wpdb->prefix . 'ppprotect';
		
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			type VARCHAR(50) NOT NULL,
			itemId int UNIQUE NOT NULL,
			name VARCHAR(100) NOT NULL,
			levels TEXT NOT NULL,
			redirect VARCHAR(255) NOT NULL,
			protectposts int(2) NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";

		dbDelta( $sql );

		add_option( 'ppprotectDbVersion', $ppprotectDbVersion );
	}

	// Saves ppprotect data in the database
	private function ppprotectInsertInDb( $type, $id, $name, $levels, $redirect, $protectposts )
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'ppprotect';
		$wpdb->replace( $table_name, array( 
			'type' => $type, 
			'itemId' => $id, 
			'name' => $name, 
			'levels' => $levels,
			'redirect' => $redirect,
			'protectposts' => $protectposts
			) 
		);
	}

	// Saves ppprotect data in the database
	private function ppprotectGetFromDb( $itemId )
	{
		global $wpdb;
		$id = intval($itemId);
		$table = $wpdb->prefix . 'ppprotect';

		if( $wpdb->get_var("SHOW TABLES LIKE '$table'") === null ) 
		{
		    $this->ppprotectCreateTable();
		}

		$row = $wpdb->get_row('SELECT * FROM ' . $table . ' WHERE itemId = ' . $id);

		return $row;
	}

	// Register & enqueue admin styles
	public function ppprotectAdminStyles() 
	{
        wp_register_style( 'ppprotect_admin_css', plugins_url( 'pp-categories-admin-styles.css', __FILE__ ), false );
        wp_enqueue_style( 'ppprotect_admin_css' );
	}

	// Callback buffer
	public function ppprotectCallback($buffer)
	{
 		return $buffer;
		}

		// Start buffering
		public function ppprotectObStart()
	{
		ob_start( array($this, "ppprotectCallback") );
	}

	// End buffering
	public function ppprotectObEnd()
	{
		ob_end_flush();
	}


	/*
	 * PP Protect Categories
	 */
 	// Generates the HTML content to be displayed in the add and edit categories sections
	public function ppprotectEditFormFields ( $tag ) 
	{
		if ( isset($tag->term_id) )
		{
			$tagId = $tag->term_id;
			$cLevels = $this->ppprotectGetFromDb( $tagId );

			if ( isset($cLevels) )
			{
				$checkedLevels = json_decode($cLevels->levels);
				$redirectTo = $cLevels->redirect;
				$postProtect = $cLevels->protectposts;
			}
		}

		$memLevels = $this->ppprotectGetPPMemLevels();

		// Used to test off grid only
		if ( !isset( $memLevels ) )
		{
			$membLevels = array('test1', 'test2', 'test3');
		}

		$ppprotectCat = '<div class="form-field ppprotect-wrap"><label class="ppp-title" for="ppprotect-category">PilotPress Permissions</label><div class="ppprotect-levels-redirect"><div class="ppprotect-levels-message">1. Select the permission levels of users that can access this category of posts.</div><div class="ppprotect-category-levels">';

		foreach ( $memLevels as $level )
		{
			if ( isset( $checkedLevels ) && in_array( $level, $checkedLevels ) ) 
			{ 
				$checked = 'checked'; 
			} 
			else 
			{ 
				$checked = '';
			}

			$ppprotectCat .= '<div class="ppprotect-cat-level-wrap"><label><input type="checkbox" name="ppprotectCat[' . $level . ']" ' . $checked . ' /> ' . $level . '</label></div>';
		}

	    $ppprotectCat .= '<p><em>(Leave blank to allow access to all users.)</em></p></div>';

	    // Start redirect code
	    $ppprotectCat .= '<div class="ppprotect-on-error"><div class="ppprotect-levels-message" style="margin-top: 10px;">2. If users don\'t have the above selected permissions, redirect them here.</div><select name="ppprotectRedirect"><option value="">' . esc_attr( __( "Select page" ) ) . '</option>';
							
		$pages = get_pages(); 
		foreach ( $pages as $page ) 
		{
			if ( isset( $redirectTo ) && $redirectTo == get_page_link( $page->ID ) )
			{
				$selected = 'selected="selected"';
			}
			else
			{
				$selected = '';
			}

			$ppprotectCat .= '<option value="' . get_page_link( $page->ID ) . '" ' . $selected . '>' . $page->post_title . '</option>';

		}

		$ppprotectCat .= '</select></div>'; // End Redirect code

		if ( isset( $postProtect ) && $postProtect == true )
		{
			$pChecked = 'checked';
		}
		else
		{
			$pChecked = '';
		}

		$ppprotectCat .= '<div class="ppprotect-all-posts"><div>3. Also protect all individual posts in this category?</div><div class="ppprotect-posts"><label><input type="checkbox" name="ppprotectPosts" ' . $pChecked . ' /> Yes</label></div></div>';

		$ppprotectCat .= '</div></div>'; // End PP Permissions code

	    echo $ppprotectCat;
	}

	// Communicates with PilotPress and gets the site's membership levels
	protected function ppprotectGetPPMemLevels()
	{
		require_once( plugin_dir_path( __FILE__ ) . '../pilotpress/pilotpress.php' );

		$pp = new PilotPress();
	    $membershipLevels = $pp->get_setting("membership_levels", "oap", true);

	    return $membershipLevels;
	}

	// Check's the current user's membership level's against others
	protected function ppprotectAccessCheck( $memLevel ) 
	{			
		if( isset( $_SESSION['user_levels'] ) && is_array( $_SESSION['user_levels'] ) && in_array( $memLevel, $_SESSION['user_levels'] )) 
		{
			return 1;
		} 
		else 
		{
			return 0;
		}
	}

	// Saves the protected category values
	public function ppprotectSaveFields( $term_id )
	{
		if ( isset( $_POST['ppprotectCat'] ) ) 
		{
			$redirect = $_POST['ppprotectRedirect'];
			$protectPosts = $_POST['ppprotectPosts'];

			if ( $protectPosts === 'on' )
			{
				$protectPosts = 1;
			}
			else
			{
				$protectPosts = 0;
			}

			$type = 'category';

			if ( isset($_POST['name']))
			{
				$name = $_POST['name'];
			}
			else
			{
				$name = get_cat_name( $term_id );
			}

			$pppCategory = array();
			foreach ( $_POST['ppprotectCat'] as $key => $val )
			{
				array_push($pppCategory, $key);
			}
			$levels = json_encode($pppCategory);

			$this->ppprotectInsertInDb( $type, $term_id, $name, $levels, $redirect, $protectPosts );
		}
	}

	// Protects categories
	public function ppprotectCategory()
	{
		global $wp_query;
		
		if ( isset($wp_query->queried_object->term_id) )
		{
			$catId = $wp_query->queried_object->term_id;
			$perms = $this->ppprotectGetFromDb( $catId );
		
			$userAccessLevels = [];

			$levels = json_decode($perms->levels);
			foreach ( $levels as $level )
			{
				if ( $this->ppprotectAccessCheck($level) === 1 )
				{
					array_push($userAccessLevels, $level);
				}
			}

			// If user does not have any access levels granted... redirect them
			if ( empty($userAccessLevels) )
			{
				if ( !current_user_can('administrator') ) 
				{
					wp_redirect( $perms->redirect ); 
					exit;
				}
			}
		}
	}

	/* 
	 * Protects category posts
	 *
	 * @var array $userAccessLevels The levels of access the user
	 * @var array $userAccessLevels The levels of access the user
	 *
	 */
	public function ppprotectPost()
	{
		if (!is_admin() && is_single())
		{
			global $wp_query;
			$postID = $wp_query->post->ID;
			$catOfPost = get_the_category($postID);
			$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );

			$userAccessLevels = [];
			$protectCategories = [];
			foreach ( $catOfPost as $cat )
			{
				$catId = $cat->term_id;
				$perms = $this->ppprotectGetFromDb( $catId );

				if ( isset($perms) )
				{
					array_push($protectCategories, $perms);
				}
			
				if ( isset($perms) )
				{
					$levels = json_decode($perms->levels);
					foreach ( $levels as $level )
					{
						if ( $this->ppprotectAccessCheck($level) === 1 )
						{
							array_push($userAccessLevels, $level);
						}
					}
				}
			}

			// If user does not have any access levels granted & the post is in a protected category & the post isn't manually specified to use it's own permissions... redirect them to the first categories redirect
			if ( empty($userAccessLevels) && !empty($protectCategories) && $selectedOverride != 'post-override' )
			{
				// If admin user is logged in, let them see the page. Comment this out to test functionality
				if ( !current_user_can('administrator') ) 
				{
					foreach ( $protectCategories as $protectedCat )
					{
						if ( $protectedCat->protectposts == 1 )
						{
							wp_redirect( $protectedCat->redirect );
							exit;
						}
					}
				}
			}
		}
	}

	public function wp_ajax_ppprotectAllowOverride()
	{
		if( !empty($_POST) )
	    {
	        update_post_meta( $_POST['postID'], '_ppProtectCatOverride', $_POST['ppOverride']);
	        $response = get_post_meta( $_POST['postID'], '_ppProtectCatOverride', true );
	    } 
	    else 
	    {
	        $response = "No POST detected.";
	    }

	    header( "Content-Type: application/json" );
	    echo json_encode($response);
	    exit();
	}

	// Warns users when global cateogry protection settings are taking prescendence 
	public function ppprotectPostWarning()
	{
		if ( is_admin() )
		{
			global $post;
			$postID = $post->ID;
			$catOfPost = get_the_category($postID);

			$protectedCategories = [];
			foreach ( $catOfPost as $cat )
			{
				$catId = $cat->term_id;
				$perms = $this->ppprotectGetFromDb( $catId );
				if ( isset($perms) && $perms->protectposts == 1 )
				{
					array_push($protectedCategories, $perms);
				}
			}

			$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );
			switch ($selectedOverride)
			{
				case 'post-override': 
					$postOverride = 'selected="selected"';
					$catOverride = '';
					break;

				case 'category-override':
					$catOverride = 'selected="selected"';
					$postOverride = '';
					break;

				default:
					$catOverride = '';
					$postOverride = '';
			}

			// If post is protected globally, add a message.
			if ( !empty($protectedCategories) )
			{
				$message = '<div class="ppprotect-protected-global-wrapper inside">
					<div class="ppprotect-protected-globally">
						<div class="ppprotect-global-message">This post has global category permissions set in the following category(s) overriding the permissions usually found here.</div>
						<div class="ppprotect-global-cats">';

				foreach ( $protectedCategories as $protectedCategory )
				{
					$message .= '<a href="' . site_url() . '/wp-admin/edit-tags.php?action=edit&taxonomy=category&tag_ID=' . $protectedCategory->itemId . '">' . $protectedCategory->name . '</a>';
					
				}

				$message .= '</div>';

				if ( is_array($protectedCategories) && count($protectedCategories) !== 1 )
				{
					$message .= '<div class="ppprotect-main-override">The category whose redirect will take prescedence is:</div>
						<div class="ppprotect-override-name"><a href="' . site_url() . '/wp-admin/edit-tags.php?action=edit&taxonomy=category&tag_ID=' . $protectedCategories[0]->itemId . '">' . $protectedCategories[0]->name . '</a></div>';
				}

				$message .= '<div class="ppprotect-override-location">It currently redirects users to:<br /><a href="' . $protectedCategories[0]->redirect . '" target="_blank">' . $protectedCategories[0]->redirect . '</a></div>
						<div class="ppprotect-override-perms">Users with the following permission levels have access to this post:';

				if ( isset($protectedCategories[0]->levels) && !empty($protectedCategories[0]->levels) )
				{
					foreach ( json_decode($protectedCategories[0]->levels) as $level )
					{
						$message .= '<div>' . $level . '</div>';
					}
				}
				else
				{
					$message .= '<div>No permissions have been selected.</div>';
				}

				$message .= '</div>
					</div>
					<div class="ppprotect-override-override">
						<select name="ppprotectManualOverride">
							<option value="category-override" ' . $catOverride . '>Category override</option>
							<option value="post-override" ' . $postOverride . '>Set permissions manually</option>
						</select>
					</div>
				</div>';

				echo $message;

				// Bind the JS to the footer to control the category override settings
				add_action( 'admin_footer', array( $this, 'ppprotectAdminFooterScripts') );

			}
			
		}		
	}

	public function ppprotectAdminFooterScripts()
	{
		global $post;
		$postID = $post->ID;

		$selectedOverride = get_post_meta( $postID, '_ppProtectCatOverride', true );

		$jsMods = '<script type="text/javascript">
			jQuery(document).ready(function()
			{
				jQuery("#_pilotpress_page_box .inside").addClass("pp-page-box").hide();
				jQuery(".ppprotect-protected-global-wrapper").appendTo(jQuery("#_pilotpress_page_box"));';

			if ( !isset($selectedOverride) || $selectedOverride == 'post-override' )
			{
				$jsMods .= 'jQuery(".pp-page-box").show();
							jQuery(".ppprotect-protected-globally").hide();';
			}

		$jsMods .= '
				jQuery(".ppprotect-override-override select").change(function() {
						
					var selectedOption = jQuery(this).val();
					jQuery.ajax({
						type: "POST",
						url: ajaxurl,
						data: { 
							action: "pp_category_override",
							postID: ' . $postID . ', 
							ppOverride: selectedOption 
						}
					}).done(function( response ) {
						if ( response == "post-override" ) {
							jQuery(".pp-page-box").show();
							jQuery(".ppprotect-protected-globally").hide();
						}
						else if ( response == "category-override" ) {
							jQuery(".pp-page-box").hide();
							jQuery(".ppprotect-protected-globally").show();
						}
					});

				});
			});
		</script>';

		echo $jsMods;
	}


	public function ppprotectCategoryJS()
	{
		$catFoot = '<script type="text/javascript">
			jQuery(".ppprotect-posts input:checkbox").change(function()
			{
				if ( this.checked === true )
				{
					var accept = confirm("IMPORTANT - By selecting this option you will override the PilotPress permission settings you may have already added to any of the posts in this category. This means that the settings you just selected here will take prescedence. Once you save this setting you will be able to manually set permissions for each post, but you will have to open each post and select the option \'Set permissions manually\' to do so. Are you sure you want to proceed with this setting?");
					if ( accept != true )
					{
						jQuery(this).prop("checked", false);
					}
				}
			});
		</script>';

		echo $catFoot;
	}
}
<?php

/**
 * The admin-specific functionality of the plugin.
 *					
 * @link			https://devonostendorf.com/projects/#post-notif
 * @since      1.0.0
 *
 * @package    Post_Notif
 * @subpackage Post_Notif/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and enqueues the admin-specific
 *	JavaScript.
 *
 * @since      1.0.0
 * @package    Post_Notif
 * @subpackage Post_Notif/admin
 * @author     Devon Ostendorf <devon@devonostendorf.com>
 */
class Post_Notif_Admin {
		  	
	/**
	 * The ID of this plugin.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @var     string	$plugin_name	The ID of this plugin.
	 */
	private $plugin_name;
									
	/**
	 * The version of this plugin.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @var		string	$version	The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since	1.0.0
	 * @param	string	$plugin_name	The name of this plugin.
	 * @param	string	$version	The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since	1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * An instance of this class should be passed to the run() function
		 * defined in Post_Notif_Admin_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Post_Notif_Admin_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/post-notif-admin.js', array( 'jquery' ), $this->version, false );

	}
	
	
	// Functions related to adding Post Notif meta box to Edit Post page
	
	/**
	 * Add meta box to Edit Post page.
	 *
	 * @since	1.0.0
	 */	
	public function add_post_notif_meta_box() {
	
		add_meta_box(
			'post_notif'
			,'Post Notif'
			,array( $this, 'render_post_notif_meta_box' )
			,'post'
		);
		
	}
	
	/**
	 * Render meta box on Edit Post page.
	 *
	 * @since	1.0.0
	 * @param	WP_Post	$post	The object for the current post/page.
	 */
	public function render_post_notif_meta_box( $post ) {
		
		if ( get_post_status($post->ID) == 'publish' ) {
			
			// Post has been published, allow Post Notif send
				  
			global $wpdb;			
		
			// Tack prefix on to table name
			$post_notif_post_tbl = $wpdb->prefix.'post_notif_post';
			
			echo '<input type="hidden" name="hdnPostID" id="id_hdnPostID" value="' . $post->ID . '" />';

			$notif_sent_dttm = $wpdb->get_var( 
				$wpdb->prepare(
					"SELECT notif_sent_dttm FROM " . $post_notif_post_tbl . " WHERE post_id = %d"
					,$post->ID
				)		
			);
			if ( $notif_sent_dttm == null ) {
		
				// Display Send Post Notif button
				echo '<input type="button" name="btnSendNotif" id="id_btnSendNotif" value="' . __( 'Send', 'post-notif' ) . '" />';
				echo '	<span id="id_spnPostNotifLastSent"></span>';
			}
			else {
				  
				// Already sent, display RESEND Post Notif button and last sent date
				echo '<input type="button" name="btnSendNotif" id="id_btnSendNotif" value="' . __( 'RESEND', 'post-notif' ) . '" />';
				echo '<span id="id_spnPostNotifLastSent">Last sent: ' . date( "F j, Y", strtotime( $notif_sent_dttm ) )
					. " at "
					. date( "g:i:s A", strtotime( $notif_sent_dttm ) )
					. "</span>"
				;
			}
		}
		else {
			_e( 'Post has not yet been published.', 'post-notif' );
		}
		
	}
	
	/**
	 * Enqueue AJAX script that fires when "Send Notif" button (in meta box on Edit Post page) is pressed.
	 *
	 * @since	1.0.0
	 * @param	string	$hook	The current page name.
	 *	@return	null	If this is not post.php page
	 */
	public function send_post_notif_enqueue( $hook ) {
	
		if ( 'post.php' != $hook ) {
				  
			return;
		}
		   
		$post_notif_send_nonce = wp_create_nonce( 'post_notif_send' );
		wp_localize_script(
			$this->plugin_name
			,'post_notif_send_ajax_obj'
			,array(
				'ajax_url' => admin_url( 'admin-ajax.php' )
				,'nonce' => $post_notif_send_nonce
			)
		);  

	}
	
	/**
	 * Handle AJAX event sent when "Send Notif" button (in meta box on Edit Post page) is pressed.
	 *
	 * @since	1.0.0
	 *	@return	null	If unable to send emails (already in process)
	 */	
	public function send_post_notif_ajax_handler() {

		// Confirm matching nonce
		check_ajax_referer( 'post_notif_send' );
   
		global $wpdb;
   
		// Tack prefix on to table names
		$post_notif_subscriber_tbl = $wpdb->prefix.'post_notif_subscriber';
		$post_notif_sub_cat_tbl = $wpdb->prefix.'post_notif_sub_cat';
		$post_notif_post_tbl = $wpdb->prefix.'post_notif_post';

		// Get current post ID
		$post_id = $_POST['post_id'];

		if ( ! ( $wpdb->get_var( "SELECT IS_FREE_LOCK('" . $wpdb->prefix.'post_notif_send_lock' . "')" ) ) ) {
   	
			// Already emailing			
			return;
		}
		else {
  
			// Set lock
			if ( ( $wpdb->get_var( "SELECT GET_LOCK('" . $wpdb->prefix.'post_notif_send_lock' . "',2)" ) ) != 1 ) {
   			  
				// Lock set failed				
				return;
			}
			else {
   			  
				// All is well
    		
				// Find categories this post is associated with
				$post_categories_arr = wp_get_post_categories( $post_id ); 
				$post_category_clause = '(';
				foreach ( $post_categories_arr as $post_category ) {   		
					$post_category_clause .= $post_category . ',';
				}
   		
				// Tack on "All" category too
				$post_category_clause .= '0)';

    			// Find subscribers to this/these ^^^ category/s
    			$subscribers_arr = $wpdb->get_results(
    				"
   					SELECT $post_notif_subscriber_tbl.id AS id
   						,email_addr 
   						,first_name
   						,authcode
   					FROM $post_notif_subscriber_tbl
   					JOIN $post_notif_sub_cat_tbl
   					ON $post_notif_subscriber_tbl.id = $post_notif_sub_cat_tbl.id
   					WHERE confirmed = 1
   						AND cat_id IN $post_category_clause
   					ORDER BY $post_notif_subscriber_tbl.id
   				"
   			);
   		   		
   			//	Compose emails
   		
   			$post_notif_options_arr = get_option( 'post_notif_settings' );
   		
   			// Replace variables in both the post notif email subject and body 
   		
   			$post_attribs = get_post( $post_id ); 
   			$post_title = $post_attribs->post_title;
   		
   			// NOTE: This is in place to minimize chance that, due to email client settings, subscribers
   			//		will be unable to see and/or click the URL links within their email
   			$post_permalink = get_permalink( $post_id );

   			$post_notif_email_subject = $post_notif_options_arr['post_notif_eml_subj'];
   			$post_notif_email_subject = str_replace( '@@blogname', get_bloginfo('name'), $post_notif_email_subject );
   			$post_notif_email_subject = str_replace( '@@posttitle', $post_title, $post_notif_email_subject );
 
   			// Tell PHP mail() to convert both double and single quotes from their respective HTML entities to their applicable characters
   			$post_notif_email_subject = html_entity_decode (  $post_notif_email_subject, ENT_QUOTES, 'UTF-8' );
   			
   			$post_notif_email_body_template = $post_notif_options_arr['post_notif_eml_body'];
   			$post_notif_email_body_template = str_replace( '@@blogname', get_bloginfo('name'), $post_notif_email_body_template );
   			$post_notif_email_body_template = str_replace( '@@posttitle', $post_title, $post_notif_email_body_template );
   			$post_notif_email_body_template = str_replace( '@@permalink', '<a href="' . $post_permalink . '">' . $post_permalink . '</a>', $post_notif_email_body_template );
   			$post_notif_email_body_template = str_replace( '@@signature', $post_notif_options_arr['@@signature'], $post_notif_email_body_template );

				// Set sender name and email address
				$headers[] = 'From: ' . $post_notif_options_arr['eml_sender_name'] 
					. ' <' . $post_notif_options_arr['eml_sender_eml_addr'] . '>';
  		
   			// Specify HTML-formatted email
   			$headers[] = 'Content-Type: text/html; charset=UTF-8';

   			//	Physically send emails
   			foreach ( $subscribers_arr as $subscriber ) {
   				
   				// Iterate through subscribers, tailoring links (change prefs, unsubscribe) to each subscriber
   				// NOTE: This is in place to minimize chance that, due to email client settings, subscribers
   				//		will be unable to see and/or click the URL links within their email
   				$prefs_url = get_site_url() . '/post_notif/manage_prefs/?email_addr=' . $subscriber->email_addr . '&authcode=' . $subscriber->authcode;
   				$unsubscribe_url = get_site_url() . '/post_notif/unsubscribe/?email_addr=' . $subscriber->email_addr . '&authcode=' . $subscriber->authcode;

   				$post_notif_email_body = $post_notif_email_body_template;
   				$post_notif_email_body = str_replace('@@firstname', ($subscriber->first_name != '[Unknown]') ? $subscriber->first_name : __( 'there', 'post-notif' ), $post_notif_email_body);
   				$post_notif_email_body = str_replace('@@prefsurl', '<a href="' . $prefs_url . '">' . $prefs_url . '</a>', $post_notif_email_body);
    				$post_notif_email_body = str_replace('@@unsubscribeurl', '<a href="' . $unsubscribe_url . '">' . $unsubscribe_url . '</a>', $post_notif_email_body);
    				
   				$mail_sent = wp_mail( $subscriber->email_addr, $post_notif_email_subject, $post_notif_email_body, $headers );   			
   			}

   			$notif_row_inserted = $wpdb->insert(
   				$post_notif_post_tbl, 
   				array(
   					'post_id' => $post_id
   					,'notif_sent_dttm' => date( "Y-m-d H:i:s" ) 
   					,'sent_by' => get_current_user_id()
   				)
   			);   		
   			
   			// Release lock
   			$wpdb->get_var( "SELECT RELEASE_LOCK('" . $wpdb->prefix.'post_notif_send_lock' . "')" );
   		}

   		if ( $notif_row_inserted ) {
   			$post_notif_sent_msg = __( 'Post notification has been sent for this post!', 'post-notif' );
   		}
   		else {
   			$post_notif_sent_msg = __( 'Post notification FAILED for this post!', 'post-notif' );
   		}
   		wp_send_json( array( 'message' => $post_notif_sent_msg ) );
   	}
   	
		// All ajax handlers should die when finished
    	wp_die(); 
   	
   }

	
	// Functions related to adding Post Notif submenu to Settings menu
	
	/**
	 * Add Post Notif menu item to Settings menu.
	 *
	 * @since	1.0.0
	 */	
	public function add_post_notif_options_page() {
	
		add_options_page(
			__( 'Post Notif Settings', 'post-notif' )
			,'Post Notif'
			,'manage_options'
			,'post-notif-slug'
			,array( $this, 'render_post_notif_options_page' )
		);
		
	}
	
	/**
	 * Register set of options configurable via Settings >> Post Notif on admin menu sidebar.
	 *
	 * @since	1.0.0
	 */	
	public function register_post_notif_settings() {
     
		register_setting(
			'post_notif_settings_group'
         ,'post_notif_settings'
      );
      
   }
   
	/**
	 * Render Post Notif options page.
	 *
	 * @since	1.0.0
	 */	
	public function render_post_notif_options_page() {

   	$post_notif_options_pg = '';
    	ob_start();
		include( plugin_dir_path( __FILE__ ) . 'views/post-notif-admin-display-options.php' );
		$post_notif_options_pg .= ob_get_clean();
		print $post_notif_options_pg;	
		
	}

	
	// Functions related to adding Post Notif top level menu to the admin menu sidebar
	
	/**
	 * Add Post Notif top level menu to the admin menu sidebar.
	 *
	 * @since	1.0.0
	 */	
	public function add_post_notif_admin_menu() {
			
		// NOTE: This will not have a selectable page associated with it (due to non-existent 'menu_only_no_selectable_item' capability)
		add_menu_page(
			'menu_only_no_selectable_item'
			,'Post Notif'
			,'menu_only_no_selectable_item'
			,'post-notif-menu'
			,null
			,''
			,3
		);

		add_submenu_page(
			'post-notif-menu'
			,__( 'Delete Subscribers', 'post-notif' )
			,__( 'Delete Subscribers', 'post-notif' )
			,'manage_options'	// ONLY admin role has this capability
			,'post-notif-del-subs'
			,array( $this, 'define_delete_subscribers_page' )
		);
		
		add_submenu_page(
			'post-notif-menu'
			,__( 'View Subscribers', 'post-notif' )
			,__( 'View Subscribers', 'post-notif' )
			,'edit_others_posts'	// admin and editor roles have this capability
			,'post-notif-view-subs'
			,array( $this, 'define_view_subscribers_page' )			
		);

		add_submenu_page(
			'post-notif-menu'
			,__( 'View Post Notifs Sent', 'post-notif' )
			,__( 'View Post Notifs Sent', 'post-notif' )
			,'edit_others_posts'	// admin and editor roles have this capability
			,'post-notif-view-posts-sent'			
			,array( $this, 'render_view_post_notifs_sent_page' )
		);

	}
		
	/**
	 * Define single and bulk actions for Delete Subscribers page.
	 *
	 * @since	1.0.0
	 */	
	public function define_delete_subscribers_page() {

		$available_actions_arr = array(
			'actionable_column_name' => 'first_name'
			,'actions' => array(
				'delete' => array(
					'label' => __( 'Delete', 'post-notif' )
					,'single_ok' => true
					,'bulk_ok' => true
				)
			)
		);
		$this->render_subscribers_page( $available_actions_arr );		
	}
	
	/**
	 * Define single and bulk actions (NONE) for View Subscribers page.
	 *
	 * @since	1.0.0
	 */	
	public function define_view_subscribers_page() {

		$available_actions_arr = null;
		$this->render_subscribers_page( $available_actions_arr );
		
	}
	
	/**
	 * Render Subscribers [View or Delete] page.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @param	array	$available_actions_arr	The available actions for the list table items.
	 */	
	private function render_subscribers_page ( $available_actions_arr ) {
	
		global $wpdb;
		
		// Tack prefix on to table names
		$post_notif_subscriber_tbl = $wpdb->prefix.'post_notif_subscriber';
		$post_notif_sub_cat_tbl = $wpdb->prefix.'post_notif_sub_cat';

		$subscribers_deleted = 0;
		$form_action = $_SERVER['REQUEST_URI'];		
		$sort_by_category = false;
		
		if ( 
				( !empty( $_REQUEST['action'] ) 
				|| !empty( $_REQUEST['action2'] ) 
			)
			&& ( 
				( $_REQUEST['action'] == 'delete' ) 
				|| ( $_REQUEST['action2'] == 'delete' ) 
			) 
		) {
		
			// Delete(s) need to be processed
			
			if ( !empty( $_REQUEST['subscriber'] ) ) {
				  
				// Delete single subscriber
				$subscribers_deleted = $this->process_single_subscriber_delete( $_REQUEST['subscriber'] );
				$form_action = remove_query_arg( array ( 'action', 'subscriber' ), $_SERVER['REQUEST_URI'] );
			}
			else {
				  			 
				// Delete multiple (selected) subscribers via bulk action
				$subscribers_deleted = $this->process_multiple_subscriber_delete( $_POST );
			}
		}
		
		// Define list table columns
		
		if ( is_array( $available_actions_arr ) ) {				  
			$columns_arr = array();
			foreach ( $available_actions_arr['actions'] as $single_action_arr ) {
				if ( $single_action_arr['bulk_ok'] == true ) {

					// There are bulk actions, add checkbox column
					$columns_arr['cb'] = '<input type="checkbox" />';	 
					break;
				}
			}
		}	 		
		$columns_arr['first_name'] = __( 'First Name', 'post-notif' );
		$columns_arr['email_addr'] = __( 'Email Address', 'post-notif' );
		$columns_arr['confirmed'] = __( 'Confirmed?', 'post-notif' );
		$columns_arr['date_subscribed'] = __( 'Date Subscribed', 'post-notif' );
		$columns_arr['categories'] = __( 'Categories', 'post-notif' );

		// NOTE: Third parameter indicates whether column data is already sorted 
		$sortable_columns_arr = array(
         'first_name' => array( 
         	'first_name'
         	,false
         )
         ,'email_addr' => array(
         	'email_addr'
         	,false
         )
         ,'confirmed' => array(
         	'confirmed'
         	,false
         )
         ,'date_subscribed' => array(
         	'date_subscribed'
         	,false
         )
         ,'categories' => array(
         	'categories'
         	,false
         )
      );    
				
		if ( !empty( $_REQUEST['orderby'] ) ) {					 
			if ( array_key_exists ( $_REQUEST['orderby'], $sortable_columns_arr ) ) {
					  
				// This IS a valid, sortable column
				if ( $_REQUEST['orderby'] != 'categories' ) {
					$orderby = $_REQUEST['orderby'];		 
				}
				else {
					$orderby = 'id';
					$sort_by_category = true;
				
					// Sort by category requires some special handling since category data is not
					//		retrieved by original query
					function usort_reorder( $a, $b ) {
						$order = ( !empty( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : 'asc';
						$result = strcmp( $a['categories'], $b['categories'] );
  					
						return ( $order === 'asc' ) ? $result : -$result;
					}
				}
			}
			else {
					  
				// This is NOT a valid, sortable column					  
				$orderby = 'first_name';
			}
		}
		else {
				  
			// No orderby specified
			$orderby = 'first_name';
		}
		if ( !empty( $_REQUEST['order'] ) ) {
			if ( $_REQUEST['order'] == 'desc' ) {
				$order = 'desc';
			}
			else {
					  
				// This is NOT a valid order				  
				$order = 'asc';
			}
		}
		else {
			
			// No order specified
			$order = 'asc';
		}
		
		// Get subscribers
		$subscribers_arr = $wpdb->get_results(
			"
   			SELECT 
   				id
   				,first_name
   				,email_addr 
   				,confirmed
   				,date_subscribed
   			FROM $post_notif_subscriber_tbl
   			ORDER BY $orderby $order
   		"
   		,ARRAY_A
   	);
   	
   	// Select categories each subscriber is subscribed to AND pass array to page
   	//		for display
 		$args = array(
			'exclude' => 1		// Omit Uncategorized
			,'orderby' => 'name'
			,'order' => 'ASC'
			,'hide_empty' => 0
		);
		$category_arr = get_categories( $args );
		$category_name_arr = array();
		foreach ( $category_arr as $category )
		{
			$category_name_arr[$category->cat_ID] = $category->name;
		}

   	$subscriber_cats_arr = array();
   	foreach ( $subscribers_arr as $sub_key => $sub_val ) {
   		$selected_cats_arr = $wpdb->get_results( 
   			"
   				SELECT cat_id 
   				FROM $post_notif_sub_cat_tbl
   				WHERE id = " . $sub_val['id']
   				. " ORDER BY cat_id
   			"
   		);
   		
   		$cat_string = '';
   		foreach ( $selected_cats_arr as $cat_key => $cat_val ) { 
   			if ($cat_val->cat_id != 0) {
   				$cat_string .= $category_name_arr[$cat_val->cat_id] . ', ';
   			}
   			else {
   				$cat_string = 'All';	  
   				break;
   			}
   		}
  	   	$cat_string = rtrim ( $cat_string, ', ' );   	
  			$subscribers_arr[$sub_key]['categories'] = $cat_string;
  			
  			// Translate binary "Subscription Confirmed?" value to words
  			$subscribers_arr[$sub_key]['confirmed'] =  ( ( $sub_val['confirmed'] == 1 ) ? __( 'Yes', 'post-notif' ) : __( 'No', 'post-notif' ) );  			
  		}	
		if ( $sort_by_category ) {
				  
			// Special sort for category
			usort( $subscribers_arr, 'usort_reorder' );
		}
   	
		// Build page	  
	
    	$class_settings_arr = array(
    		'singular' => __( 'subscriber', 'post-notif' )
    		,'plural' => __( 'subscribers', 'post-notif' )
    		,'ajax' => false
    		,'available_actions_arr' => $available_actions_arr
    	);
    
    	// Single array containing the various arrays to pass to the list table class constructor
    	$table_data_arr = array(
    		'columns_arr' => $columns_arr
    		,'hidden_columns_arr' => array()
    		,'sortable_columns_arr' => $sortable_columns_arr
    		,'rows_per_page' => 0		// NOTE: Pass 0 for single page with all data (i.e. NO pagination)
    		,'table_contents_arr' => $subscribers_arr    			  
    	);

    	$view_subs_pg_list_table = new Post_Notif_List_Table( $class_settings_arr, $table_data_arr );
		$view_subs_pg_list_table->prepare_items();		
         
      // Render page	  
		$post_notif_view_subs_pg = '';
    	ob_start();
		include( plugin_dir_path( __FILE__ ) . 'views/post-notif-admin-view-subs.php' );
		$post_notif_view_subs_pg .= ob_get_clean();
		print $post_notif_view_subs_pg;	
		
   }

	/**
	 * Perform single subscriber delete.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @param	int	$sub_id	ID of subscriber to delete.
	 *	@return	int	Number of subscribers deleted.
	 */	
	private function process_single_subscriber_delete( $sub_id ) {

		global $wpdb;
	  
		// Tack prefix on to table names
		$post_notif_subscriber_tbl = $wpdb->prefix.'post_notif_subscriber';
		$post_notif_sub_cat_tbl = $wpdb->prefix.'post_notif_sub_cat';

		// Delete subscriber's preferences rows						
		$results = $wpdb->delete( 
			$post_notif_sub_cat_tbl
			,array( 
				'id' => $sub_id
			)    			
		);
						
		// Delete subscriber row					
		$num_subs_deleted = $wpdb->delete( 
			$post_notif_subscriber_tbl
			,array( 
				'id' => $sub_id
			)    			
		);
		if ( $num_subs_deleted ) {
				  
			return $num_subs_deleted;
		}
		else {
				  
		  return 0;
		}
		
	}
			 
	/**
	 * Perform multiple subscriber delete.
	 *
	 * @since	1.0.0
	 * @access	private
	 * @param	array	$form_post	The collection of global query vars.
	 *	@return	int	Number of subscribers deleted.
	 */	
	private function process_multiple_subscriber_delete( $form_post ) {
			  			  
		global $wpdb;
	  
		// Tack prefix on to table names
		$post_notif_subscriber_tbl = $wpdb->prefix.'post_notif_subscriber';
		$post_notif_sub_cat_tbl = $wpdb->prefix.'post_notif_sub_cat';
	
		// Define checkbox prefix
		$del_subscribers_checkbox_prefix = 'chkKey_';
		$subscribers_deleted = 0;
		
		// For each selected subscriber on submitted form:
		// 	Delete their category rows from preferences table
		// 	Delete their row from subscribers table
		foreach ( $form_post as $del_subscribers_field_name => $del_subscribers_value ) {
			if ( !(strncmp($del_subscribers_field_name, $del_subscribers_checkbox_prefix, strlen( $del_subscribers_checkbox_prefix ) ) ) ) {
						  
				// This is a Subscriber checkbox
				if ( isset( $del_subscribers_field_name ) ) {
				
					// Checkbox IS selected
						
					// Delete subscriber's preferences rows						
					$results = $wpdb->delete( 
						$post_notif_sub_cat_tbl
						,array( 
							'id' => $del_subscribers_value
						)    			
					);
						
					// Delete subscriber row					
					$num_subs_deleted = $wpdb->delete( 
						$post_notif_subscriber_tbl 
						,array( 
							'id' => $del_subscribers_value
						)    			
					);
					if ( $num_subs_deleted )
					{
							  
						// OK, wise-guy, I know you're saying there should never be more than
						//		one subscriber per id!
						$subscribers_deleted += $num_subs_deleted;
					}
				}					  
			}
		}
		
		return $subscribers_deleted;
		
	}
 
	/**
	 * Render View Post Notifs Sent page.
	 *
	 * @since	1.0.0
	 */	
	public function render_view_post_notifs_sent_page() {
			  			
		global $wpdb;
		
		// Tack prefix on to table name
		$post_notif_post_tbl = $wpdb->prefix.'post_notif_post';
		
		// Define list table columns
		
    	$columns_arr = array(
    		'post_id' => __( 'Post ID', 'post-notif' )
    		,'post_title' => __( 'Post Title', 'post-notif' )
    		,'author' => __( 'Author', 'post-notif' )
    		,'notif_sent_dttm' => __( 'Sent Date/Time', 'post-notif' )
    		,'sent_by_login' => __( 'Sent By', 'post-notif' )
    	);

 		// NOTE: Third parameter indicates whether column data is already sorted 
    	$sortable_columns_arr = array(
    		'post_id' => array(
    			'post_id'
    			,false
    		)
         ,'notif_sent_dttm' => array(
         	'notif_sent_dttm'
         	,false
         )
      );
				
		if ( !empty( $_REQUEST['orderby'] ) ) {
			if ( array_key_exists ( $_REQUEST['orderby'], $sortable_columns_arr ) ) {
	
				// This IS a valid, sortable column
				$orderby = $_REQUEST['orderby'];
			}
			else {
  				// This is NOT a valid, sortable column					  
  				$orderby = 'notif_sent_dttm';
  			}
		}
		else {

			// No orderby specified				  
			$orderby = 'notif_sent_dttm';		  
		}
		if ( !empty( $_REQUEST['order'] ) ) {
			if ( $_REQUEST['order'] == 'asc' ) {
				$order = 'asc';
			}
			else {
					  
				// This is NOT a valid order				  
				$order = 'desc';
			}
		}
		else {
			
			// No order specified
			$order = 'desc';
		} 		
		
		// Get post notifs sent
		$post_notifs_sent_arr = $wpdb->get_results(
			"
   			SELECT post_id
   				,notif_sent_dttm 
   				,sent_by
   				,user_login AS sent_by_login
   			FROM $post_notif_post_tbl
   			JOIN wp_users
   				ON ($post_notif_post_tbl.sent_by = wp_users.ID)
   			ORDER BY $orderby $order
   		"
   		,ARRAY_A
   	);
   	
   	// Get post titles, authors' names, and post notif senders' names
   	foreach ( $post_notifs_sent_arr as $notif_key => $notif_val ) {
   		$post_object = get_post( $notif_val['post_id'] );
   		$post_notifs_sent_arr[$notif_key]['post_title'] = $post_object->post_title;
   		
    		$post_author_data = get_userdata( $post_object->post_author );
   		$post_notifs_sent_arr[$notif_key]['author'] = $post_author_data->user_login;
    	}
  	
		// Build page	  
    
    	$class_settings_arr = array(
    		'singular' => __( 'notification', 'post-notif' )
    		,'plural' => __( 'notifications', 'post-notif' )
			,'ajax' => false
    		// NOTE: Page is read-only, so no available actions
    		,'available_actions_arr' => null
    	);
    
     	// Single array containing the various arrays to pass to the list table class constructor
    	$table_data_arr = array(
    		'columns_arr' => $columns_arr
    		,'hidden_columns_arr' => array()
    		,'sortable_columns_arr' => $sortable_columns_arr
    		,'rows_per_page' => 0		// NOTE: Pass 0 for single page with all data (i.e. NO pagination)
    		,'table_contents_arr' => $post_notifs_sent_arr    			  
    	);
    	
    	$view_post_notif_list_table = new Post_Notif_List_Table( $class_settings_arr, $table_data_arr );
		$view_post_notif_list_table->prepare_items();		
           	    	
      // Render page	  
		$post_notif_view_posts_pg = '';
    	ob_start();
		include( plugin_dir_path( __FILE__ ) . 'views/post-notif-admin-view-post-notifs-sent.php' );
		$post_notif_view_posts_pg .= ob_get_clean();
		print $post_notif_view_posts_pg;	
			  
	}			
	
}

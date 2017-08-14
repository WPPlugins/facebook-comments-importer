<?php
require_once('facebook.php');
require_once('fbci-logger.php');
require_once('fbci-utils.php');

/**
* Manage the import of Facebook comments to Wordpress.
*
* see Facebook Graphe Api documentation for more details
* at http://developers.facebook.com/docs/api
*
* @package WordPress
* @since ??
*/

class FacebookCommentImporter {

	protected $page_id = '' ;
	protected $facebook = '' ;
	protected $in_test = false ;
	protected $test_result = '' ;
	
	/**
	 * Constructor
	 *
	 * @package WordPress
	 * @since 1.0
	 *
	 * @param    string    $page_id    a Facebook page/profile ID
	 */
	public function __construct($page_id) {
		$this->page_id = trim($page_id) ;
		$this->facebook = new FBCI_Facebook(array(
		  'appId'  => '106793439375373',
		  'secret' => 'dbc0fe0aa03a505300d2569b7a004663',
		  'cookie' => false,
		));
	}
	
	/**
	 * Make a quick test on the Fan Page or Profile
	 * 
	 * @package WordPress
	 * @since 1.0
	 *
	 * @return   string		A description of the error, or a description starting with 'OK' if all is right.
	 */
	public function fan_page_test(){	
		try{
			$this->in_test = true ;
			$wall = $this->get_wall(10, false) ; 
			$this->in_test = false ;
			if(count($wall) < 1) {
				throw new Exception(__('Cannot find a post linked to this blog on the last 10 posts of the Facebook wall. Check that at least one of the last 10 posts on your wall links to a post of your blog.', 'facebook-comments-importer'));
			} else {
				foreach($wall as $key => $item){
					return __('OK. Your Facebook page seems to be linked to your blog.', 'facebook-comments-importer') . '<br/><br/>' . $this->test_result ;
				}
			}
		} catch(Exception $e) {
			$this->in_test = false ;
			return 'Warning: ' . $e->getMessage() . '<br/><br/>' . $this->test_result ; 
		}
	}
	
	/**
	 * Get a Facebook Fan Page or Profile
	 * 
	 * 
	 *
	 * @package WordPress
	 * @since 1.0
	 *
	 * @return   array                the Fan Page, see FB Graphe API
	 */
	public function get_fan_page(){	
		try{  
			$fan_page = $this->facebook->api('/' . $this->page_id) ;  
		} catch(Exception $e) {
			throw new Exception(__('Error while getting this fan page : ', 'facebook-comments-importer') . $e->getMessage()); 
		}
		
		return $fan_page ;
	}

	/**
	 * Get the wall of the Facebook Fan Page or Profile
	 * 
	 * Return the list of posts, with comments, etc.
	 * Return only the posts that are linked to a post of the blog.
	 * The link is made between the FB post "link" attribute and the
	 * wordpress permalink.
	 *
	 * @package WordPress
	 * @since 1.0
	 *
	 * @param    int    $count    Number of items (posts) to fetch from the wall (Default : 30)
	 * @param    bool    $only_commented    If true, only returns items that have comments (Default : false)
	 * @return   array                the wall, see FB Graphe API
	 */
	public function get_wall($count = 30, $only_commented = false){	
		try{
			$q = '/' . $this->page_id . '/feed?limit=' . $count ;
			$fan_wall = $this->facebook->api($q) ;
			$fan_wall = $fan_wall["data"] ;

			if($this->in_test) {
				$this->test_result = '<table class="wp-list-table widefat fixed" cellspacing="0"><thead><tr><th>' . __('Facebook Post', 'facebook-comments-importer') 
									. '</th><th>' . __('Link', 'facebook-comments-importer')
									. '</th><th>' . __('Linked to Blog ?', 'facebook-comments-importer')
									. '</th><th>' . __('Facebook Comments', 'facebook-comments-importer')
									. '</th><th>' . __('Imported to Blog', 'facebook-comments-importer') . '</th></thead></tr>';
			}

			foreach($fan_wall as $key => $item){
				$post_id = (int) url_to_postid(get_final_url($item['link'])) ;
				if($this->in_test) {
					if(isset($item['name'])){
						$this->test_result .= "<tr><td><strong>" . $item['name'] . "</strong></td>" ;
					}
					else {
						$this->test_result .= "<tr><td><strong>" . substr($item['message'], 0, 40) . "...</strong></td>" ;
					}
					
					if(isset($item['link'])) {
						$this->test_result .= "<td><a href=\"" . $item['link'] . "\">" . $item['link'] . "</a></td>" ;
					} else {
						$this->test_result .= "<td style=\"color:red\">No link</td>" ;
					}
					
					if($post_id == 0){
						$this->test_result .= "<td style=\"color:red\">" . __('No', 'facebook-comments-importer') . "</td>" ;
					} else {
						$this->test_result .= "<td style=\"color:green\">" . __('Yes', 'facebook-comments-importer') . "</td>" ;
					}
					if(!isset($item["comments"]) || sizeof($item["comments"]["data"]) == 0) {
						$this->test_result .= "<td>" . __('No comment', 'facebook-comments-importer') . "</td>" ;
					} else {
						$this->test_result .= "<td>" . sizeof($item["comments"]["data"]) . " " . __('comments', 'facebook-comments-importer') . "</td>" ;
					}
					$nb_imported_comments = $this->get_imported_comment_number($post_id);
					if(!isset($item['link']) || ($post_id == 0)){
						$this->test_result .= "<td style=\"color:red\">" . __('Impossible', 'facebook-comments-importer') . "</td>" ;
					} elseif($nb_imported_comments == 0 && (!isset($item["comments"]) || !isset($item["comments"]["data"]))){
						$this->test_result .= "<td>-</td>" ;
					} elseif($nb_imported_comments == 0){
						$this->test_result .= "<td>" . __('Not yet', 'facebook-comments-importer') . "</td>" ;
					} else {
						$this->test_result .= "<td style=\"color:green\">" . $nb_imported_comments . " " . __('comments', 'facebook-comments-importer') . "</td>" ;
					}
					
					$this->test_result .= "</tr>" ;
				}
				// remove the no-commented posts
				if($only_commented && (!isset($item["comments"]) || sizeof($item["comments"]["data"]) == 0)){
					unset($fan_wall[$key]);
					continue ;
				}
				// We only keep the items that are linked to a blog post
				if($post_id == 0){
					unset($fan_wall[$key]);
				}
			}
			
			if($this->in_test) {
				$this->test_result .= "</table>" ;
			}
			
		} catch(Exception $e) {
			throw new Exception(__('Error while getting this wall : ', 'facebook-comments-importer') . $e->getMessage()); 
		}
		
		return $fan_wall ;
	}
	
	/**
	 * Get a Facebook user
	 * 
	 * Return a set of informations of a Facebook user (name, picture, etc.)
	 *
	 * @package WordPress
	 * @since 1.0
	 *
	 * @param    string    $user_id    the user ID (see Facebook API guide)
	 * @return   array                the user, see FB Graphe API for more info.
	 */
	public function get_user($user_id){	
		try{
			$user = $this->facebook->api('/' . $user_id) ;  
		} catch(Exception $e) { 
			throw new Exception(__('Error while getting this user : ', 'facebook-comments-importer') . $e->getMessage()); 
		}
		return $user ;
	}
	
	/**
	 * Get an array of the wall's comments.
	 * 
	 * Return an array of specific data for a comment (Not the Facebook array)
	 * Each item of the array have the following fields :
	 * - author_name : 		the username of the commenter
	 * - author_link : 		the link to the commenter FB profile 
	 * - author_picture : 	the FB profile picture of the commenter
	 * - message : 			the comment's message
	 * - created_time : 	the comment's date. The format is as '2010-08-02T12:23:29+0000'
	 *                  	You can convert it with date_create($comment["created_time"])
	 * - post_link :		the permalink to the wordpress post
	 * - post_name : 		the title of the FB item, wich should be the title of the wordpress post
	 *
	 * @package WordPress
	 * @since 1.0
	 *
	 * @param    array    $wall    an array provided by the get_wall function.
	 * @return   array             an array, as explained before.
	 */
	public function get_comments($wall){
		$comments = array() ;
		try{
			foreach($wall as $item) {  
				$comments_stream = $this->facebook->api('/' . $item["id"] . '/comments') ; 			
				while(isset($comments_stream["data"])){
					foreach($comments_stream["data"] as $comment){
						$user = $this->get_user($comment["from"]["id"]) ;
						
						// Generate the author string
						$author_str  = get_option('fbci_author_str', '%name% via Facebook') ;
						$tags = array('%name%', '%first_name%', '%last_name%');
						$replacements = array($user["name"], $user["first_name"], $user["last_name"]);

						$author_str = str_replace($tags, $replacements, $author_str);
						
						$comments[] = array(
							"author_name" => $user["name"],
							"author_str" => $author_str,
							"author_link" => $user["link"],
							"author_picture" => "http://graph.facebook.com/". $user["id"] ."/picture",
							"message" => $comment["message"],
							"created_time" => $comment["created_time"],
							"post_link" => $item["link"],
							"post_name" => $item["name"],
							"comment_id" => $comment["id"]
						);
					}
					$next_page = $comments_stream;
					$comments_stream = "" ;
					$next_page = $next_page["paging"]["next"] ;
					$next_page = substr($next_page, strpos($next_page, "graph.facebook.com") + 18) ; // 18 = length of "graph.facebook.com"
					if($next_page !== FALSE && $next_page != "") {
						$comments_stream = $this->facebook->api($next_page) ; // Si il y a une page suivante, on boucle
					}
				}
			}		
			return $comments ;
		} catch(Exception $e) {
			throw new Exception(__('Error while getting the comments : ', 'facebook-comments-importer') . $e->getMessage()); 
		}
	}
	
	
	/**
	 * Insert a FB comment in the wordpress database.
	 * 
	 * Get a comment form the get_comments function and insert it into
	 * the database. Not that this function doesn't use wp_new_comment
	 * but wp_insert_comment. This is because wp_new_comment does not allow
	 * to change the date of the comment, and we want to set the real date
	 * of the facebook comment.
	 * However, this function calls wp_filter_comment and wp_notify_postauthor
	 * in order to simulate the wp_new_comment behavior.
	 * Also inserts a record in the commentmeta table to link the WP comment 
	 * to the FB one.
	 *
	 * @package WordPress
	 * @since 2.9.0
	 *
	 * @param    array    $wall    	an array (an item of the array provided by 
									the get_comments function.)
	 */
	public function import_comment($comment){
		if(!($this->is_comment_imported($comment["comment_id"]))){
			//build the array to pass to wp_new_comment
			$post_id = (int) url_to_postid(get_final_url($comment['post_link'])) ;

			if($post_id > 0) {
				$commentdata = apply_filters('preprocess_comment', $commentdata);
				$commentdata = array(
					'comment_post_ID' => $post_id,
					'comment_author' => $comment["author_str"],
					'comment_author_email' => '',
					'comment_author_url' => $comment["author_link"],
					'comment_content' => $comment["message"],
					'comment_agent' => 'facebook-comment-importer plugin',
					'comment_date' => get_date_from_gmt(date_format(date_create($comment["created_time"]), 'Y-m-d H:i:s')),
					'comment_parent' => 0,
					'comment_approved' => (get_option('comment_moderation', 0) == 1) ? 0 : 1,
					'comment_type' => ''
				);

				$commentdata = wp_filter_comment($commentdata);
				$comment_id = wp_insert_comment($commentdata);
				do_action('comment_post', $comment_id, $commentdata['comment_approved']);
				wp_notify_postauthor($comment_id, $commentdata['comment_type']);
			
				// add a meta to recognize the comment with the facebook comment id.
				add_comment_meta($comment_id, 'fbci_comment_id', $comment["comment_id"]) ;
			}
		}
	}
	
	/**
	 * Import all the comments
	 *
	 * Do all the job : checks all the comments from facebook, then inserts
	 * them into the wordpress database.
	 * uses get_comment, get_wall and import_comment
	 *
	 * Note that this only checks the 30 last comments from FB to avoid 
	 * performance problems.
	 *
	 * @package WordPress
	 * @since 2.9
	 */
	public function import_comments() {
		$comments = $this->get_comments($this->get_wall(30, true));
		foreach($comments as $comment){
			$this->import_comment($comment) ;
		}
	}
	
	/**
	 * Check if a Facebook comment is already imported in Wordpress
	 *
	 * The check uses the commentmeta table where the FB comments ids 
	 * are stored.
	 *
	 * @package WordPress
	 * @since 2.9.0
	 *
	 * @param    string    $comment_id    	a Facebook comment ID (see FB API doc)
     * @return   bool    					true if already imported.
	 */
	public function is_comment_imported($comment_id) {
		global $wpdb ;
		$nb_comments = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->commentmeta WHERE meta_key = 'fbci_comment_id' and meta_value = %s", $comment_id));
		return ($nb_comments > 0) ;
	}
	
	/**
	 * Return the number of Facebook comments imported in a blog post
	 *
	 * The check uses the commentmeta table where the FB comments ids 
	 * are stored.
	 *
	 * @package WordPress
	 * @since 2.9.0
	 *
	 * @param    string    $comment_id    	a Facebook comment ID (see FB API doc)
     * @return   bool    					true if already imported.
	 */
	public function get_imported_comment_number($post_id) {
		global $wpdb ;
		$nb_comments = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->commentmeta cm
													  JOIN $wpdb->comments c ON cm.comment_id = c.comment_ID 
													  WHERE cm.meta_key = 'fbci_comment_id' 
													  AND c.comment_post_ID = %d", $post_id));
		return $nb_comments ;
	}
}
?>
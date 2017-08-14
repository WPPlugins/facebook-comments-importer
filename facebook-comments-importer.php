<?php
/*
Plugin Name: Facebook Comments Importer
Plugin URI: 
Description: This plugin imports the comments posted on your Facebook fan page to your blog.
Version: 1.2.2
Author: Neoseifer22
Author URI: 
License: GPL2

Copyright 2010  Neoseifer  (email : neoseifer_at_free_dot_fr)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

Icons from http://www.famfamfam.com/lab/icons/silk/

*/
load_plugin_textdomain('facebook-comments-importer', false, dirname(plugin_basename(__FILE__)));

require_once('fbci.class.php');
require_once('fbci-logger.php');

register_activation_hook(__FILE__, 'fbci_activation');
register_deactivation_hook(__FILE__, 'fbci_deactivation');

add_action('fbci_cron_import', 'fbci_import_all_comments');
add_filter('get_avatar', 'fbci_get_avatar', 10, 5);
add_filter('cron_schedules', 'fbci_schedules');
add_filter('plugin_action_links', 'fbci_plugin_action_links', 10, 2);

function fbci_activation() {
	// Scheduler Management
	$schedule = get_option('fbci_scheduler', 'hourly') ;
	wp_clear_scheduled_hook('fbci_cron_import');
	if($schedule != 'never') {
		//Calculate the next time
		wp_schedule_event(fbci_next_scheduled_time($schedule), $schedule, 'fbci_cron_import');
	}
}

function fbci_deactivation() {
	wp_clear_scheduled_hook('fbci_cron_import');
	delete_option('fbci_scheduler');
}

function fbci_schedules() {
    return array(
		'fifteenminutes' => array(
			'interval' => 900, /* 60 seconds * 15 minutes */
			'display' => 'Fifteen minutes'
		),
		'halfhour' => array(
			'interval' => 1800, /* 60 seconds * 30 minutes */
			'display' => 'Half hour'
		)
	);
}

function fbci_plugin_action_links($links, $file) {
    if ($file == plugin_basename(__FILE__)) {
        $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=facebook-comments-importer/facebook-comments-importer.php">' . __('Settings', 'facebook-comments-importer') . '</a>';
        array_unshift($links, $settings_link);
    }
    return $links;
}

/**
 * Imports all the comments from Facebook to the database
 * 
 * This method do all the job silently. In case of error,
 * it logs in the log file.
 * In order to avoid to import comments twice, this method 
 * cannot be launch concurrently. It uses a lock file to 
 * avoid this.
 *
 * @package WordPress
 * @since 2.9.0
 */
function fbci_import_all_comments() {
	try{
		if(!fbci_is_locked(5 * 60)){	// 5 minutes
			$fbci = new FacebookCommentImporter(get_option('fbci_page_id')) ;
			$fbci->import_comments();
		}
		
	} catch (Exception $e) {
		fbci_log($e->getMessage());
		throw $e ;
	} 
}

/**
* Check if lock option is present and not obsolete.
* If yes, return true.
* If not present or obsolete, write a new lock 
* and return false (so it becomes locked)
*
* @param	int		lifetime		Seconds for a lock to become obsolete
*/
function fbci_is_locked($lifetime){
	$locktime = get_option('fbci_lock', '0') ;
	$now = time();
	
	if($now - $locktime > $lifetime) {
		update_option('fbci_lock', $now);
		return false;
	}
	return true;
}

/**
 * Returns the next scheduled time.
 * @author : Neoseifer22
 * 
 * @package WordPress
 * @since 2.5
 *
 * @param    string    $schedule	    the string that represents the schedule used in wp_schedule_event 
 * @return   string             		The next time, formated as a unix timestamp by DateTime->format('U');
 */
function fbci_next_scheduled_time($schedule){
	$now = new Datetime();
	switch ($schedule) {
		case "fifteenminutes":
			$dt = '+' . (15 - ($now->format('i') % 15)) . ' minutes'; // Time Interval between Now and the next quarter
			$now->modify($dt);
			break;
		case "halfhour":
			$dt = '+' . (30 - ($now->format('i') % 30)) . ' minutes'; // Time Interval between Now and the next half
			$now->modify($dt) ;
			break;
		case "hourly":
			$dt = '+' . (60 - ($now->format('i'))) . ' minutes'; // Time Interval between Now and the next half
			$now->modify($dt);
			break;
		case "twicedaily":
			if(($now->format('H') >= 3) && ($now->format('H') < 15)) {
				$now->setTime(15, 0);
			}
			else {
				$dt = '+1 day' ;
				$now->modify($dt);
				$now->setTime(3, 0);
			}
			break;
		case "daily":
			if($now->format('H') < 3) {
				$now->setTime(3, 0) ;
			}
			else {
				$dt = '+1 day' ;
				$now->modify($dt);
				$now->setTime(3, 0) ;
			}
			break;
	}
	$now->setTime($now->format('H'), $now->format('i'), 0);
	return $now->format('U');
	
}

/**
 * Returns the Facebook avatar. Used by the get_avatar filter.
 * @author : Justin Silver
 * 
 * @package WordPress
 * @since 2.5
 *
 * @param	 object	   $avatar			The default avatar.
 * @param    string    $id_or_email     Author’s User ID (an integer or string), 
 *										an E-mail Address (a string) or the 
 *										comment object from the comment loop
 *										provided by get_avatar.
 * @param	 string 	$size			Size of avatar to return. provided by get_avatar.
 * @return   string             		The avatar img tag if possible
 */
function fbci_get_avatar($avatar, $id_or_email, $size='50') {
	if (!is_object($id_or_email)) { 
		$id_or_email = get_comment($id_or_email);
    }

    if (is_object($id_or_email)) {
        if ($id_or_email->comment_agent=='facebook-comment-importer plugin'){
			$alt = '';
            $fb_url = $id_or_email->comment_author_url;
            $fb_array = explode("/", $fb_url);
            $fb_id = $fb_array[count($fb_array)-1];
            if (strlen($fb_id)>1) {
                $img = "http://graph.facebook.com/".$fb_id."/picture";
                return "<img alt='{$alt}' src='{$img}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
            }
        }
    }
    return $avatar;
}

/**
* Administration Menu block
*/
add_action('admin_menu', 'fbci_create_menu');

function fbci_create_menu() {
	//create new top-level menu
	add_submenu_page('options-general.php','Facebook Comments Importer Settings', 'Facebook Comments Importer', 'administrator', __FILE__, 'fbci_settings_page');
	
	//call register settings function
	add_action( 'admin_init', 'fbci_register_mysettings' );
}


function fbci_register_mysettings() {
	register_setting('fbci-settings-group', 'fbci_page_id');
	register_setting('fbci-settings-group', 'fbci_author_str');
	register_setting('fbci-settings-group', 'fbci_scheduler');
}


function fbci_settings_page() {
	if($_POST['fbci_import']=='true'){
		try{
			fbci_import_all_comments();
			echo '<div class="update-nag">';
			_e('Importation succesfull.', 'facebook-comments-importer');
			echo '</div>';
		} catch (Exception $e) {
			echo '<div class="update-nag" style="color:red">';
			_e('Error while importing comments from Facebook: ', 'facebook-comments-importer') ; echo $e->getMessage();
			echo '</div>';
		} 
	}
	
	if(($_GET['updated']=='true') || ($_GET['settings-updated']=='true')){
		// Scheduler Management
		$schedule = get_option('fbci_scheduler', 'hourly') ;
		wp_clear_scheduled_hook('fbci_cron_import');
		if($schedule != 'never') {
			//Calculate the next time
			wp_schedule_event(fbci_next_scheduled_time($schedule), $schedule, 'fbci_cron_import');
		}
	}


?>
<div class="wrap">
<h2>Facebook Comments Importer</h2>

<form method="post" action="options.php">
    <?php settings_fields( 'fbci-settings-group' ); ?>
    <table class="form-table">
        <tr valign="top">
        <th scope="row"><?php _e('Facebook Fan Page ID :', 'facebook-comments-importer') ; ?></th>
        <td>
			<input type="text" name="fbci_page_id" value="<?php echo get_option('fbci_page_id'); ?>" size="50" />
			<div class="description">
			<?php _e('For exemple, if your page url is <i>www.facebook.com/pages/BlogName/<b>123456</b></i>, your Fan Page ID is <b>123456</b>. if your page url is only <i>www.facebook.com/pages/<b>BlogName</b></i>, your Fan Page ID is <b>BlogName</b>.', 'facebook-comments-importer') ; ?>
			</div>
		</td>
        </tr>
		<tr valign="top">
        <th scope="row"><?php _e('Comment author text :', 'facebook-comments-importer') ; ?></th>
        <td>
			<input type="text" name="fbci_author_str" value="<?php echo get_option('fbci_author_str', '%name% via Facebook'); ?>" size="50" />
			<div class="description">
			<?php _e('You can use the following tags : %name%, %first_name%, %last_name%', 'facebook-comments-importer') ; ?>
			</div>
		</td>
        </tr>
		<tr valign="top">
        <th scope="row"><?php _e('Automaticly import new comments :', 'facebook-comments-importer') ; ?></th>
        <td>
			<select name="fbci_scheduler">
				<option value="never" <?php if(get_option('fbci_scheduler', 'hourly') == 'never') echo 'selected="selected"'; ?>><?php _e('Never', 'facebook-comments-importer') ; ?></option>
				<option value="fifteenminutes" <?php if(get_option('fbci_scheduler', 'hourly') == 'fifteenminutes') echo 'selected="selected"'; ?>><?php _e('Every 15 minutes', 'facebook-comments-importer') ; ?></option>
				<option value="halfhour" <?php if(get_option('fbci_scheduler', 'hourly') == 'halfhour') echo 'selected="selected"'; ?>><?php _e('Every 30 minutes', 'facebook-comments-importer') ; ?></option>
				<option value="hourly" <?php if(get_option('fbci_scheduler', 'hourly') == 'hourly') echo 'selected="selected"'; ?>><?php _e('Hourly (Recommended)', 'facebook-comments-importer') ; ?></option>
				<option value="twicedaily" <?php if(get_option('fbci_scheduler', 'hourly') == 'twicedaily') echo 'selected="selected"'; ?>><?php _e('Twice daily', 'facebook-comments-importer') ; ?></option>
				<option value="daily" <?php if(get_option('fbci_scheduler', 'hourly') == 'daily') echo 'selected="selected"'; ?>><?php _e('Daily', 'facebook-comments-importer') ; ?></option>
			</select>
			<div class="description">
			<?php _e('Warning : Importing comments too often can slow your visitors! Use "Every 15 minutes" only if you have a lot of comments.', 'facebook-comments-importer') ; ?>
			</div>
			<br/>
		</td>
        </tr>
		<tr valign="top">
		<th><?php _e('Next scheduled import:', 'facebook-comments-importer') ; ?></th>
		<td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), wp_next_scheduled('fbci_cron_import') + get_option('gmt_offset')*3600); ?></td>
		</tr>
    </table>
	
	<p class="submit" style="float: left;margin-right:10px">
    <input type="submit" class="button-primary" value="<?php _e('Save', 'facebook-comments-importer'); ?>" />
    </p>
</form>
<form method="post" action="#">
	<input type="hidden" name="fbci_import" value="true" />
	<p class="submit">
	<input type="submit" class="button-secondary" value="<?php _e('Import Now', 'facebook-comments-importer'); ?>" />
	</p>
</form>
	
	<?php
	
	if(get_option('fbci_page_id') != '') {
		try {
			$fbci = new FacebookCommentImporter(get_option('fbci_page_id')) ;
			$test = $fbci->fan_page_test() ;
		} catch (Exception $e) {
			$test = __('Error: Cannot make the test. ', 'facebook-comments-importer') . $e->getMessage() ;
		}
	?>
	
		<h3 class="title"><?php _e('Importation state', 'facebook-comments-importer'); ?></h3>
		<div>
			<?php 
				$dir = WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)) ;
				echo '<img src="' . $dir . 'images/' ;
				if(substr ($test, 0, 2) == 'OK') {
					echo 'accept.png' ;
				} else {
					echo 'error.png' ;
				}
				echo '" style="vertical-align:middle; margin-right:5px;" />' . $test; 
			?>
		</div>
	
	<?php
	}
	?>
</div>
<?php
}
?>
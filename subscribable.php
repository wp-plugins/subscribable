<?php
/*
Plugin Name: Subscribable
Plugin URI: http://subscribable.com
Description: This plugin enables subscribable.com users to subscribe to your blog and receive immediate notification each time you publish a new post. It adds a menu item to the WordPress administration dashboard for monitoring, displaying, and managing subscriber activity.
Version: 0.1
Author: Matthew Keller
Author URI: http://blog.subscribable.com
*/

add_action('activate_subscribable/subscribable.php', 'subscribable_install_database_tables');
function subscribable_install_database_tables() {

	global $wpdb;
	$subscribable_version = '0.1';

	if (null == get_option('subscribable_version')) {

		$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
		if ($conn === false) {
			trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (mysql_query('SET NAMES utf8', $conn) === false) {
			mysql_close($conn);
			trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (mysql_select_db(DB_NAME, $conn) === false) {
			mysql_close($conn);
			trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (mysql_query('BEGIN', $conn) === false) {
			mysql_close($conn);
			trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}

		if (($rsl = mysql_query('CREATE TABLE ' . $wpdb->prefix . 'subscribable_blogs (lastUpdateNbr INTEGER, lastPostTime DATETIME, lastUpdater VARCHAR(255), lastUpdaterRealName VARCHAR(100) CHARSET utf8, attempts SMALLINT) ENGINE=InnoDB', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (($rsl = mysql_query('INSERT INTO ' . $wpdb->prefix . 'subscribable_blogs VALUES (-1, NOW(), null, null, 10)', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (($rsl = mysql_query('CREATE TABLE ' . $wpdb->prefix . 'subscribable_subscribers (url VARCHAR(2048), securityCode CHAR(32), username VARCHAR(255), realName VARCHAR(100) CHARSET utf8, ip INTEGER UNSIGNED, updateAuthCode BIGINT, lastVisit DATETIME, alreadySeen INTEGER, alreadyUpdated INTEGER, INDEX(url(64), securityCode), INDEX(url(64), username)) ENGINE=InnoDB', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}

		if (mysql_query('COMMIT', $conn) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (mysql_close($conn) === false) {
			trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}

		add_option('subscribable_version', $subscribable_version);
		add_option('subscribable_display_subscribers', 'true');

		global $wp_registered_sidebars;
		if (count($wp_registered_sidebars) >= 1) {
			$sidebars_widgets = wp_get_sidebars_widgets();
			if (empty($sidebars_widgets['sidebar-1'])) {
				$sidebars_widgets['sidebar-1'][] = 'pages';
				$sidebars_widgets['sidebar-1'][] = 'archives';
				$sidebars_widgets['sidebar-1'][] = 'categories';
				$sidebars_widgets['sidebar-1'][] = 'links';
				$sidebars_widgets['sidebar-1'][] = 'meta';
			}
			$sidebars_widgets['sidebar-1'][] = 'subscribable';
			wp_set_sidebars_widgets($sidebars_widgets);
		}
	}
	elseif (get_option('subscribable_version') != $subscribable_version) {

		// update database tables
		update_option('subscribable_version', $subscribable_version);
	}
}

add_action('init', 'subscribable_set_cookie');
function subscribable_set_cookie() {

	if (array_key_exists('securityCode', $_GET) && strlen($_GET['securityCode']) == 32) {
		setcookie('securityCode', $_GET['securityCode']);
		header('Location: ' . get_bloginfo('url'));
		exit();
	}
}

add_action('admin_menu', 'subscribable_add_admin_page');
function subscribable_add_admin_page() {
	add_menu_page('Subscribable', 'Subscribable', 'administrator', __FILE__, 'subscribable_show_admin_page'); 
}
function subscribable_show_admin_page() {

	global $wpdb;

	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		if (array_key_exists('displaySubscribers', $_POST))
			update_option('subscribable_display_subscribers', 'true');
		else
			update_option('subscribable_display_subscribers', 'false');
	}

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}

	if (($rsl = mysql_query('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'subscribable_subscribers', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	$row = mysql_fetch_row($rsl);
	$nbrSubscribers = $row[0];

	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return;
	}

	echo '<div class="wrap">';
	echo 	'<h2>Options</h2>';
	echo 	'<form action="' . htmlspecialchars(str_replace('%7E', '~', $_SERVER['REQUEST_URI']), ENT_QUOTES) . '" method="post">';
	echo 		'<p>Options let you customize how subscribers interact with your blog.</p>';
//	echo 		'<p class="submit"><input type="submit" value="Update Options &raquo;"/></p>';
	echo 		'<p style="text-align:center"><input type="checkbox" name="displaySubscribers"' . (get_option('subscribable_display_subscribers') === 'true' ? ' checked' : '') . '/> Display subscribers on blog sidebar</p>';
	echo 		'<p class="submit"><input type="submit" value="Update Options &raquo;"/></p>';
	echo 	'</form>';
	echo 	'<h2>Subscribers (' . $nbrSubscribers . ')</h2>';
	echo 	'<p>Subscribers receive immediate notification each time you publish a new post.</p>';
	echo 	'<table class="widefat">';
	echo 		'<thead><tr><th scope="col"></th><th scope="col">Username</th><th scope="col">Real Name</th><th scope="col">Last Visit</th></tr></thead>';
	echo 		'<tbody id="the-list">';

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}

	if (($rsl = mysql_query('SELECT username,realName,TIMESTAMPDIFF(YEAR,lastVisit,NOW()),TIMESTAMPDIFF(MONTH,lastVisit,NOW()),TIMESTAMPDIFF(WEEK,lastVisit,NOW()),TIMESTAMPDIFF(DAY,lastVisit,NOW()),TIMESTAMPDIFF(HOUR,lastVisit,NOW()),TIMESTAMPDIFF(MINUTE,lastVisit,NOW()),TIMESTAMPDIFF(SECOND,lastVisit,NOW()) FROM ' . $wpdb->prefix . 'subscribable_subscribers ORDER BY lastVisit DESC', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	while(($row = mysql_fetch_row($rsl)) !== false) {
		$i = null;
		for ($i = 2; $i < 9; $i++)
			if ($row[$i] > 1)
				break;
		$lastVisitString = null;
		switch($i) {
			case 2:
				$lastVisitString = $row[$i] . ' years ago';
				break;
			case 3:
				$lastVisitString = $row[$i] . ' months ago';
				break;
			case 4:
				$lastVisitString = $row[$i] . ' weeks ago';
				break;
			case 5:
				$lastVisitString = $row[$i] . ' days ago';
				break;
			case 6:
				$lastVisitString = $row[$i] . ' hours ago';
				break;
			case 7:
				$lastVisitString = $row[$i] . ' minutes ago';
				break;
			default:
				$lastVisitString = $row[$i] . ' seconds ago';
				break;
		}
		echo '<tr class="alternate"><td><div style="text-align:center"><a href="http://' . $row[0] . '/profile/image" target="_blank"><img src="http://' . $row[0] . '/profile/thumbnail"/></a></div></td><td>' . $row[0] . '</td><td>' . htmlspecialchars($row[1], ENT_QUOTES, 'utf-8') . '</td><td>' . $lastVisitString . '</td></tr>';
	}

	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		echo '</tbody></table></div>';
		return;
	}

	echo '</tbody></table></div>';
}

add_action('plugins_loaded', 'subscribable_register_widget');
function subscribable_register_widget() {
	register_sidebar_widget('Subscribable', 'subscribable_show_widget');
}
function subscribable_show_widget() {

	global $wpdb;

	echo '<li><h2>Subscribable</h2><ul>';

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}

	$viewerIsSubscribed = false;
	$viewer = 'rr.subscribable.com';
	if (array_key_exists('securityCode', $_COOKIE) && strlen($_COOKIE['securityCode']) == 32) {

		if (($rsl = mysql_query('SELECT username FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE securityCode=\'' . mysql_real_escape_string($_COOKIE['securityCode'], $conn) . '\'', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		}
		if (($row = mysql_fetch_row($rsl)) !== false) {
			$viewerIsSubscribed = true;
			$viewer = $row[0];
			if (mysql_free_result($rsl) === false) {
				mysql_query('ROLLBACK', $conn);
				mysql_close($conn);
				trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			}
			if (($rsl = mysql_query('UPDATE ' . $wpdb->prefix . 'subscribable_subscribers SET lastVisit=NOW() WHERE securityCode=\'' . mysql_real_escape_string($_COOKIE['securityCode'], $conn) . '\'', $conn)) === false) {
				mysql_query('ROLLBACK', $conn);
				mysql_close($conn);
				trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			}
		}
		else {
			if (mysql_free_result($rsl) === false) {
				mysql_query('ROLLBACK', $conn);
				mysql_close($conn);
				trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			}
		}
	}
	if (get_option('subscribable_display_subscribers') === 'true') {

		$usernames = Array();
		if (($rsl = mysql_query('SELECT securityCode,username,realName FROM ' . $wpdb->prefix . 'subscribable_subscribers ORDER BY RAND() LIMIT 10', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		}
		while(($row = mysql_fetch_row($rsl)) !== false) {
			echo '<li>' . htmlspecialchars($row[2], ENT_QUOTES, 'utf-8') . '</li>';
			$usernames[] = $row[1];
		}
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		}
		if (!empty($usernames)) {
			echo '<li><a style="cursor:pointer" onClick="javascript:document.subscribableAddPeopleForm.submit(); return false;">Add these people</a></li>';
			echo '<form name="subscribableAddPeopleForm" action="http://' . $viewer . '/showaddpeople" method="post" style="display:none">';
			foreach($usernames as $username)
				echo '<input type="hidden" name="usernames" value="' . $username . '"/>';
			echo '<input type="hidden" name="returnURL" value="' . htmlspecialchars(get_bloginfo('url'), ENT_QUOTES) . '"/>';
			echo '</form>';
		}
	}

	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}

	if ($viewerIsSubscribed)
echo '</ul></li>';//		echo '<li><a style="cursor:pointer" href="' . htmlspecialchars(get_bloginfo('url') . '/wp-content/plugins/subscribable/showsettings.php, ENT_QUOTES) . '">Subscription settings</a></li></ul></li>';
	else {
		echo '<li><a style="cursor:pointer" onClick="javascript:document.subscribableSubscribeForm.submit(); return false;">Subscribe</a></li></ul></li>';
		echo '<form name="subscribableSubscribeForm" action="http://rr.subscribable.com/showsubscribe" method="post" style="display:none">';
		echo '<input type="hidden" name="url" value="' . htmlspecialchars(get_bloginfo('url'), ENT_QUOTES) . '"/>';
		echo '<input type="hidden" name="endpoint" value="' . htmlspecialchars(get_bloginfo('url'), ENT_QUOTES) . '/wp-content/plugins/subscribable/ContentProvider.php"/>';
		echo '<input type="hidden" name="title" value="' . htmlspecialchars(mb_strlen(get_bloginfo('name'), 'utf-8') > 200 ? mb_substr(get_bloginfo('name'), 0, 200, 'utf-8') : get_bloginfo('name'), ENT_QUOTES, 'utf-8') . '"/>';
		echo '<input type="hidden" name="cancelURL" value="' . htmlspecialchars(get_bloginfo('url'), ENT_QUOTES) . '"/>';
		echo '</form>';
	}
}

add_action('wp_insert_post', 'subscribable_check_for_new_content');
function subscribable_check_for_new_content() {

	global $wpdb;

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}

	if (($rsl = mysql_query('SELECT MAX(post_date),UNIX_TIMESTAMP(MAX(post_date)) FROM ' . $wpdb->prefix . 'posts WHERE post_type=\'post\' AND post_status=\'publish\'', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	$row = mysql_fetch_row($rsl);
	$lastPostTimeString = $row[0];
	$lastPostTime = $row[1];
	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	if (($rsl = mysql_query('SELECT UNIX_TIMESTAMP(lastPostTime) FROM ' . $wpdb->prefix . 'subscribable_blogs', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	$row = mysql_fetch_row($rsl);
	$lastUpdatedPostTime = $row[0];
	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}

	if ($lastUpdatedPostTime < $lastPostTime) {

		if (($rsl = mysql_query('SELECT post_author FROM ' . $wpdb->prefix . 'posts WHERE post_date=\'' . $lastPostTimeString . '\'', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		$row = mysql_fetch_row($rsl);
		$userID = $row[0];
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (($rsl = mysql_query('SELECT display_name FROM ' . $wpdb->prefix . 'users WHERE ID=' . $userID, $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		$row = mysql_fetch_row($rsl);
		$lastUpdaterRealName = $row[0];
		$lastUpdaterRealName = mb_strlen($lastUpdaterRealName, 'utf-8') > 100 ? mb_substr(0, 97, 'utf-8') . '...' : $lastUpdaterRealName;
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		if (($rsl = mysql_query('UPDATE ' . $wpdb->prefix . 'subscribable_blogs SET lastUpdateNbr=lastUpdateNbr+1,lastPostTime=\'' . $lastPostTimeString . '\',lastUpdater=\'' . mysql_real_escape_string('nobody.subscribable.com', $conn) . '\',lastUpdaterRealName=\'' . mysql_real_escape_string($lastUpdaterRealName, $conn) . '\',attempts=0', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
		}
		wp_clear_scheduled_hook('subscribable_send_updates');
		wp_schedule_single_event(time(), 'subscribable_send_updates');
	}

	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_ERROR);
	}
}

add_action('subscribable_send_updates', 'subscribable_send_updates');
function subscribable_send_updates() {

	global $wpdb;
	set_time_limit(0);

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}

	if (($rsl = mysql_query('SELECT lastUpdateNbr,lastUpdater,lastUpdaterRealName,attempts FROM ' . $wpdb->prefix . 'subscribable_blogs', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	$row = mysql_fetch_row($rsl);
	$lastUpdateNbr = $row[0];
	$lastUpdater = $row[1];
	$lastUpdaterRealName = $row[2];
	$attempts = $row[3];
	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}

	if (($rsl = mysql_query('SELECT url,securityCode,username,INET_NTOA(ip),CONV(updateAuthCode, 10, 16),alreadySeen FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE alreadyUpdated<' . $lastUpdateNbr, $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	while(($row = mysql_fetch_row($rsl)) !== false) {

		$url = $row[0] . '?securityCode=' . $row[1];
		$username = $row[2];
		$ip = $row[3];
		$updateAuthCode = $row[4];
		while (strlen($updateAuthCode) < 16)
			$updateAuthCode = '0' . $updateAuthCode;
		$alreadySeen = $row[5];

		if (false === ($socket = fsockopen('udp://' . $ip, 1024)))
			continue;
		$updateMessage = subscribable_build_update_message($username, $url, $lastUpdater, $lastUpdaterRealName, $lastUpdateNbr, $updateAuthCode, $alreadySeen < $lastUpdateNbr);
		$justWritten = 0;
		$totalWritten = 0;
		while ($totalWritten < mb_strlen($updateMessage, 'utf-8') && false !== ($justWritten = fwrite($socket, substr($updateMessage, $totalWritten))))
			$totalWritten += $justWritten;
		fclose($socket);
	}

	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (($rsl = mysql_query('UPDATE ' . $wpdb->prefix . 'subscribable_blogs SET attempts=attempts+1 WHERE lastUpdateNbr=' . $lastUpdateNbr, $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}

	$attempts++;
	switch($attempts) {
		case 1:
			wp_schedule_single_event(time(), 'subscribable_send_updates');
			break;
		case 2:
			wp_schedule_single_event(time() + 3600, 'subscribable_send_updates');
			break;
		case 10:
			break;
		default:
			wp_schedule_single_event(time() + 86400, 'subscribable_send_updates');
			break;
	}

	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
	if (mysql_close($conn) === false) {
		wp_schedule_single_event(time(), 'subscribable_send_updates');
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
	}
}

function subscribable_build_update_message($username, $url, $updater, $updaterRealName, $updateNbr, $updateAuthCode, $fresh) {

	$updateMessage = '';

	$updateMessage .= chr(0);
	$updateMessage .= chr(strlen($username));
	$updateMessage .= $username;

	$updateMessage .= chr(strlen($url) >> 8);
	$updateMessage .= chr(strlen($url));
	$updateMessage .= $url;

	$updateMessage .= chr(0);
	$updateMessage .= chr(strlen($updater));
	$updateMessage .= $updater;

	$updateMessage .= chr(mb_strlen($updaterRealName, 'utf-8') >> 8);
	$updateMessage .= chr(mb_strlen($updaterRealName, 'utf-8'));
	$updateMessage .= $updaterRealName;

	$updateMessage .= chr($updateNbr >> 24);
	$updateMessage .= chr($updateNbr >> 16);
	$updateMessage .= chr($updateNbr >> 8);
	$updateMessage .= chr($updateNbr);

	$updateMessage .= chr(hexdec(substr($updateAuthCode, 0, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 2, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 4, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 6, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 8, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 10, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 12, 2)));
	$updateMessage .= chr(hexdec(substr($updateAuthCode, 14, 2)));

	if ($fresh)
		$updateMessage .= chr(1);
	else
		$updateMessage .= chr(0);

	return $updateMessage;
}

?>

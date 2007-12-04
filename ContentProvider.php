<?php
/*

make changes to plugin and subscribable
	-store urn in subscriptions table...will do later, if necessary
	-make plugin php 4 compatible, switch to nusoap
	update tables to prepare for new features (?)
	get rid of login() web service
	auto-update

release version 0.1
	get it in the wordpress plugin repository
	contact some bloggers
	fix bugs
	get feedback

add new features
	think about adding a profile page
	think about supporting private blogs
	contact some bloggers
	fix bugs
	get feedback

release version 1.0
	publicize it on weblogtoolscollection.com

is it catching on? rename urlsa to rsa, decent open protocol
	url to uri
	"subscribe to a resource"
	urlsa to rsa
	switch wikipedia entry to rsa
	update messages contain uri+endpoint instead of url
	subscription table uses id as key instead of url (big change in showsubscriptions)
	subscription table keys are url and uri+endpoint, if already subscribed to that uri+endpoint, don't subscribe again

	get rid of security code in url, post+redirect+setcookie
	get rid of all url rewriting everywhere

*/

// these notes taken from subscribable.php

// low traffic future enhancements:
// lost user detection

// high traffic future enhancements:
// high traffic widget? store front page subscriber list in an option or file instead of querying each time?
// lost user detection?
// order updates by most active subscribers?
// update throttling?
// put a commit directly after the query so won't hold lock while sending updates

// a version for basic wordpress
// a version for wordpress mu
// a high-traffic version for basic wordpress
// a high-traffic version for wordpress mu

// requires wordpress 2.0, mysql 5.0
// implement username dns lookup w/ tcp (i think can be done with net_dns extension, supports tcp lookups)
// get it to work with mu? http://www.itdamager.com/2007/08/03/how-to-write-plugins-for-wordpress-mu/
// don't forget to change charset http header to utf-8 if outputting any utf-8 stuff (from database or anything)
// if you change your address, you shouldn't run into problems unless you don't set up forwarding from the old address
// subscribe to content, content is described with a string or int or something, you get a subscription, the subscription is represented by a url
// when plugin is deactivated, turn off update sending and soap endpoint, is the cron thing turned off automatically when the plugin is deactivated? actually, endpoint must always be available in case somebody wants to unsubscribe...
//get_bloginfo('url'); // upon activation make sure this is plenty less than 2048
//ini_set("soap.wsdl_cache_enabled", "0"); // disabling WSDL cache
// requires at least php 5.0

require_once(realpath('../../../wp-config.php'));
require_once('lib/nusoap.php');

function isaLegalUsername($username) {

	if (strlen($username) > 255)
		return false;
	if (!preg_match('/^[a-zA-Z0-9](([a-zA-Z0-9\\-])*[a-zA-Z0-9])?(\\.[a-zA-Z0-9](([a-zA-Z0-9\\-])*[a-zA-Z0-9])?)*$/', $username))
		return false;
	$labelArray = explode('.', $username);
	foreach($labelArray as $label)
		if (strlen($label) > 63)
			return false;
	return true;
}

function generateSecurityCode() {
	return generateRandom64BitHexString() . generateRandom64BitHexString();
}

function generateUpdateAuthCode() {
	return generateRandom64BitHexString();
}

function generateRandom64BitHexString() {

	$hexString = '';
	$counter = 0;
	while($counter++ < 16)
		$hexString .= dechex(mt_rand(0, 15));
	return $hexString;
}

function LogIn($username, $realName) {

	if ($username === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!isaLegalUsername($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a legal username'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	$username = strtolower($username);

	if ($realName === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($realName))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (mb_strlen($realName, 'utf-8') > 100)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter must be no more than 100 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	if (gethostbyname($username) != $_SERVER['REMOTE_ADDR'])
		return new soap_fault('Client', '', '', array('detail' => new soapval('AuthorizationFault', 'AuthorizationFault', array('errorMessage' => 'the hostname ' . $username . ' does not resolve to your ip address'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	return new soap_fault('Client', '', '', array('detail' => new soapval('LogInFault', 'LogInFault', array('errorMessage' => 'LogIn() is not supported by this blog'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
}

function Invite($usernames, $realNames, $url, $inviter) {

	global $wpdb;

	if ($usernames === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_array($usernames))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter must be an array'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (count($usernames) == 0)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter cannot be empty'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	for($i = 0; $i < count($usernames); $i++) {
		if ($usernames[$i] === null)
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter cannot contain a null value'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		elseif (!is_string($usernames[$i]))
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter values must be strings'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		elseif (!isaLegalUsername($usernames[$i]))
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter values must be legal usernames'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		else {
			$usernames[$i] = strtolower($usernames[$i]);
			$count = 0;
			foreach($usernames as $value)
				if ($usernames[$i] === $value)
					$count++;
			if ($count > 1)
				return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter cannot contain duplicate values'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
	}

	if ($realNames === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realNames[]" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_array($realNames))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realNames[]" parameter must be an array'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (count($realNames) != count($usernames))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" and "realNames[]" parameters must have the same length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	foreach($realNames as $value) {
		if ($value === null)
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realNames[]" parameter cannot contain a null value'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		elseif (!is_string($value))
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realNames[]" parameter values must be strings'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		elseif (mb_strlen($value, 'utf-8') > 100)
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realNames[]" parameter values must be no more than 100 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	if ($url === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($url))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (strlen($url) > 2048)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be no more than 2048 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	if ($inviter === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"inviter" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($inviter))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"inviter" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!isaLegalUsername($inviter))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"inviter" parameter must be a legal username'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	foreach($usernames as $value)
		if ($inviter == $value)
			return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"usernames[]" parameter cannot contain the inviter\'s username'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	$inviter = strtolower($inviter);

	if (gethostbyname($inviter) != $_SERVER['REMOTE_ADDR'])
		return new soap_fault('Client', '', '', array('detail' => new soapval('AuthorizationFault', 'AuthorizationFault', array('errorMessage' => 'the hostname ' . $inviter . ' does not resolve to your ip address'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	$securityCode = '';
	if (($rsl = mysql_query('SELECT securityCode FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE username=\'' . $inviter . '\'', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (($row = mysql_fetch_row($rsl)) !== false)
		$securityCode = '?securityCode=' . $row[0];

	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('InviteFault', 'InviteFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	return get_bloginfo('url') . $securityCode;
}

function Subscribe($username, $realName, $url) {

	global $wpdb;

	if ($username === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!isaLegalUsername($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a legal username'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	$username = strtolower($username);

	if ($realName === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($realName))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (mb_strlen($realName, 'utf-8') > 100)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"realName" parameter must be no more than 100 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	if ($url === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($url))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (strlen($url) > 2048)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be no more than 2048 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	$ip = $_SERVER['REMOTE_ADDR'];
	if (gethostbyname($username) != $ip)
		return new soap_fault('Client', '', '', array('detail' => new soapval('AuthorizationFault', 'AuthorizationFault', array('errorMessage' => 'the hostname ' . $username . ' does not resolve to your ip address'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	if (($rsl = mysql_query('SELECT lastUpdateNbr FROM ' . $wpdb->prefix . 'subscribable_blogs', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	$row = mysql_fetch_row($rsl);
	$lastUpdateNbr = $row[0];
	if (mysql_free_result($rsl) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	$result = array();
	if (($rsl = mysql_query('SELECT url,securityCode,updateAuthCode AS STRING FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE username=\'' . $username . '\'', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (($row = mysql_fetch_row($rsl)) !== false) {

		$result[] = $row[0] . '?securityCode=' . $row[1];
		$result[] = $row[2];
		$result[] = mb_strlen(get_bloginfo('name'), 'utf-8') > 200 ? mb_substr(get_bloginfo('name'), 0, 200, 'utf-8') : get_bloginfo('name');
		$result[] = $row[0] . '?securityCode=' . $row[1];
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		if (($rsl = mysql_query('UPDATE ' . $wpdb->prefix . 'subscribable_subscribers SET realName=\'' . mysql_real_escape_string($realName, $conn) . '\',ip=INET_ATON(\'' . $ip . '\') WHERE username=\'' . $username . '\'', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
	}
	else {

		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		$securityCode = generateSecurityCode();
		if (($rsl = mysql_query('SELECT securityCode FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE securityCode=\'' . $securityCode . '\'', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		while(mysql_fetch_row($rsl) !== false) {
			if (mysql_free_result($rsl) === false) {
				mysql_query('ROLLBACK', $conn);
				mysql_close($conn);
				trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
				return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
			}
			$securityCode = generateSecurityCode();
			if (($rsl = mysql_query('SELECT securityCode FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE securityCode=\'' . $securityCode . '\'', $conn)) === false) {
				mysql_query('ROLLBACK', $conn);
				mysql_close($conn);
				trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
				return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
			}
		}
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		if (($rsl = mysql_query('SELECT CONV(\'' . generateUpdateAuthCode() . '\', 16, -10)', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		$row = mysql_fetch_row($rsl);
		$updateAuthCode = $row[0];
		if (mysql_free_result($rsl) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not free database query result set: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
		$result[] = get_bloginfo('url') . '?securityCode=' . $securityCode;
		$result[] = $updateAuthCode;
		$result[] = mb_strlen(get_bloginfo('name'), 'utf-8') > 200 ? mb_substr(get_bloginfo('name'), 0, 200, 'utf-8') : get_bloginfo('name');
		$result[] = get_bloginfo('url') . '?securityCode=' . $securityCode;
		if (($rsl = mysql_query('INSERT INTO ' . $wpdb->prefix . 'subscribable_subscribers VALUES (\'' . mysql_real_escape_string(get_bloginfo('url'), $conn) . '\', \'' . $securityCode . '\', \'' . $username . '\', \'' . mysql_real_escape_string($realName, $conn) . '\', INET_ATON(\'' . $ip . '\'), ' . $updateAuthCode . ', NOW(), -1, ' . $lastUpdateNbr . ')', $conn)) === false) {
			mysql_query('ROLLBACK', $conn);
			mysql_close($conn);
			trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
			return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
		}
	}

	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('SubscribeFault', 'SubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	return $result;
}

function Unsubscribe($username, $url) {

	global $wpdb;

	if ($username === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!isaLegalUsername($username))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"username" parameter must be a legal username'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	$username = strtolower($username);

	if ($url === null)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter cannot be null'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (!is_string($url))
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be a string'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	elseif (strlen($url) > 2048)
		return new soap_fault('Client', '', '', array('detail' => new soapval('ParameterFault', 'ParameterFault', array('errorMessage' => '"url" parameter must be no more than 2048 characters in length'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	if (gethostbyname($username) != $_SERVER['REMOTE_ADDR'])
		return new soap_fault('Client', '', '', array('detail' => new soapval('AuthorizationFault', 'AuthorizationFault', array('errorMessage' => 'the hostname ' . $username . ' does not resolve to your ip address'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));

	$conn = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD, true);
	if ($conn === false) {
		trigger_error('Could not connect to database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('SET NAMES utf8', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could set database connection character encoding to utf-8: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_select_db(DB_NAME, $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not select database: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_query('BEGIN', $conn) === false) {
		mysql_close($conn);
		trigger_error('Could not start database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	if (($rsl = mysql_query('DELETE FROM ' . $wpdb->prefix . 'subscribable_subscribers WHERE username=\'' . $username . '\'', $conn)) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not execute database query: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}

	if (mysql_query('COMMIT', $conn) === false) {
		mysql_query('ROLLBACK', $conn);
		mysql_close($conn);
		trigger_error('Could not commit database transaction: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
	if (mysql_close($conn) === false) {
		trigger_error('Could not close database connection: ' . mysql_error() . ' (' . mysql_errno() . ')', E_USER_WARNING);
		return new soap_fault('Server', '', '', array('detail' => new soapval('UnsubscribeFault', 'UnsubscribeFault', array('errorMessage' => 'subscribable internal error'), 'http://www.subscribable.com/URLSA/', 'http://www.subscribable.com/URLSA/')));
	}
}

$server = new soap_server('ContentProvider.wsdl');
//$server->soap_defencoding = 'UTF-8';
//$server->decode_utf8 = false;
$HTTP_RAW_POST_DATA = isset($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : '';
$server->service($HTTP_RAW_POST_DATA);

?>

<?php
require_once __DIR__.'/api.php';

Class Webinar {
	
	private static $_email				= "youremail@domain.com";
	private static $_password			= "yourpassword";
	private static $_key 				= "3363335922197614469";
	const CONSUMER_KEY					= "5btgN2JhCGQtx19RkQl0fSxT80VDZtlg";
	
	
	public static function RunModule() {
		/*** GoToWebinar API **/
		OSD::setup(CONSUMER_KEY);
		if (!OSD::is_authenticated()) {
			OSD::authenticate_direct(self::$_email, self::$_password, CONSUMER_KEY);
		} else {
			//Test for API to Get All Webinars
			$key = self::$_key;
			$rows = OSD::get("/G2W/rest/organizers/{$key}/webinars/")->body;
			//echo "<hr />";
		}
    }
	
	
	//For dev reference only!
	public static function AuthenticateWebinar() {
		cubeCore::cancelTemplate();
		if (!(version_compare(PHP_VERSION, "5.3") >= 0)) {
		  throw new Exception('OSD PHP library requires PHP 5.3 or higher.');
		}
		
		// Setup the API client reference. Client ID and Client Secrets are defined
		// as constants in config.php
		OSD::setup(CONSUMER_KEY);
		if (!OSD::is_authenticated()) {
			print "Press the 'Connect' button to connect to api platform <br><br>";
			$id = CONSUMER_KEY;
			print "<a href=\"https://api.citrixonline.com/oauth/authorize?client_id={$id}\"><button>Connect</button></a>";
			unset($id);
		} else {
			//$org_key = OSD::$oauth->organizer_key;
			//self::$_token = OSD::$oauth->access_token; 
		    //print "<p>You have been authenticated.<br />Your access token is: ".self::$_token."<br/>Your Orgnizer key is: ".self::$_key."</p>";
			/* Test to GET Webinars */
			$key = self::$_key;
			$rows = OSD::get("/G2W/rest/organizers/{$key}/webinars/")->body;
			print_r($rows);
			unset($key, $rows);
		}
		
		//Callback from Authentication 
		if(isset($_GET['code'])) {
			$code = $_GET['code'];
			$response = OSD::authenticate_with_authorization_code($code);
		}
    }
	

	
	private static function getWebinars($archive=false) {
		
		self::$_HTML = "";
		OSD::setup(CONSUMER_KEY);
		if (!OSD::is_authenticated()) {
			OSD::authenticate_direct(self::$_email, self::$_password, CONSUMER_KEY);
		}
		
		$key = OSD::$oauth->organizer_key;
		
		if ($archive == "false") {
			$rows = OSD::get("/G2W/rest/organizers/{$key}/webinars/")->body;
			$rows = json_decode($rows, true);
		} else {
			$current_date = date('Y-m-d');
			$rows = OSD::get("/G2W/rest/organizers/{$key}/historicalWebinars?fromTime=2012-04-16T18:00:00Z&toTime={$current_date}T18:00:00Z")->body;
			$rows = json_decode($rows, true);
		}

		return $rows;
	}
	
	
	/**
	 * 	Register for Webinars without making payment
	 */
	public static function directWebinarRegister() {
		
		if (empty($_POST['fname'])) return;
		if (empty($_POST['lname'])) return;
		if (empty($_POST['email'])) return;
		if (empty($_POST['webinar-id'])) return;
		if ($_SESSION['member-info']['user_level'] != 2) return;
		
		if (isset($_POST['webinar-direct-register-submit'])) {
			$status = self::registerUser(cubeDB::escape($_POST['fname']), cubeDB::escape($_POST['lname']), cubeDB::escape($_POST['email']), cubeDB::escape($_POST['webinar-id']));
			
			if ($status) {
				header("Location: /members/successfull-webinar.php", true, 302);
			} else {
				$JS = "<script type='text/javascript>
					   		alert('Sorry, your request for registration has been failed, please contact us to investiagte the issue further.');
					   </script>";
				return $JS;
				//return false;
			}
		} else {
			return false;
		}
		
	}
	
	
	
	public static function registerUser($first_name, $last_name, $email_address, $webinar_key) {
		
		$attributes = array();
		$attributes['email'] 		= $email_address;
		$attributes['firstName'] 	= $first_name;
		$attributes['lastName'] 	= $last_name;
		
		OSD::setup(CONSUMER_KEY);
		if (!OSD::is_authenticated()) {
			OSD::authenticate_direct(self::$_email, self::$_password, CONSUMER_KEY);
		}
		$key = OSD::$oauth->organizer_key;
		//$key = self::$_key;
		$response = OSD::post("/G2W/rest/organizers/{$key}/webinars/{$webinar_key}/registrants?resendConfirmation=false", $attributes)->status;
		if ($response) {
			return true;
		} else {
			return false;
		}
	}
	

	
	/**
	 * 	Checks to see if request is POST then displays the page
	 * 	This being used for successfull or declined messages in page
	 */
	public static function detectRequestCode() {
		
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			die('Access Forbidden');
		}
		return true;
	}
	
}





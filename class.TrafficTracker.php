<?php
/* ==========================================================
 * class.TrafficTracker.php v1.6
 * https://github.com/adamdehaven/TrafficTracker
 * 
 * Author: Adam Dehaven ( @adamdehaven )
 * http://about.adamdehaven.com/
 * 
 * ==========================================================
 * MIT License: https://github.com/adamdehaven/TrafficTracker/blob/master/LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 * ========================================================== */

class TrafficTracker
{
  /* -------------------------------------------------------------------------
	------------------------------- SET DEFAULTS -------------------------------
	--------------------------------------------------------------------------*/
	private $urlPrefix 	= 'http'; // Set URL prefix for your website.
	private $replaceInUrl 	= array('?customer=new','?version=mobile'); // strip out any custom strings from URL.
	private $myIp 		= array('10.0.0.1'); // Put IP addresses you would like to filter out (not track) in array.
	private $reportingTimezone = 'America/Kentucky/Louisville'; // http://www.php.net/manual/en/timezones.america.php
	private $dateFormat 	= 'Y-m-d H:i:s'; // Preferred date format - http://php.net/manual/en/function.date.php
	private $cookieExpire 	= 30; // Set number of days for AdWords tracking cookies to be valid.
	/* =========================================================================
	============================= DO NOT EDIT BELOW ============================
	==========================================================================*/
	private $dbHost;
	private $dbUsername;
	private $dbPassword;
	private $dbDatabase;
	private $trackPrefix;
	private $deleteRollingDays;
	private	$referrerMedium;
	private	$referrerSource;
	private	$referrerContent;
	private	$referrerCampaign;
	private	$referrerKeyword;
	private	$referrerAdwordsKeyword;
	private	$referrerAdwordsMatchType;
	private	$referrerAdwordsPosition;
	private	$identifyUser;
	private	$firstVisit;
	private	$previousVisit;
	private	$currentVisitStarted;
	private	$timesVisited;
	private	$pagesViewed;
	private	$theCurrentUrl;
	private $referrerPageViewed;
	private $userIp;
	private $visitTimestamp;
	
	private function setAdwordsCookies() {
		if(isset($_GET[$this->trackPrefix]) && $_GET[$this->trackPrefix] == 'true'):
			// Set cookie indicating referrer is Google AdWords Click good for 30 days
			setcookie($this->trackPrefix.'_referrer', 'googleAdwords', $cookieDie,'/');
			setcookie($this->trackPrefix.'_kw', $_GET[$this->trackPrefix.'_kw'], $cookieDie,'/');
			setcookie($this->trackPrefix.'_pos', $_GET[$this->trackPrefix.'_pos'], $cookieDie,'/');
			setcookie($this->trackPrefix.'_mt', $_GET[$this->trackPrefix.'_mt'], $cookieDie,'/');
		endif;
	} //-- end setAdwordsCookies()
	
	private function processDefaults() {
		$dirtyUrl 		= $this->urlPrefix.'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']; // Get URL of the current page.
		$this->theCurrentUrl 	= str_replace($replaceInUrl, '', $dirtyUrl); // delete unwanted strings from URL.
		$this->visitTimestamp 	= date($this->dateFormat); // Set Current Date & Time
		$this->userIp 		= $_SERVER['REMOTE_ADDR']; // Set Visitor's IP Address
	} //--end processDefaults()
	
	private function parseCookies() {
		// Parse __utmz cookie
		list($domainHash,$sourceTimestamp, $sessionNumber, $campaignNumber, $campaignData) = explode('.', $_COOKIE["__utmz"],5);
		$sourceData = parse_str(strtr($campaignData, '|', '&')); // Parse the __utmz data
		
		$this->referrerMedium		= $utmcmd;	// medium (organic, referral, direct, etc)
		$this->referrerSource		= $utmcsr;	// source (google, facebook.com, etc)
		$this->referrerContent		= $utmcct;	// content (index.html, etc)
		$this->referrerCampaign		= $utmccn; // campaign 
		$this->referrerKeyword		= $utmctr;	// term (search term)
		$this->referrerPageViewed	= $this->theCurrentUrl;
		if(isset($utmgclid)): // if from AdWords
			$this->referrerSource		= 'google';
			$this->referrerCampaign		= '';
			$this->referrerMedium		= 'cpc';
			$this->referrerContent		= '';
			$this->referrerAdwordsKeyword	= $utmctr;
		endif;
		
		// Parse the __utma Cookie
		list($domainHash,$uniqueId,$timestampFirstVisit,$timestampPreviousVisit,$timestampStartCurrentVisit,$numSessionsStarted) = explode('.', $_COOKIE["__utma"]);
		
		$this->identifyUser		= $uniqueId; // Get Google Analytics unique user ID.
		$this->firstVisit		= date($this->dateFormat,$timestampFirstVisit); // Get timestamp of first visit.
		$this->previousVisit		= date($this->dateFormat,$timestampPreviousVisit); // Get timestamp of previous visit.
		$this->currentVisitStarted	= date($this->dateFormat,$timestampStartCurrentVisit); // Get timestamp of current visit.
		$this->timesVisited		= $numSessionsStarted; // Get number of times visited.
		
		// Parse the __utmb Cookie
		list($domainHash,$pageViews,$garbage,$timestampStartCurrentVisit) = explode('.', $_COOKIE['__utmb']);
		$this->pagesViewed = $pageViews; // Get the total number of page views.
	}	//-- end parseCookies()
	
	// If user came from AdWords
	private function setIfAdWords() {
		$this->referrerAdwordsKeyword	= (isset($_COOKIE[$this->trackPrefix.'_kw']) ? $_COOKIE[$this->trackPrefix.'_kw'] : $utmctr); // Set adwordsKeyword
		$this->referrerAdwordsMatchType	= (isset($_COOKIE[$this->trackPrefix.'_mt']) ? $_COOKIE[$this->trackPrefix.'_mt'] : ''); // Set adwordsMatchType
		$this->referrerAdwordsPosition	= (isset($_COOKIE[$this->trackPrefix.'_pos']) ? $_COOKIE[$this->trackPrefix.'_pos'] : ''); // Set adwordsPosition
	} //-- end setIfAdWords()
	
	private function logTraffic() { // Write to Database
		error_reporting(0);  
		$mysqli = new mysqli($this->dbHost,$this->dbUsername,$this->dbPassword,$this->dbDatabase); // Connect to database
		if ($mysqli->connect_error):
			die('Connect Error (' . $mysqli->connect_errno . ') '.$mysqli->connect_error);
		endif;
		if(isset($this->referrerPageViewed) && !in_array($this->userIp,$this->myIp)): // If referrerPageViewed is set & not an internal IP
			$mysqli->query("INSERT INTO trafficTracker (identifyUser, medium, source, content, campaign, keyword, pageViewed, adwordsKeyword, adwordsMatchType, adwordsPosition, firstVisit, previousVisit, currentVisit, timesVisited, pagesViewed, userIp, timestamp) VALUES 
				(
				'".$this->identifyUser."',
				'".$this->referrerMedium."', 
				'".$this->referrerSource."', 
				'".$this->referrerContent."', 
				'".$this->referrerCampaign."', 
				'".$this->referrerKeyword."',
				'".$this->referrerPageViewed."', 
				'".$this->referrerAdwordsKeyword."', 
				'".$this->referrerAdwordsMatchType."', 
				'".$this->referrerAdwordsPosition."', 
				'".$this->firstVisit."', 
				'".$this->previousVisit."', 
				'".$this->currentVisitStarted."', 
				'".$this->timesVisited."', 
				'".$this->pagesViewed."', 
				'".$this->userIp."',
				'".$this->visitTimestamp."'
				)"
			);
		endif;
		$mysqli->query("DELETE FROM trafficTracker WHERE timestamp < DATE_SUB(NOW(), INTERVAL ".$this->deleteRollingDays." DAY)"); 
		$mysqli->close();
	} //-- end logTraffic()
	
	function __construct($dbHost,$dbUsername,$dbPassword,$dbDatabase,$trackPrefix='ttcpc',$deleteRollingDays=30) {
		$this->dbHost = $dbHost;
		$this->dbUsername = $dbUsername;
		$this->dbPassword = $dbPassword;
		$this->dbDatabase = $dbDatabase;
		$this->trackPrefix = $trackPrefix;
		$this->deleteRollingDays = "$deleteRollingDays";
		date_default_timezone_set($this->reportingTimezone); // Set timezone.
		$cookieDie = time() + ($this->cookieExpire * 24 * 60 * 60); // Cookie is good for X Number of days; 24 hours; 60 mins; 60secs
		$this->setAdwordsCookies(); // Set cookies for AdWords
		// If we have the cookies - parse them
		if(isset($_COOKIE['__utma']) && isset($_COOKIE['__utmz'])):
			$this->processDefaults();
			$this->parseCookies();
			$this->setIfAdWords();
			$this->logTraffic();
		endif;
	} //-- end __construct($_COOKIE)
	
} //-- end class TrafficTracker
?>

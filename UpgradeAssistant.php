#!/usr/bin/php
<?PHP
echo "Disclaimer:  This script is NOT definitive.  There may be other issues with your server that will affect compatibility.\n\n";

$currentUnRaidVersion = parse_ini_file("/etc/unraid-version");
if ( version_compare($currentUnRaidVersion,"6.3.5","<=") ) {
	exec("plugin check /var/log/unRAIDServer.plg");
} else {
	exec("plugin checkos");
}
$newUnRaidVersion = exec("plugin version /tmp/plugins/unRAIDServer.plg");

echo "Current unRaid Version: {$currentUnRaidVersion['version']}   Upgrade unRaid Version: $newUnRaidVersion\n\n";

if ( version_compare($newUnRaidVersion,$currentUnRaidVersion['version'],"=") ) {
	echo "NOTE: You are currently running the latest version of unRaid.  To check compatibility against the 'next' branch of unRaid, go to Upgrade OS and select 'Next' branch and then re-run these tests\n\n";
}

# MAIN

# Check for correct starting sector on the partition for cache drive
echo "Checking cache drive partitioning\n";
$disks = parse_ini_file("/var/local/emhttp/disks.ini",true);
if ( $disks['cache']['status'] == "DISK_OK" ) {
	$cacheDevice = $disks['cache']['device'];
	$output = exec("fdisk -l /dev/$cacheDevice | grep /dev/{$cacheDevice}1");
	$line = preg_replace('!\s+!',' ',$output);
	$contents = explode(" ",$line);
  if ( $contents[1] != "64" ) {
		ISSUE("Cache drive partition doesn't start on sector 64.  You will have problems.  See here https://lime-technology.com/forums/topic/46802-faq-for-unraid-v6/?tab=comments#comment-511923 for how to fix this.");
	} else {
		OK("Cache drive partition starts on sector 64");
	}
} else {
	OK("Cache drive not present");
}
#check for plugins up to date
echo "\nChecking for plugin updates\n";
exec("plugin checkall > /dev/null 2>&1");
$installedPlugs = glob("/tmp/plugins/*.plg");
foreach ($installedPlugs as $installedPlg) {
	$updateVer = exec("plugin version ".escapeshellarg($installedPlg));
	$installedVer = exec("plugin version ".escapeshellarg("/boot/config/plugins/".basename($installedPlg)));
	if (version_compare($updateVer,$installedVer,">")) {
		$pluginName = exec("plugin name ".escapeshellarg($installedPlg));
		ISSUE(basename($installedPlg)." ($pluginName) is not up to date.  It is recommended to update all your plugins.");
		$updateFlag = true;
	}
}
if ( ! $updateFlag ) {
	OK("All plugins up to date");
}
 
# Check for plugins compatible
echo "\nChecking for plugin compatibility\n";

$moderation = download_json("https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Moderation.json","/tmp/upgradeAssistantModeration.json");

foreach ($installedPlugs as $installedPlg) {
	$pluginURL = exec("plugin pluginURL ".escapeshellarg($installedPlg));
	if ( $moderation[$pluginURL]['MaxVer'] ) {
		if ( version_compare($newUnRaidVersion,$moderation[$pluginURL]['MaxVer'],">") ) {
			$pluginName = exec("plugin name ".escapeshellarg($installedPlg));
			ISSUE(basename($installedPlg)." ($pluginName) is not compatible with $newUnRaidVersion.  It is HIGHLY recommended to uninstall this plugin");
			$versionsFlag = true;
		}
	}
	if ( $moderation[$pluginURL]['DeprecatedMaxVer'] ) {
		if ( version_compare($newUnRaidVersion,$moderation[$pluginURL]['DeprecatedMaxVer'],">") ) {
			$pluginName = exec("plugin name ".escapeshellarg($installedPlg));
			ISSUE(basename($installedPlg)." ($pluginName) is deprecated with $newUnRaidVersion.  It is recommended to uninstall this plugin");
			$versionsFlag = true;
		}
	}
}
if ( ! $versionsFlag ) {
	OK("All plugins are compatible");
}

# Check for extra parameters on emhttp executable
echo "\nChecking for extra parameters on emhttp\n";
$emhttpExe = exec("cat /boot/config/go | grep /usr/local/sbin/emhttp");

$emhttpParams = trim(str_replace("/usr/local/sbin/emhttp","",$emhttpExe));
if ( $emhttpParams == "&" || ! $emhttpParams) {
	OK("emhttp command in /boot/config/go contains no extra parameters");
} else {
	ISSUE("emhttp command in /boot/config/go has extra parameters passed to it.  Currently emhttp does not accept any extra paramters.  These should be removed");
}

# check for zenstates in go file
echo "\nChecking for zenstates on Ryzen CPU\n";
$output = exec("lscpu | grep Ryzen");
if ( $output ) {
	$output = exec("cat /boot/config/go | grep  /usr/local/sbin/zenstates");
	if ( ! $output ) {
		ISSUE("zenstates is not loading withing /boot/config/go  See here: https://lime-technology.com/forums/topic/66327-unraid-os-version-641-stable-release-update-notes/");
	}
} else {
	OK("Ryzen CPU not detected");
}

# Check for disabled disks
echo "\nChecking for disabled disks\n";
foreach ($disks as $disk) {
	if ($disk['status'] == 'DISK_DSBL') {
		ISSUE("{$disk['name']} is disabled.  Highly recommended to fix this problem before upgrading your OS");
		$diskDSBLflag = true;
	}
}
if ( ! $diskDSBLflag ) {
	OK("No disks are disabled");
}

# Check for old versions of dynamix.plg still present\n
echo "\nChecking for very old versions of dynamix.plg\n";
if ( is_file("/boot/config/plugins/dynamix.plg") ) {
	ISSUE("dynamix.plg exists at /config/plugins on the flash drive.  Very old versions of this file can cause issues.  It is recommended to delete this file");
} else {
	OK("dynamix.plg not found");
}

# Check for less than 2G memory_get_peak_usage
echo "\nChecking installed RAM\n";
$file = trim(str_replace("MemTotal:","",exec("cat /proc/meminfo | grep MemTotal:")));
$raw = explode(" ",$file);
	
if ($raw[0] < 3500000 ) {
	ISSUE("The functional minimum of memory for unRaid is 4G as a very basic NAS.  You will need to increase your memory");
} else {
	OK("You have 4+ Gig of memory");
}


if ($ISSUES_FOUND) {
	echo "\n\nIssues have been found with your server that may hinder the OS upgrade.  You should rectify those problems before upgrading\n";
} else {
	echo "\n\nNo issues were found with your server that may hinder the OS upgrade.  You should be good to go\n";
}

#Support stuff
function download_url($url, $path = "", $bg = false,$requestNoCache=false){
	if ( ! strpos($url,"?") ) {
		$url .= "?".time(); # append time to always wind up requesting a non cached version
	}
	exec("curl --compressed --max-time 60 --silent --insecure --location --fail ".($path ? " -o '$path' " : "")." $url ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code );
	return ($exit_code === 0 ) ? implode("\n", $out) : false;
}
function readJsonFile($filename) {
	$json = json_decode(@file_get_contents($filename),true);
	if ( ! is_array($json) ) { $json = array(); }
	return $json;
}
function download_json($url,$path) {
	download_url($url,$path);
	return readJsonFile($path);
}

function OK($msg) {
	echo "OK: $msg\n";
}
function ISSUE($msg) {
	global $ISSUES_FOUND;
	echo "Issue Found: $msg\n";
	$ISSUES_FOUND = true;
}
?>
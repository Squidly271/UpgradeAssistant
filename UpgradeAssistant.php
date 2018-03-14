#!/usr/bin/php
<?PHP

echo "Disclaimer:  This script is NOT definitive.  There may be other issues with your server that will affect compatibility.\n\n";

exec("plugin checkos");
$newUnRaidVersion = exec("plugin version /tmp/plugins/unRAIDServer.plg");
$currentUnRaidVersion = parse_ini_file("/etc/unraid-version");

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
		echo "Error: Cache drive partition doesn't start on sector 64.  You will have problems.  See here https://lime-technology.com/forums/topic/46802-faq-for-unraid-v6/?tab=comments#comment-511923 for how to fix this.\n";
	} else {
		echo "OK: Cache drive partition starts on sector 64\n";
	}
} else {
	echo "OK: Cache drive not present\n";
}
#check for plugins up to date
echo "\nChecking for plugin updates\n";
exec("plugin checkall > /dev/null 2>&1");
$installedPlugs = glob("/tmp/plugins/*.plg");
foreach ($installedPlugs as $installedPlg) {
	$updateVer = exec("plugin version ".escapeshellarg($installedPlg));
	$installedVer = exec("plugin version ".escapeshellarg("/boot/config/plugins/".basename($installedPlg)));
	if (version_compare($updateVer,$installedVer,">")) {
		echo "Warning: ".basename($installedPlg)." is not up to date\n";
		$updateFlag = true;
	}
}
if ( ! $updateFlag ) {
	echo "OK: All plugins up to date\n";
}
 
# Check for plugins compatible

echo "\nChecking for plugin compatibility\n";
	

$moderation = download_json("https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Moderation.json","/tmp/upgradeAssistantModeration.json");

foreach ($installedPlugs as $installedPlg) {
	$pluginURL = exec("plugin pluginURL ".escapeshellarg($installedPlg));
	if ( $moderation[$pluginURL]['MaxVer'] ) {
		if ( version_compare($newUnRaidVersion,$moderation[$pluginURL]['MaxVer'],">") ) {
			echo "Error: ".basename($installedPlg)." is not compatible with $newUnRaidVersion.  It is HIGHLY recommended to uninstall this plugin\n";
			$versionsFlag = true;
		}
	}
	if ( $moderation[$pluginURL]['DeprecatedMaxVer'] ) {
		if ( version_compare($newUnRaidVersion,$moderation[$pluginURL]['DeprecatedMaxVer'],">") ) {
			echo "Error: ".basename($installedPlg)." is deprecated with $newUnRaidVersion.  It is recommended to uninstall this plugin\n";
			$versionsFlag = true;
		}
	}
	
}
if ( ! $versionsFlag ) {
	echo "OK: All plugins are compatible\n";
}

# Check for extra parameters on emhttp executable
echo "\nChecking for extra parameters on emhttp\n";
$emhttpExe = exec("cat /boot/config/go | grep /usr/local/sbin/emhttp");

$emhttpParams = trim(str_replace("/usr/local/sbin/emhttp","",$emhttpExe));
if ( $emhttpParams == "&" || ! $emhttpParams) {
	echo "OK: emhttp command in /boot/config/go contains no extra parameters\n";
} else {
	echo "Warning: emhttp command in /boot/config/go has extra parameters passed to it.  Currently emhttp does not accept any extra paramters.  These should be removed\n";
	echo $emhttpParams;
}

# check for zenstates in go file
echo "\nChecking for zenstates on Ryzen CPU\n";
$output = exec("lscpu | grep Ryzen");
if ( $output ) {
	$output = exec("cat /boot/config/go | grep  /usr/local/sbin/zenstates");
	if ( ! $output ) {
		echo "Warning: zenstates is not loading withing /boot/config/go  See here: https://lime-technology.com/forums/topic/66327-unraid-os-version-641-stable-release-update-notes/\n";
	}
} else {
	echo "OK: Ryzen CPU not detected\n";
}

# Check for disabled disks
echo "\nChecking for disabled disks\n";
foreach ($disks as $disk) {
	if ($disk['status'] == 'DISK_DSBL') {
		echo "Warning: {$disk['name']} is disabled.  Highly recommended to fix this problem before upgrading your OS\n";
		$diskDSBLflag = true;
	}
}
if ( ! $diskDSBLflag ) {
	echo "OK: No disks are disabled\n";
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
?>

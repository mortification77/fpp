<?
/////////////////////////////////////////////////////////////////////////////
/**
 * Returns a list of backups in the specified location
 * GET /api/backups/list
 * @param $backupDir
 * @return array
 */
function GetAvailableBackupsFromDir($backupDir)
{
	global $settings;

	$excludeList = array();
	$dirs = array();

	foreach (scandir($settings['mediaDirectory']) as $fileName) {
		if (($fileName != '.') &&
			($fileName != '..')) {
			array_push($excludeList, $fileName);
		}
	}

	array_push($dirs, '/');

	foreach (scandir($backupDir) as $fileName) {
		if (($fileName != '.') &&
			($fileName != '..') &&
			(!in_array($fileName, $excludeList)) &&
			(is_dir($backupDir . '/' . $fileName))) {
			array_push($dirs, $fileName);

			foreach (scandir($backupDir . '/' . $fileName) as $subfileName) {
				if (($subfileName != '.') &&
					($subfileName != '..') &&
					(!in_array($subfileName, $excludeList)) &&
					(is_dir($backupDir . '/' . $fileName . '/' . $subfileName))) {
					array_push($dirs, $fileName . '/' . $subfileName);
				}
			}
		}
	}

	return $dirs;
}

/**
 * Returns a list of local FPP file backup directories
 * GET api/backups/list
 * @return string
 */
function GetAvailableBackups()
{
	global $settings;
	return json(GetAvailableBackupsFromDir($settings['mediaDirectory'] . '/backups/'));
}

function CheckIfDeviceIsUsable($deviceName)
{
	global $SUDO;

	// Check if in use / Mount / List / Unmount
	$mountPoint = exec($SUDO . " lsblk /dev/$deviceName");
	$mountPoint = preg_replace('/.*disk ?/', '', $mountPoint);
	$mountPoint = preg_replace('/.*part ?/', '', $mountPoint);
	if (preg_match('/[a-z0-9\/]/', $mountPoint))
		return "ERROR: Partition is mounted on: $mountPoint";

	$isSwap = exec("grep /dev/$deviceName /proc/swaps");
	if ($isSwap != "")
		return "ERROR: $deviceName is a swap partition";

	return "";
}

/**
 * Returns a list of devices like USB's or SSD's attached to the system
 * GET api/backups/devices
 * @return string
 */
function GetAvailableBackupsDevices()
{
	global $SUDO;
	$devices = array();

	foreach (scandir("/dev/") as $deviceName) {
		if (preg_match("/^sd[a-z][0-9]/", $deviceName)) {
			exec($SUDO . " sfdisk -s /dev/$deviceName", $output, $return_val);
			$GB = round(intval($output[0]) / 1024.0 / 1024.0, 1);
			unset($output);

			if ($GB <= 0.1)
				continue;

			$unusable = CheckIfDeviceIsUsable($deviceName);
			if ($unusable != '')
				continue;

			$baseDevice = preg_replace('/[0-9]*$/', '', $deviceName);

			$device = array();
			$device['name'] = $deviceName;
			$device['size'] = $GB;
			$device['model'] = exec("cat /sys/block/$baseDevice/device/model");
			$device['vendor'] = exec("cat /sys/block/$baseDevice/device/vendor");

			array_push($devices, $device);
		}
	}

	return json($devices);
}

/**
 * Returns a list of FPP file backups on a specified device like USB or SSD attached to the system
 * GET api/backups/list/:DeviceName
 * @return string
 */
function GetAvailableBackupsOnDevice()
{
	global $SUDO;
	$deviceName = params('DeviceName');
	$dirs = array();

//	// unmount just in case
//	exec($SUDO . ' umount /mnt/tmp');
//
//	$unusable = CheckIfDeviceIsUsable($deviceName);
//	if ($unusable != '') {
//		array_push($dirs, $unusable);
//		return json($dirs);
//	}
//
//	exec($SUDO . ' mkdir -p /mnt/tmp');
//
//	$fsType = exec($SUDO . ' file -sL /dev/' . $deviceName, $output);
//
//	$mountCmd = '';
//	// Same mount options used in scripts/copy_settings_to_storage.sh
//	if (preg_match('/BTRFS/', $fsType)) {
//		$mountCmd = "mount -t btrfs -o noatime,nodiratime,compress=zstd,nofail /dev/$deviceName /mnt/tmp";
//	} else if ((preg_match('/FAT/', $fsType)) ||
//		(preg_match('/DOS/', $fsType))) {
//		$mountCmd = "mount -t auto -o noatime,nodiratime,exec,nofail,uid=500,gid=500 /dev/$deviceName /mnt/tmp";
//	} else {
//		// Default to ext4
//		$mountCmd = "mount -t ext4 -o noatime,nodiratime,nofail /dev/$deviceName /mnt/tmp";
//	}
//
//	exec($SUDO . ' ' . $mountCmd);
//
//	$dirs = GetAvailableBackupsFromDir('/mnt/tmp/');
//
//	exec($SUDO . ' umount /mnt/tmp');

	$dirs = DriveMountHelper($deviceName, 'GetAvailableBackupsFromDir', array('/mnt/tmp/'));

	return json($dirs);
}

/**
 * Handles mounting the specified device and performing
 *
 * @param $deviceName string The device to be mounted
 * @param $usercallback_function string The function we should call once mounting is completed so we can do something in the directory
 * @param $functionArgs array Order AND Number arguments MUST!! match the arguments required by the supplied user function
 * @return mixed|string
 */
function DriveMountHelper($deviceName, $usercallback_function, $functionArgs = array())
{
	global $SUDO;
	$dirs = array();

	// unmount just in case
	exec($SUDO . ' umount /mnt/tmp');

	$unusable = CheckIfDeviceIsUsable($deviceName);
	if ($unusable != '') {
		array_push($dirs, $unusable);
		return json($dirs);
	}

	exec($SUDO . ' mkdir -p /mnt/tmp');

	$fsType = exec($SUDO . ' file -sL /dev/' . $deviceName, $output);

	$mountCmd = '';
	// Same mount options used in scripts/copy_settings_to_storage.sh
	if (preg_match('/BTRFS/', $fsType)) {
		$mountCmd = "mount -t btrfs -o noatime,nodiratime,compress=zstd,nofail /dev/$deviceName /mnt/tmp";
	} else if ((preg_match('/FAT/', $fsType)) ||
		(preg_match('/DOS/', $fsType))) {
		$mountCmd = "mount -t auto -o noatime,nodiratime,exec,nofail,uid=500,gid=500 /dev/$deviceName /mnt/tmp";
	} else {
		// Default to ext4
		$mountCmd = "mount -t ext4 -o noatime,nodiratime,nofail /dev/$deviceName /mnt/tmp";
	}

	exec($SUDO . ' ' . $mountCmd);

	//Call the function that will do some work in the mounted directory
	$dirs = call_user_func_array($usercallback_function, $functionArgs);

	exec($SUDO . ' umount /mnt/tmp');

	return ($dirs);
}

////
//Functions for JSON Configuration Backup API
/**
 * Returns a list of JSON Configuration backups stored locally or if set the 'JSON Configuration Device'
 * GET /api/backups/configuration/list
 * @return string
 */
function GetAvailableJSONBackups(){
	global $settings;

	$json_config_backup_filenames_on_alternative = array();
	$json_config_backup_filenames_clean = array();

	//Get the full directory path for the type of directory type we're processing
	$dir_jsonbackups = GetDirSetting('JsonBackups');

	//Grabs only the array keys which contain the JSON filenames
	$json_config_backup_filenames = (read_directory_files($dir_jsonbackups, true, true, 'asc'));
	//Process the backup files to extra some info about them
	$json_config_backup_filenames = process_jsonbackup_file_data_helper($json_config_backup_filenames, $dir_jsonbackups);

	//See what backups are stored on the selected storage device if it's value is set
	if (isset($settings['jsonConfigBackupUSBLocation']) && !empty($settings['jsonConfigBackupUSBLocation'])) {
		$dir_jsonbackupsalternate = GetDirSetting('JsonBackupsAlternate');

		//$settings['jsonConfigBackupUSBLocation'] is the selected alternative drive to stop backups to
		$json_config_backup_filenames_on_alternative = DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'read_directory_files', array($dir_jsonbackupsalternate, true, true, 'asc'));
		//Process the backup files to extra some info about them
		$json_config_backup_filenames_on_alternative = process_jsonbackup_file_data_helper($json_config_backup_filenames_on_alternative, $dir_jsonbackupsalternate);
	}
	//Merge the results together, if t he same backup name exists in the alternative backup location it will overwrite the record from the local cnfig directory
	$json_config_backup_filenames_clean = array_merge($json_config_backup_filenames, $json_config_backup_filenames_on_alternative);

	//Once merged - do another sort on the entries but sort on the backup_time_unix value
	usort($json_config_backup_filenames_clean, "sort_backup_time_asc");

	return json($json_config_backup_filenames_clean);
}

/**
 * Helper function to extact some metadata out of the backup files
 * @param $json_config_backup_Data
 * @param $source_directory
 * @return array
 */
function process_jsonbackup_file_data_helper($json_config_backup_Data, $source_directory)
{
	global $settings;

	$json_config_backup_filenames_clean = array();

	//process each of the backups and read out the backup comment, and work out the date it was created
	foreach ($json_config_backup_Data as $backup_filename => $backup_data) {
		$backup_data_comment = '';
		$backup_alternative = false;
		$backup_filepath = $source_directory;
		//Check to see if the source direct is the same as the default or not, if it is't then the the directory is the alternative backup directory (USB or something)
		if ($source_directory !== GetDirSetting('JsonBackups')) {
			$backup_alternative = true;
		}

		//cleanup the filename so it can be used as as a ID
		$backup_filename_clean = trim(str_replace('.json', '', $backup_filename));

		$decoded_backup_data = json_decode($backup_data[0], true);
		if (array_key_exists('backup_comment', $decoded_backup_data)) {
			$backup_data_comment = $decoded_backup_data['backup_comment'];
		}

		//Locate the last underscore, this appears before the date/time in the filename
		$backup_date_time_pos = strrpos($backup_filename_clean, '_');
		//Extract everything between this occurrence and the end of the string, this will be the date time in full
		$backup_date_time_str = substr($backup_filename_clean, $backup_date_time_pos + 1);
		//Date time created in this format date("YmdHis"), output it in this format date('D M d H:i:s T Y') so it's more human-readable
		$backup_date_time = DateTime::createFromFormat("YmdHis", $backup_date_time_str)->format('D M d H:i:s Y');
		$backup_date_time_unix = DateTime::createFromFormat("YmdHis", $backup_date_time_str)->format('U');

		$json_config_backup_filenames_clean[$backup_filename_clean] = array('backup_alternative_location' => $backup_alternative,
			'backup_filedirectory' => $backup_filepath,
			'backup_filename' => $backup_filename,
			'backup_comment' => $backup_data_comment,
			'backup_time' => $backup_date_time,
			'backup_time_unix' => $backup_date_time_unix
		);
	}

	return $json_config_backup_filenames_clean;
}

/**
 * Generates a JSON Configuration backup containing all config data
 * GET /api/backups/configuration/
 * @return string
 */
function MakeJSONBackup()
{
	global $settings, $skipJSsettings,
		   $mediaDirectory, $eventDirectory, $playlistDirectory, $scriptDirectory, $settingsFile,
		   $skipHTMLCodeOutput,
		   $system_config_areas, $known_json_config_files, $known_ini_config_files,
		   $backup_errors, $backup_error_string,
		   $sensitive_data, $protectSensitiveData,
		   $fpp_backup_version, $fpp_backup_prompt_download,
		   $fpp_backup_max_age, $fpp_backup_min_number_kept,
		   $fpp_backup_location, $fpp_backup_location_alternate_drive;

	//Get the backup comment out of the post data
	$backup_comment = file_get_contents('php://input');

	$toUSBResult = false;

	//Include the FPP backup script
	require_once "../backup.php";

	//Create the backup and return the status
	$backup_creation_status = performBackup('all', false, $backup_comment);

	if (array_key_exists('success', $backup_creation_status) && $backup_creation_status['success'] == true) {
		$toUSBResult = DoJsonBackupToUSB();
		if ($toUSBResult){
			$backup_creation_status['copied_to_usb'] = true;
		}else{
			$backup_creation_status['copied_to_usb'] = false;
		}
	} else {
		/* Handle error */
		error_log('MakeJSONBackup: Something went wrong trying to call backups API to make a settings backup. (' . json_encode(['result' => $backup_creation_status]));
		$backup_creation_status['copied_to_usb'] = false;
	}

	//Return the result which contains the success of the backup and the path which it was written to;
	return json($backup_creation_status);
}

/**
 * Returns a list of JSON Configuration files on a specified device (i.e a alternate storage device)
 * Reuses some above functions to mount check and mount the device
 * Overrides the default behaviour of GetAvailableJSONBackups, so we can check specific devices if needed
 * GET /api/backups/configuration/list/:DeviceName
 * @return string
 */
function GetAvailableJSONBackupsOnDevice(){
	global $SUDO, $settings;
	$deviceName = params('DeviceName');

	//Get the full directory path for the type of directory type we're processing
	$dir_jsonbackupsalternate = GetDirSetting('JsonBackupsAlternate');

	$json_config_backup_filenames = DriveMountHelper($deviceName, 'read_directory_files', array($dir_jsonbackupsalternate, false, true));

	//do some additional massaging of the data
	$json_config_backup_filenames = array_keys($json_config_backup_filenames);

	return json($json_config_backup_filenames);
}

/**
 * Restored the specified JSON Backup
 * POST /api/backups/configuration/restore/:Directory/:BackupFilename
 * @return string
 */
function RestoreJsonBackup(){
	global $SUDO, $settings, $skipJSsettings,
		   $mediaDirectory,$eventDirectory, $playlistDirectory, $scriptDirectory, $settingsFile, $scheduleFile, $fppDir,
		   $skipHTMLCodeOutput,
		   $system_config_areas, $known_json_config_files, $known_ini_config_files,
		   $backup_errors,$backup_error_string,
		   $keepMasterSlaveSettings, $keepNetworkSettings, $uploadData_IsProtected, $settings_restored,
		   $network_settings_restored, $network_settings_restored_post_apply, $network_settings_restored_applied_ips,
		   $sensitive_data, $protectSensitiveData,
		   $fpp_backup_version, $fpp_backup_prompt_download,
		   $fpp_backup_max_age, $fpp_backup_min_number_kept,
		   $fpp_backup_location, $fpp_backup_location_alternate_drive,
		   $args;

	//Get the backup comment out of the post data
	$area_to_restore = file_get_contents('php://input');
	//Directory is either JsonBackups (matching $settings['configDirectory'] . "/backups/")
	// or JsonBackupsAlternate (using the device set in jsonConfigBackupUSBLocation, it's mounted to /mnt/tmp/, then backups are sourced from /mnt/tmp/config/backups)
	$restore_from_directory = params('Directory');
	//Filename of the backup to restore
	$restore_from_filename = params('BackupFilename');

	//Get the full directory path for the type of directory type we're processing
	$dir = GetDirSetting($restore_from_directory);
	$fullPath = "$dir/$restore_from_filename";

	$file_contents_decoded = null;
	$restore_status = array('success' => 'Failed', 'message' => '');

	//check that the area supplied is not empty, if so then assume we're restoring all araeas
	if (empty($area_to_restore)) {
		$area_to_restore = "all";
	}

	//Make sure the directory and filename are supplied
	if (isset($restore_from_directory) && isset($restore_from_filename)) {
		//Include the FPP backup script
		require_once "../backup.php";

		//Restore the backup and return the status
		//Read in the backup file and json_decode the contents
		if (strtolower($restore_from_directory) === 'jsonbackups') {
			//get load the file from the config directory
			$file_contents = file_get_contents($fullPath);

			if ($file_contents !== FALSE) {
				//decode back into an array
				$file_contents_decoded = json_decode($file_contents, true);
			} else {
				//file_get_contents will return false if it couldn't read the file so
				$restore_status['success'] = "Ok";
				$restore_status['message'] = 'Backup File ' . $fullPath . ' could not be read.';
			}
		} else if ((strtolower($restore_from_directory) === 'jsonbackupsalternate')) {
			if (isset($settings['jsonConfigBackupUSBLocation']) && !empty($settings['jsonConfigBackupUSBLocation'])) {
				//Mount and read the json backup from the jsonConfigBackupUSBLocation location
				$file_contents = DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'file_get_contents', array($fullPath));

				//If the file was read ok, $file_contents will be false if there was issue reading the file
				if ($file_contents !== FALSE) {
					//decode back into an array
					$file_contents_decoded = json_decode($file_contents, true);
				} else {
					//file_get_contents will return false if it couldn't read the file so
					$restore_status['success'] = "Ok";
					$restore_status['message'] = 'Backup File ' . $fullPath . ' could not be read.';
				}
			}
		}

		//Perform the restore
		$restore_data = doRestore($area_to_restore, $file_contents_decoded, $restore_from_filename, true, true, 'api');

		$restore_status['success'] = $restore_data['success'];
		$restore_status['message'] = $restore_data['message'];
	} else {
		$restore_status['success'] = "Error";
		$restore_status['message'] = 'Supplied Directory or Filename is invalid';
	}

	//Return the result which contains the success of the backup and the path which it was written to;
	return json($restore_status);
}

/**
 * Downloads a specific JSON Backup
 * Extracted from file.php GetFile() and modified to deal with mounting the selected USB drive to delete the JSON backup stored on it
 * @return string
 */
function DownloadJsonBackup(){
	global $settings;

	$status = "File not found";
	$dirName = params("Directory");
	$fileName = params("BackupFilename");

	//Get the full directory path for the type of directory type we're processing
	$dir = GetDirSetting($dirName);
	$fullPath = "$dir/$fileName";

	if (strtolower($dirName) == "jsonbackups") {
		//Check if the file exists, we can use file_exists directly on the path for normal jsonhackups as it's going to be in the /home/fpp directory
		$fileExists = file_exists($fullPath);

		if ($fileExists) {
			//Content type will always be json so see the header
			header("Content-Type: application/json");
			header("Content-Disposition: attachment; filename=\"" . basename($fileName) . "\"");

			//Empty the output buffers
			ob_clean();
			flush();

			readfile($fullPath);
		} else {
			$status = "File Not Found";
			return json(array("status" => $status, "file" => $fileName, "dir" => $dirName));
		}
	} elseif (strtolower($dirName) == "jsonbackupsalternate") {
		//Use our DriveMountHelper to mount the specified USB drive and check if the file exists
		$fileExists = DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'file_exists', array($fullPath));

		if ($fileExists) {
			//Content type will always be json so see the header
			header("Content-Type: application/json");
			header("Content-Disposition: attachment; filename=\"" . basename($fileName) . "\"");

			//Empty the output buffers
			ob_clean();
			flush();

			DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'readfile', array($fullPath));
		} else {
			$status = "File Not Found";
			return json(array("status" => $status, "file" => $fileName, "dir" => $dirName));
		}
	}
}

/**
 * Deletes a specific JSON Backup
 * Extracted from file.php DeleteFile() and modified to deal with mounting the selected USB drive to delete the JSON backup stored on it
 * @return string
 */
function DeleteJsonBackup(){
	global $settings;

	$status = "File not found";
	$dirName = params("Directory");
	$fileName = params("BackupFilename");

	//Get the full directory path for the type of directory type we're processing
	$dir = GetDirSetting($dirName);
	$fullPath = "$dir/$fileName";

	$fileDeleted = false;
	$fileExists_alt = false;
	$dir_alt = $fullPath_alt = "";

	if (strtolower($dirName) == "jsonbackups") {
		//Check if the file exists, we can use file_exists directly on the path for normal jsonhackups as it's going to be in the /home/fpp/media directory
		$fileExists = file_exists($fullPath);

	} elseif (strtolower($dirName) == "jsonbackupsalternate") {
		//Use our DriveMountHelper to mount the specified USB drive and check if the file exists

		//Mount the drive and see if the file exists
		$fileExists = DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'file_exists', array($fullPath));
	}


	if ($dir == "") {
		$status = "Invalid Directory";
	} else if (!($fileExists)) {
		$status = "File Not Found";
	} else {

		if (strtolower($dirName) == "jsonbackups") {
			//Check if the file exists, we can use unlink directly on the path for normal jsonhackups as it's going to be in the /home/fpp directory
			$fileDeleted = unlink($fullPath);
		} elseif (strtolower($dirName) == "jsonbackupsalternate") {
			//Use our DriveMountHelper to mount the specified USB drive and check if the file exists
			// Mount the drive and delete the file
			$fileDeleted = DriveMountHelper($settings['jsonConfigBackupUSBLocation'], 'unlink', array($fullPath));

			//ALSO check if the file exists in the /home/fpp/media location, because the backup we're deleting could have been copied to USB from location
			//and we want to delete it in both
			$dir_alt = GetDirSetting('JsonBackups');
			$fullPath_alt = "$dir_alt/$fileName";
			$fileExists_alt = file_exists($fullPath_alt);
			//If the file exists in /home/media then delete it also as we want to delete copies of the file in both locations
			//it will exist in both locations if a USB device is selected to copy backups to
			if($fileExists_alt == true){
				//delete it
				unlink($fullPath_alt);
			}
		}

		if ($fileDeleted) {
			$status = "OK";
		} else {
			$status = "Unable to delete file";
		}
	}

	return json(array("status" => $status, "file" => $fileName, "dir" => $dirName));
}

/**
 * Callback function to sort backups by their backup time
 * @param $a
 * @param $b
 * @return mixed
 */
function sort_backup_time_asc($a, $b)
{
	return $b['backup_time_unix'] - $a['backup_time_unix'];
}
?>
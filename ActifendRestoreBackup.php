<?php
// require(ABSPATH . "wp-config.php");
require_once(trailingslashit( ADMIN_DIR ) . 'includes/import.php');

/**
* ActifendRestoreBackup
*
* This is class Restore the wrodpress backup file from azure storage and restore those file to your server.
*
*
*/
class ActifendRestoreBackup {
    var $utiObj;
    var $fiObj;
    var $req_id;
    var $addlMsg;
    var $type;
    var $backup_type;
    var $backup_time;
    var $params_array;
    var $alt_path;
    var $full_backup_name;
    var $full_backup_size = 0;
    var $inc_backup_name;
    var $inc_backup_size = 0;
    var $quarantine      = 'NO';
    var $wpcontent2skip  = array(
        'actifend_tmp',
        'plugins',
        'themes',
        'wflogs',
        'afend_quarantine'
    );

    public function __construct() {
        $this->utiObj = new Utility;
        $this->utiObj->initFileSystem();
        global $wp_filesystem;
        $this->fiObj  = new ActifendFileIntegrity( $wp_filesystem->abspath() );
        $this->alt_path = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'full_wp_backup';
    }

    /**
     * Zip extension check
     */
    public function zip_check() {
        return extension_loaded('zip');
    //    if (extension_loaded('zip')) {
    //        return True;
    //    }
    //    return false;
    }

    /**
     * actifend_restore_process
     * After completion of restore to Actifend-cloud status is to be sent
     * Actifend BE
     */
    public function actifend_restore_process() {
        // get asset id
        $result = $this->utiObj->getActifendInfo();
        if (isset($result->asset_id) && !empty($result->asset_id)) {
            $assetid = $result->asset_id;
        } else {
            debug_log("No ASSET ID assigned .... ");
            return 'NO_ASSETID';
        }
        // Execute Backup process
        $this->actifend_check_get_backup_file($assetid);
        $statusOpt = get_option('ActifendRestoreStatus');

        // check if no requests are pending exit without doing anything
        if (strtoupper($statusOpt) == 'NONE') return;

        if ($statusOpt == 'ready2restore') {
            // suspend the crons until restore is completed
            $this->utiObj->reset_actifend_crons('remove');
            $this->actifend_restore_from_backup_file($assetid, $this->backup_type);
            $statusOpt = get_option('ActifendRestoreStatus');
        }

        if ( $statusOpt == 'ready2recover' ) {
            // suspend the crons until restore is completed
            $this->utiObj->reset_actifend_crons('remove');
            $this->actifend_fi_recover();
            $statusOpt = get_option('ActifendRestoreStatus');
        }

        // Send the status to Actifend BE
        if ($statusOpt == 'complete') {
            // reinitiate the crons
            $this->utiObj->reset_actifend_crons( 'add' );
            $restoreStatus = 'done';
            $statusOpt = 'Restore of Backup from Actifend-cloud completed.';
        } else {
            // reinitiate the crons
            $this->utiObj->reset_actifend_crons( 'add' );
            $restoreStatus = 'failed';
            $statusOpt = $this->addlMsg;
        }

        if (get_option('FilePermissionProblem') != 0) {
            $statusOpt .= " Insufficient file privileges to restore fully. Additional manual restore needed.";
        }

        $actifend_array = array('status' => $restoreStatus,
                                'zip_enabled' => $this->zip_check(),
                                'reason' => $statusOpt,
                                'reqid' => $this->req_id);

        $actifend_params = json_encode($actifend_array);
        // send status done status by curl usind the PATCH method when backup send to azure successfully.
        $actifend_url = ACTIFEND_BACKUP_END_POINT . $assetid . "/wprestore";
        $this->utiObj->actifend_postViaCurl($actifend_url, $actifend_params, "PATCH");
        debug_log("Status update sent to Actifend BE.");
    }


    /**
     * get_azure_file_name
     *
     * This function is used to get the file name and other attributes of the backup file
     * stored in azure storage whcih are require to get the file from the azure stroage.
     * @param array $params_array Array contain list parameters required to access the azure storage. e.g (token, account name and asset_id etc)
     * @return array $file_attribute Array contain the file name and size.
     */
    private function get_azure_file_name($params_array) {
        try {
            $assetid      = $params_array['assetid'];
            $account_name = $params_array['account_name'];
            $share_name   = $params_array['share_name'];
            $sas_token    = $params_array['sas_token'];
            $subfolder    = $params_array['sub_folder']; //sub_folder

            // $uri = 'https://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "/" . $subfolder . "?" . $sas_token . "&restype=directory&comp=list";
            $uri = 'https://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "/" . $subfolder . "/" . BACKUP_FILE . "?" . $sas_token;

            $today    = gmdate("D, d M Y G:i:s T");
            $az_headers = array("x-ms-date" => $today,
                                "x-ms-version" => "2015-04-05");
            $response = wp_remote_head($uri, array('headers' => $az_headers));
            $fsize = $response['headers']['content-length'];
            debug_log("File size: " . $fsize);
            $file_attribute = array('fname' => BACKUP_FILE, 'fsize' => $fsize);
            return $file_attribute;

        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
    * actifend_update_restore_status
    *
    * Updates the status of the restore process
    * @param new status to be set
    */
    public function actifend_update_restore_status( $status ) {
        $status_lst = array (
            'failed'        => 'Restore of Backup Failed.',
            'initiated'     => 'Restore process initiated',
            'complete'      => 'Restore successfully completed.',
            'ready2restore' => 'Backup file is ready to be restored.',
            'ready2recover' => 'Ready to recover from file integrity problem.',
            'error'         => 'Unexpected error during restore.',
            'unzip_error'   => 'Backup file unzip error. Please contact Actifend support.',
            'no_backup'     => 'Backup files does not exist.',
            'none'          => 'No restore request pending.',
            'download_error'=> 'Error while downloading backup file ' . BACKUP_FILE
            );
        if ( in_array( $status, array_keys( $status_lst )) ) {
            update_option('ActifendRestoreStatus', $status);
            $this->addlMsg = $status_lst[$status];
            debug_log($this->addlMsg);
        }
    }

    /**
     * actifend_check_get_backup_file
     *
     * This function is used to get the backup file from actifend cloud storage
     * and extracts the files from the compressed backup file to BACKUP_DIR
     * @param asset_id
     */
    public function actifend_check_get_backup_file($asset_id) {
        try {
            global $wp_filesystem;

            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';
            $uploads_dir = trailingslashit( $content_path ) . 'uploads';

            debug_log("Check for restore requests initiated ...");
            // check if any restore requests are waiting to be executed
            $response  = $this->utiObj->getViaCurl(ACTIFEND_RESTORE_END_POINT, $asset_id, 'wprestore');
            debug_log("Response: " . $response['ResponseCode']);

            if (!empty($response) && isset($response)) {
                if (($response['Message'] == 'success') && ($response['ResponseCode'] == '2000')) {
                    // set the restore status to initiated
                    $this->actifend_update_restore_status( 'initiated' );

                    $Result = $response['Result'];
                    $this->backup_type  = $Result['backup_type'];
                    debug_log("Backup type: " . $this->backup_type);
                    $this->params_array = array(
                        'assetid'        => $Result['asset_id'],
                        'account_name'   => $Result['stor_name'],
                        'share_name'     => $Result['share_name'],
                        'sub_folder'     => $Result['backup_name'],
                        'sas_token'      => $Result['sas_token']
                        );

                    $this->req_id = $Result['_id'];
                    $this->type   = $Result['type'];
                    // Backup file full path
                    $backup_file = trailingslashit( $content_path ) . BACKUP_FILE;

                    $this->quarantine = $Result['quarantine'];
                    debug_log("Quarantine: " . $this->quarantine);

                    $this->backup_time = $Result['backup_time'];
                    $file_attribute = $this->get_azure_file_name($this->params_array);

                    debug_log("Backup name: " . $this->params_array['sub_folder']);
                    # v1.4.5 Get the full / incremental backup file details also
                    if ( $this->backup_type == 'full' ) {
                        $this->inc_backup_name = $Result['inc_backup_name'];
                        debug_log("Incremental backup name: " . $this->inc_backup_name);
                        if ( $this->inc_backup_name != 'NONE' ) {
                            $this->inc_backup_size = $Result['inc_backup_size'];
                        }
                        debug_log("Incremental backup size: " . $this->inc_backup_size);
                    } else {
                        $this->full_backup_name = $Result['full_backup_name'];
                        debug_log("Full backup name: " . $this->full_backup_name);
                        $inc_bkp_params = array(
                            'assetid'        => $Result['asset_id'],
                            'account_name'   => $Result['stor_name'],
                            'share_name'     => $Result['share_name'],
                            'sub_folder'     => $this->full_backup_name,
                            'sas_token'      => $Result['sas_token']
                        );
                        $inc_file_attrib = $this->get_azure_file_name($inc_bkp_params);
                        // Get the full backup file from Actifend-cloud storage
                        $ret = $this->actifend_get_backup_file_from_storage($inc_bkp_params, $inc_file_attrib);
                        if( false !== $ret ) {
                            $full_bkp_path = $this->alt_path;
                            $extDone = unzip_file( $backup_file, $full_bkp_path );
                            if ( $extDone ) {
                                debug_log('Successfully unzipped ' . BACKUP_FILE);
                                $wp_filesystem->delete( BACKUP_FILE );
                            } else {
                                debug_log('There was an error unzipping ' . BACKUP_FILE);
                                $this->actifend_update_restore_status( 'unzip_error' );
                                return;
                            }
                        }
                    }

                    if ($wp_filesystem->exists( $backup_file )) {
                        $wp_filesystem->delete( $backup_file );
                    }

                    if ($wp_filesystem->exists( $backup_path )) {
                        $this->utiObj->actifend_rrmdir( $backup_path );
                    }
                    // Get the file from Actifend-cloud storage
                    $this->actifend_get_backup_file_from_storage($this->params_array, $file_attribute);

                    // Unzip the backup file
                    if($wp_filesystem->exists( $backup_file )) {
                        $wp_filesystem->mkdir( $backup_path );
                        $extDone = unzip_file( $backup_file, $backup_path );
                        if ( $extDone ) {
                            debug_log('Successfully unzipped ' . BACKUP_FILE);
                        } else {
                            debug_log('There was an error unzipping ' . BACKUP_FILE);
                            $this->actifend_update_restore_status( 'unzip_error' );
                            return;
                        }
                        // delete backup zip file
                        $wp_filesystem->delete(trailingslashit( $content_path ) . BACKUP_FILE);
                        // if uploads folder is NOT in the backup do not delete original folder
                        if (! $wp_filesystem->exists( $uploads_dir )) {
                            array_push($this->wpcontent2skip, 'uploads');
                        }
                        // update the status
                        if ( $this->type == 'file_integrity') {
                            $this->actifend_update_restore_status( 'ready2recover' );
                        }else {
                            $this->actifend_update_restore_status( 'ready2restore' );
                        }
                        return;
                    } else {
                        $this->actifend_update_restore_status( 'no_backup' );
                        return;
                    }
                }else {
                    $this->actifend_update_restore_status( 'none' );
                    return;
                }
            }
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_fi_recover
     *
     * Recover from File integrity issue
     * Added in v1.5
     */
    public function actifend_fi_recover() {
        try {
            debug_log("Recovery Started ... ");

            $disc         = get_option('actifend_fi_disc', array());
            debug_log("Discrepancies: ");
            debug_log( $disc );
            if (empty( $disc )) return;

            global $wp_filesystem;
            $abspath      = $wp_filesystem->abspath();
            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path  = trailingslashit( $content_path ) . 'wp_backup';
            $q_path       = trailingslashit( $content_path ) . 'afend_quarantine';

            // Quaratine the files that created discrepancy
            if ( array_key_exists( 'add', $disc ) ) {
                $files_added = $disc['add'];
                foreach ($files_added as $file_added=>$type ) {
                    $filepath = trailingslashit( $abspath ) . $file_added;
                    # delete the file or quarantine it
                    if ( $this->quarantine == 'YES' ) {
                        $wp_filesystem->delete( $filepath );
                        debug_log("Added file removed: $filepath");
                    }else {
                        $q_filepath = trailingslashit( $q_path ) . $file_added;
                        $wp_filesystem->move( $filepath, $q_filepath );
                        debug_log("File moved to quarantine: $filepath");
                    }
                }
            }

            if ( array_key_exists( 'del', $disc ) ) {
                $files_deleted = $disc['del'];
                foreach ($files_deleted as $file_deleted=>$type) {
                    $filepath = trailingslashit( $abspath ) . $file_deleted;
                    # Add the file; get the file from backup
                    if ( $type == 'file' ) {
                        if ( $this->actifend_recover_file( $file_deleted, $backup_path, true ) ) {
                            debug_log("Deleted file recovered: $filepath");
                        } else {
                            $this->actifend_update_restore_status( 'failed' );
                            return false;
                        }
                    }
                }
            }

            if ( array_key_exists( 'alt', $disc ) ) {
                $files_altered = $disc['alt'];
                foreach ($files_altered as $file_altered=>$type) {
                    $filepath = trailingslashit( $abspath ) . $file_altered;
                    debug_log("Altered File Path: $filepath");
                    // get the file from backup and replace

                    if ( $type == 'file' ) {
                        if ( $this->quarantine == 'YES' ) {
                            $q_filepath = trailingslashit( $q_path ) . $file_altered;
                            if (! $wp_filesystem->exists( dirname( $q_filepath ) ))
                                wp_mkdir_p( dirname( $q_filepath ) );
                            $wp_filesystem->move( $filepath, $q_filepath);
                        }

                        if ( $this->actifend_recover_file( $file_altered, $backup_path, true ) ) {
                            debug_log("Altered file recovered: $filepath");
                        } else {
                            $this->actifend_update_restore_status( 'failed' );
                            return false;
                        }

                    }
                }
            }
            // take a new baseline of the file structure
            $this->fiObj->createBaseline();
            //  Update status
            $this->actifend_update_restore_status( 'complete' );
            delete_option( 'actifend_fi_disc' );
            // delete the backup folder
            $wp_filesystem->delete( $backup_path, true );
            debug_log("Recovery Complete.");
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'failed' );
            return false;
        }
    }

    /**
     * actifend_recover_file
     * Recover a file that has been modified or deleted, from a backup folder
     * @param $bkp_folder  -> backup location to look for the file
     * @param $alt takes alternate backup location to look for the file if
     * the file is not in the $bkp_folder
     * Added in v1.5
     */
    public function actifend_recover_file( $fileName, $bkp_folder, $alt=false) {
        global $wp_filesystem;

        $path_in_backup = trailingslashit( $bkp_folder ) . $fileName;
        $filepath = trailingslashit( $wp_filesystem->abspath() ) . $fileName;
        if ($wp_filesystem->exists( $path_in_backup )) {
            $wp_filesystem->copy( $path_in_backup, $filepath, true );
            return true;
        }else {
            debug_log("$fileName does not exist in the last backup. ");
            # Get the last full back, if the earline is a inc backup
            if ( $this->backup_type == 'full' ) {
                return false;
            } else {
                // look for the file in the alternate location
                if ( $alt ) {
                    $full_bkp_path  = $this->alt_path;
                    # Check if the alternate location has any files
                    $fileCount = count(list_files( $full_bkp_path, 1 ));
                    if ($fileCount == 0) {
                        debug_log("Alternate location $full_bkp_path is empty!");
                        return false;
                    }

                    $path_in_backup = trailingslashit( $full_bkp_path ) . $fileName;
                    if ($wp_filesystem->exists( $path_in_backup )) {
                        $wp_filesystem->copy( $path_in_backup, $filepath, true );
                        return true;
                    }else {
                        debug_log("$fileName does not exist in the full backup also! ");
                        return false;
                    }
                }
            }
        }
    }

    /**
     * actifend_restore_from_backup_file
     *
     * Once backup file is retrieved and unzipped, this function restores the files from the backup file
     * @param asset_id
     * @param backup_type
     */
    public function actifend_restore_from_backup_file($asset_id, $backup_type) {
        try {
            global $wp_filesystem;

            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            // restore database
            $this->actifend_restore_database();
            debug_log("WordPress database restored!");

            if (strcmp($backup_type, 'full') === 0) {
                // Exit here if not enough privileges
                $privCheck = $this->utiObj->check_file_privileges();
                if ($privCheck === 1) {
                    debug_log("Insufficient privileges to restore fully.");
                    debug_log("Exiting after unzipping the backup file to wp-content/wp_backup folder!");
                } else {
                    $ret = $this->actifend_restore_core();
                    if (! $ret ) {
                        $this->actifend_update_restore_status( 'failed' );
                        return false;
                    }
                    debug_log("Restore of full backup file complete!");
                    // delete the backup folder
                    $this->utiObj->actifend_rrmdir( $backup_path );

                    // restore incremental backup if it is available
                    // Get the file from Actifend-cloud storage
                    if ( strtoupper( $this->inc_backup_name ) != 'NONE') {
                        $this->params_array['sub_folder'] = $this->inc_backup_name;
                        // $inc_file_attributes = $this->get_azure_file_name($this->params_array);
                        // $this->inc_backup_size = $inc_file_attributes['fsize'];

                        $inc_file_details = array('fname' => BACKUP_FILE,
                                                'fsize' => $this->inc_backup_size);
                        debug_log("Starting restore of incremental backup...");
                        $this->actifend_get_backup_file_from_storage($this->params_array, $inc_file_details);

                        // Unzip the backup file
                        if($wp_filesystem->exists(trailingslashit( $content_path ) . BACKUP_FILE)) {
                            $wp_filesystem->mkdir( $backup_path );
                            $extDone = unzip_file(trailingslashit( $content_path ) . BACKUP_FILE, $backup_path);
                            if ( $extDone ) {
                                debug_log('Successfully unzipped ' . BACKUP_FILE);
                                $incBackup = true;
                            } else {
                                debug_log('There was an error unzipping ' . BACKUP_FILE);
                                $this->actifend_update_restore_status( 'unzip_error' );
                                return;
                            }

                            if ( $incBackup ) {
                                // restore database
                                $this->actifend_restore_database();
                                debug_log("WordPress database restored from incremental backup!");
                                // incremental restore after restore of full backup
                                $ret = $this->actifend_restore_incremental_backup( true );
                                if ( $ret ) {
                                    debug_log("Incremental Backup restored!");
                                } else {
                                    debug_log("Restore of incremental backup failed.");
                                    $this->actifend_update_restore_status( 'failed' );
                                    return false;
                                }
                                // delete the backup folder
                                $wp_filesystem->delete( $backup_path, true );                                // $this->utiObj->actifend_rrmdir( $backup_path );
                            }
                        }
                    }
                }
            }

            if ( strcmp($backup_type, 'content') === 0 ) {
                debug_log("Site partial restore initiated...");
                // Restore from the incremental backup
                $ret = $this->actifend_restore_incremental_backup( false );
                if ( $ret ) {
                    debug_log("Incremental Backup restored!");
                } else {
                    debug_log("Restore of incremental backup failed.");
                    $this->actifend_update_restore_status( 'failed' );
                    return false;
                }
                // delete the backup folder
                $wp_filesystem->delete( $backup_path, true );
            }

            // take a new baseline of the file structure
            $this->fiObj->createBaseline();

            $this->actifend_update_restore_status( 'complete' );
            debug_log("actifend_total_restore done!");

        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'failed' );
            return false;
        }
    }

    /**
     * actifend_quarantine
     * added in v1.4.5
     * quarantine the existing folders before they are restored
     */
    public function actifend_quarantine( $source ) {
        if (strcmp( $this->quarantine, 'NO' ) === 0) {
            debug_log("Skipping quarantine of $source ... ");
            return;
        }
        global $wp_filesystem;

        $qdir = trailingslashit( WP_CONTENT_DIR )
                . trailingslashit( 'afend_quarantine' )
                . date("Y-m-d_H_i");
        // recursive folder creation
        wp_mkdir_p( $qdir );
        $prefix = trailingslashit( WP_CONTENT_DIR );
        $skip_lst = array( $prefix . 'afend_quarantine',
                           $prefix . 'actifend_tmp',
                           $prefix . 'wp_backup',
                           $prefix . 'upgrade',
                           $prefix . 'uploads',
                           $prefix . 'logs',
                           $prefix . 'wflogs' );
        $ret = copy_dir($source, $qdir, $skip_lst);
        if (is_wp_error( $ret )) {
            $error_msg = $ret->get_error_message();
            debug_log("Quarantine Error: $error_msg");
        } else {
            debug_log("$source successfully quarantined.");
        }
    }

    /**
     * actifend_get_backup_file_from_storage
     *
     * This function is used to get the latest backup file from the azure storage and
     * save the file in wp-content directory in zip format.
     *
     * @param array $params_array This array contain the token and other information that
     * required to access the storage account.
     * @param array $file_attribute This array contain the latest file file attribute
     * that required to downlod the backup file.
     */
    private function actifend_get_backup_file_from_storage($params_array, $file_attribute) {
        try {
            global $wp_filesystem;

            debug_log("Executing actifend_get_backup_file_from_storage function ...");
            $backup_file = trailingslashit( $wp_filesystem->wp_content_dir() ) . BACKUP_FILE;

            $fp = fopen( $backup_file, 'wb' );
            $fsize     = $file_attribute['fsize'];
            $fname     = $file_attribute['fname'];
            debug_log("Filename to restore: " . $fname);
            $subfolder = $params_array['sub_folder'];
            debug_log("Backup to restore: " . $subfolder);
            $max_range = 3 * 1024 * 1024;
            $assetid      = $params_array['assetid'];
            $account_name = $params_array['account_name'];
            $share_name   = $params_array['share_name'];
            $sas_token    = $params_array['sas_token'];
            $today = gmdate("D, d M Y G:i:s T");
            $uri   = 'https://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "/" . $subfolder . "/" . $fname . "?" . $sas_token;

            if ($fsize > $max_range) {
               $heads = array("x-ms-range"   => 'bytes=0-' . ($max_range - 1),
                               "x-ms-version" => "2015-04-05",
                               "x-ms-date"    => $today);
                $response = wp_remote_get($uri, array('headers' => $heads));
                $content = wp_remote_retrieve_body($response);
            } else {
                $heads = array("x-ms-range"   => 'bytes=0-' . ($fsize - 1),
                               "x-ms-version" => "2015-04-05",
                               "x-ms-date"    => $today);
                $response = wp_remote_get($uri, array('headers' => $heads));
                $content = wp_remote_retrieve_body($response);
            }
            $fw = fwrite($fp, $content);
            $remain_size = $fsize - $max_range;
            $start_range = $max_range;
            while ($remain_size > $max_range) {
                $end_range = $start_range + $max_range;
                $heads = array("x-ms-range"   => 'bytes=' . $start_range . '-' . ($end_range - 1),
                               "x-ms-version" => "2015-04-05",
                               "x-ms-date"    => $today);
                $response = wp_remote_get($uri, array('headers' => $heads));
                $content = wp_remote_retrieve_body($response);
                $fw = fwrite($fp, $content);
                $start_range = $end_range;
                $remain_size = $fsize - $end_range;
                if (($remain_size < $max_range) && ($fsize > $end_range)) {
                    $heads = array("x-ms-range"   => 'bytes=' . ($end_range) . '-' . ($fsize - 1),
                                   "x-ms-version" => "2015-04-05",
                                   "x-ms-date"    => $today);
                    $response = wp_remote_get($uri, array('headers' => $heads));
                    $content = wp_remote_retrieve_body($response);
                    $fw = fwrite($fp, $content);
                }
            }
            fclose($fp);
            if (! $wp_filesystem->exists( $backup_file )) {
                $errors = error_get_last();
                debug_log($errors['message'] . " error while writing file wp_backup.zip");
                $this->actifend_update_restore_status( 'download_error' );
                return false;
            }
            if (! $wp_filesystem->chmod( $backup_file, 0777 )) {
                debug_log("Could not change the permissions on wp_backup.zip file.");
            }
            debug_log(BACKUP_FILE . " retrieved from Actifend-cloud and written to local disk.");
            debug_log("Local file size: " . filesize( $backup_file ) . " bytes");
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_restore_core
     *
     * This method is used to replace the wp-content ,wp-admin, wp-include and other files
     * with the wordpress backup files that we downloaded from the azure storage.
     * @param null
     * @return null
     */
    private function actifend_restore_core() {
        try {
            global $wp_filesystem;

            $content_path = $wp_filesystem->wp_content_dir();
            $abspath = $wp_filesystem->abspath();
            $admin_path = trailingslashit( $abspath ) . 'wp-admin';
            $includes_path = trailingslashit( $abspath ) . 'wp-includes';
            $plugins_dir = $wp_filesystem->wp_plugins_dir();
            $themes_dir = $wp_filesystem->wp_themes_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';
            $uploads_dir = trailingslashit( $content_path ) . 'uploads';

            $locations = array( trailingslashit( $backup_path ) . 'wp-content'  => $content_path,
                                trailingslashit( $backup_path ) . 'wp-admin'    => $admin_path,
                                trailingslashit( $backup_path ) . 'wp-includes' => $includes_path,
                                $backup_path                                    => $abspath );

            // if plugins and themes are in a custom location (other then default wp-content)
            if (! is_link(trailingslashit( $content_path ) . 'plugins')
                && dirname( $plugins_dir ) !== $content_path ) {
                debug_log("Restoring plugins to custom location ...");
                $locations[trailingslashit( $backup_path )
                           . trailingslashit('wp-content')
                           . 'plugins'] = $plugins_dir;
            }

            if (! is_link(trailingslashit( $content_path ) . 'themes')
                && dirname( $themes_dir ) !== $content_path) {
                debug_log("Restoring themes to custom location ...");
                $locations[trailingslashit( $backup_path )
                           . trailingslashit('wp-content')
                           . 'themes'] = $themes_dir;
            }

            if (! is_link( $uploads_dir )
                && dirname( $uploads_dir ) !== $content_path) {
                debug_log("Restoring uploads to custom location ...");
                $locations[trailingslashit( $backup_path )
                           . trailingslashit('wp-content')
                           . 'uploads'] = $uploads_dir;
            }

            foreach ($locations as $source => $destination) {
                // $this->actifend_quarantine( $destination );
                if (strcmp( $destination, $abspath ) === 0) {
                    // Restore other core files that go into home dir
                    // Actifend attempts to restore these files from backup but the process will fail,
                    // if won't have the correct ownership.
                    // It is typical for the files to be owned by the FTP account that originally uploaded them.
                    $ret = $this->actifend_restore_other_core();
                    if (!$ret) {
                        debug_log("$destination restore failed. Admin needs to manually restore them. ");
                        return false;
                    }
                } else {
                    $ret = $this->actifend_restore_folder($source, $destination);
                    if (!$ret) {
                        debug_log("$destination restore failed. Admin needs to manually restore them. ");
                        return false;
                    }
                }
            }
            debug_log("actifend_restore_core restore done!");
            return true;
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_restore_folder
     * Restores the source folder to its destination
     * @param source
     * @param destination
     * @return boolean (true or false)
     */
    public function actifend_restore_folder($source, $destination) {
        try {
            global $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();

            if (!empty($source)) {
                debug_log("Folder to Restore: ". $source);

                if ($destination == $content_path) {
                    $ret = $this->utiObj->actifend_rrmdir_wp_content($destination, $this->wpcontent2skip);
                } else {
                    $ret = $this->utiObj->actifend_rrmdir($destination);
                }

                if (! $ret) {
                    debug_log($destination . " removal, before restore, failed!");
                    return false;
                } else {
                    debug_log("Deleted folder " . $destination);
                    // copy the source folder to the destination
                    if (!$this->actifend_cp_folder($source, $destination)) {
                        debug_log("$destination folder restoration failed.");
                        return false;
                    } else {
                        debug_log("$destination folder restored fully.");
                        return true;
                    }
                }
            } else {
                debug_log("$source folder is empty!");
                return true;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * actifend_cp_folder
     * This function copies files.
     */
    private function actifend_cp_folder($source, $destination) {
        if (!empty($source)) {
            if (!$this->actifend_copy_folder($source, $destination)) {
                return false;
            // v1.4 - do not try to change permissions at all;
            // if the folder is writable, which would be, copy should suffice.
            }
            return true;
        }
        return false;
    }

    /**
     * copy folder
     *
     * Copy directory function is used to copy the full content of one directory to another directory.
     * Skips folders that are mentioned in the class array variable wpcontent2skip
     * Skips files with extension .log and file defined as BACKUP_FILE constant that are
     * inside the source folder
     *
     * @param string $source Source directory path.
     * @param string $destination Destination path where the content of source directory copied.
     */
    private function actifend_copy_folder($source, $destination, $recursive=true) {
        try {
            global $wp_filesystem;
            $wpfs = $wp_filesystem;
            $content_path = $wpfs->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            if ($source != $backup_path) {
                if ( !$wpfs->exists( $destination ) ) {
                    wp_mkdir_p( $destination );
                    $wpfs->chmod( $destination );
                }
                if ( $recursive ) {
                    $ret = copy_dir( $source, $destination );
                    if ( is_wp_error( $ret ) ) {
                        $error_msg = $ret->get_error_message();
                        debug_log( $error_msg );
                        return false;
                    }
                } else {
                    $files = list_files( $source, 1 );
                    foreach ( $files as $file ) {
                        if ( $wpfs->is_dir( $file ) ) continue;
                        @$ext = pathinfo($file, PATHINFO_EXTENSION);
                        if ($ext != 'log' && $file != BACKUP_FILE) {
                            if (! $wpfs->copy(trailingslashit( $source ) . $file,
                                              trailingslashit( $destination ) . $file, true)) {
                                debug_log("Moving $file to $destination failed.");
                                return false;
                            }
                        }
                    }
                }
                $wpfs->chmod( $destination, false, true );
                return true;
            }
        } catch (Exception $e) {
            $this->actifend_update_restore_status( 'error' );
            debug_log($e->getMessage());
            return false;
        }
    }

    /**
     * actifend_restore_other_core
     *
     * This is function restore file of wordpress directory like wp-login and wp-config etc.
     */
    private function actifend_restore_other_core() {
        try {
            // $dir = ABSPATH;
            // $result = false;
            global $wp_filesystem;
            $wpfs = $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();
            $abspath = $wp_filesystem->abspath();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            $source = trailingslashit( $backup_path );
            $ret = $this->__actifend_copy_files( $source, $abspath, 1 );
            if (is_wp_error( $ret )) {
                $error_msg = $ret->get_error_message();
                debug_log( $error_msg );
                return false;
            }

            debug_log("actifend_restore_other_core function executed!");
            return true;
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_restore_database
     *
     * Function used to restore the database from the backup file.
     */
    private function actifend_restore_database() {
        try {
            global $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            $filename = trailingslashit( $backup_path ) . trailingslashit ('wp-db') . 'actifend-db-backup.sql';
            if (file_exists($filename)) {
                // MySQL host
                $mysql_host     = DB_HOST;
                // MySQL username
                $mysql_username = DB_USER;
                // MySQL password
                $mysql_password = DB_PASSWORD;
                // Database name
                $mysql_database = DB_NAME;

                // Connect to MySQL server
                $con = mysqli_connect($mysql_host, $mysql_username, $mysql_password);
                if (!$con) {
                    debug_log("ERROR: Unable to connect to MySQL instance!");
                    debug_log("Debugging error: " . mysqli_connect_error() . PHP_EOL);
                } else {
                    debug_log("Connected to MySQL instance!");
                }
                // Select database
                if (!mysqli_select_db($con, $mysql_database)) {
                    debug_log("ERROR: Unable to select ". $mysql_database);
                } else {
                    debug_log("Selected WP mysql database!");
                }

                // Temporary variable, used to store current query
                $templine = '';
                // Read in entire file
                $lines = file($filename);
                // Loop through each line
                foreach ($lines as $line) {
                    // Skip it if it's a comment
                    if (substr($line, 0, 2) == '--' || $line == '') {
                        continue;
                    }

                    // Add this line to the current segment
                    $templine .= $line;
                    // If it has a semicolon at the end, it's the end of the query
                    if (substr(trim($line), -1, 1) == ';') {
                        // Perform the query
                        if (!mysqli_query($con, $templine)) {
                            debug_log('Error performing $templine query!');
                        }

                        // Reset temp variable to empty
                        $templine = '';
                    }
                }
            }
            debug_log("actifend_restore_database function executed!");
        } catch (Exception $e) {
            debug_log($e->getMessage());
            $this->actifend_update_restore_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_restore_incremental_backup
     *
     * Function used to restore the incremental backedup files
     */
    private function actifend_restore_incremental_backup( $post_full=false ) {
        debug_log("Executing actifend_restore_incremental_backup function ...");
        global $wp_filesystem;
        $content_path   = $wp_filesystem->wp_content_dir();
        $abspath        = $wp_filesystem->abspath();
        $admin_path     = trailingslashit( $abspath ) . 'wp-admin';
        $includes_path  = trailingslashit( $abspath ) . 'wp-includes';
        $backup_path    = trailingslashit( $content_path ) . 'wp_backup';

        // Now restore files that are needed to be restored
        $dir_list       = list_files( $backup_path , 1);
        foreach ($dir_list as $index => $bdir) {
            if ($bdir === trailingslashit( $backup_path ) . trailingslashit( 'wp-db' )) {
                unset( $dir_list[$index] );
		        if (!empty( $dir_list )) array_unshift ( $dir_list, array_shift ( $dir_list ) );
                break;
            }
        }

        // if dir list is empty then exit
        if (empty( $dir_list )) return true;

        // // restore files from each of the remaining folders to their
        // $to_dir_array = array ( basename( $content_path )   => $content_path,
        //                         basename( $admin_path )     => $admin_path,
        //                         basename( $includes_path )  => $includes_path,
        //                         basename( $backup_path )    => $abspath );
        // // respective locations
        // foreach ( $dir_list as $bdir ) {
        //     $from_dir = untrailingslashit( $bdir );
        //     debug_log("Restoring from $from_dir");
        //     if ( in_array( basename( $from_dir ), array_keys( $to_dir_array ) )) {
        //         foreach ($to_dir_array as $key => $value) {
        //             if ($key == basename( $from_dir )) {
        //                 $to_dir = untrailingslashit( $value );
        //                 break;
        //             }
        //         }
        //         debug_log("Restoring to $to_dir folder.");
        //         $ret = $this->__actifend_copy_files( $from_dir, $to_dir );
        //         if ( $ret ) {
        //             debug_log("Restore to " . basename( $to_dir ) . " folder done.");
        //         }
        //     }
        // }

        // post restore verification and quarantine
        $diffs = $this->compare_files_in_system_with_files_in_json( UPLOADS_DIR,
                                                trailingslashit( $backup_path )
                                                . 'files.json' );
        debug_log("Files NOT in Backup: ");
        debug_log( $diffs );
        if (! empty( $diffs )) {
            foreach ( $diffs as $file ) {
                $file_p = trailingslashit( $wp_filesystem->abspath()) . $file;
                $dest = trailingslashit( $wp_filesystem->wp_content_dir() )
                        . trailingslashit( 'afend_quarantine' ) . $file;
                if ( is_dir( $file_p ) && ! $wp_filesystem->exists( $dest ))
                    wp_mkdir_p( $dest );

                if ( is_file( $file_p ) ) {
                    if ( ! $wp_filesystem->exists( dirname( $dest )) )
                        wp_mkdir_p( dirname( $dest ) );
                    $wp_filesystem->move( $file_p, $dest, true );
                    debug_log("$file_p moved to " . dirname( $dest ));
                }
            }
            debug_log("Files not in Backup are quarantined!");
        }
        return true;
    }


    private function __actifend_copy_files( $from_dir, $to_dir, $level=100 ) {
        // copy files from one folder to another with overwrite
        // do not use this function exclusively
        global $wp_filesystem;
        if ( $level == 100 ) {
            $c_lst_files = list_files( $from_dir );
        } else {
            $c_lst_files = list_files( $from_dir, $level );
        }

        foreach ($c_lst_files as $file) {
            $to_path = '';
            if ($wp_filesystem->is_dir( $file )) continue;
            $file_dir = dirname( $file );
            $sub_file_dir = substr( $file_dir, strlen( $from_dir ) );
            $to_path = $to_dir . $sub_file_dir;
            if (! $wp_filesystem->exists( $to_path ) ) {
                wp_mkdir_p( $to_path );
            }
            // overwrite file if already there!
            $ret = $wp_filesystem->move( $file,
                                         trailingslashit( $to_path ) . basename( $file ),
                                         true );
            if (is_wp_error( $ret )) {
                $error_msg = $ret->get_error_message();
                debug_log( $error_msg );
                return false;
            }
        }
        return true;
    }

    private function __file_to_json( $js_file ) {
        // file with json encoded string reversed as an array
        global $wp_filesystem;
        $json_string = $wp_filesystem->get_contents( $js_file );
        $files = json_decode( $json_string );
        return $files;
    }

    private function __compare_files_in_db_with_files_in_system() {
        global $wp_filesystem;
        global $wpdb;
        $skip_dirs = array(
            'actifend_tmp',
            'upgrade',
            'actifend',
            'wp_backup',
            'wflogs',
            'afend_quarantine',
            '.htaccess'
        );

        $integrity_files_table = $wpdb->prefix . ACTIFEND_INTEGRITY_FILES_TABLE;
        $result = $wpdb->get_results("SELECT * FROM {$integrity_files_table}");
        $files_in_db = array();
        foreach ($result as $value) {
            $file_p = $value->file_path;
            $pos = strpos( $file_p, 'wp-config.php' );
            if (! $pos)
                continue;
            else
                break;
        }

        foreach ($result as $value) {
            // add filenames in db to files_in_db array
            $file_p = substr( $value->file_path, $pos );
            array_push( $files_in_db, $file_p );
        }

        $files_in_sys = $this->fiObj->takeSnapshot( 'mtime', '', $skip_dirs );
        $sys_files = $this->fiObj->get_values_from_assoc_array($files_in_sys, 'file_path');
        $files_in_system = array();
        foreach ( $sys_files as $file ) {
            if ( is_file( $file ) ) {
                $file_p = substr( $file, strlen( $wp_filesystem->abspath() ) );
                array_push( $files_in_system, $file_p );
            }
        }

        $diff = array_diff( $files_in_system, $files_in_db );

        return $diff;
    }

    /**
    * compare_files_in_system_with_files_in_json()
    * @param array $dir_in_sys: folder in the system
    * @param string $jsonfile: json file from backup
    * @return array
    * 10/5/20177 - v1.4.6.4
    */
    private function compare_files_in_system_with_files_in_json( $dir_in_sys, $jsonfile ) {
        global $wp_filesystem;
        $files_in_sys = list_files( $dir_in_sys );
        $dir1 = array();
        foreach ( $files_in_sys as $file_s ) {
            if ( is_file( $file_s )) {
                $file_p = substr( $file_s, strlen( $wp_filesystem->abspath() ) );
                array_push( $dir1, $file_p );
            }
        }
        $file_contents = json_decode( file_get_contents( $jsonfile ), true );
        $js_file = array();
        foreach ( $file_contents as $fp ) {
            $file_p = $fp['file_path'];
            $file_p = str_replace('\/', DIRECTORY_SEPARATOR, $file_p);
            if ( is_file( $file_p )) {
                $file_p = substr( $file_p, strlen( $wp_filesystem->abspath() ) );
                array_push( $js_file, $file_p );
            }
        }

        $diff = array_diff( $dir1, $js_file );
        return $diff;
    }
}
?>
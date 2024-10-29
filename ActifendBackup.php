<?php
if( !class_exists( 'Actifend' ) ) require_once( 'actifendClass.php' );

/**
 * Backup class this class is used for create wordpress backup and send
 * that backup file to the azure stroage.
 *
 */
class ActiFendBackup {
    var $utiObj;
    var $fiObj;
    var $req_id;
    var $addlMsg;
    var $max_uploads_size = 0;
    var $last_backup_time;
    // folders to skip from backup
    var $wpcontent2skip = array(
        'actifend_tmp',
        'wflogs',
        'wp_backup',
        'afend_quarantine'
    );

    /**
     * constructor function
     *
     * @param null
     * @return objct this will return  object of Utlity class.
     */
    public function __construct() {
        // check if file system permissions are there for the user
        $this->utiObj = new Utility;
        $this->utiObj->initFileSystem();
        global $wp_filesystem;
        $this->fiObj  = new ActifendFileIntegrity( $wp_filesystem->abspath() );
    }

    /**
     * Zip extension check
     */
    public function zip_check() {
        return extension_loaded( 'zip' );
    }

    /**
     * actifend_backup_process
     * After completion of upload to Actifend-cloud status is to be sent
     * Actifend BE
     */
    public function actifend_backup_process() {
        // get asset id
        $result = $this->utiObj->getActifendInfo();
        if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
            $assetid = $result->asset_id;
        } else {
            debug_log( 'No ASSET ID assigned .... ' );
            return 'NO_ASSETID';
        }
        // Execute Backup process
        $this->actifend_wordpress_backup( $assetid );
        $statusOpt = get_option( 'ActifendBackupStatus' );
        // check if no requests are pending exit without doing anything
        if ( strtoupper( $statusOpt ) == 'NONE' ) return;
        // Send the status to Actifend BE
        $backupStatus = ( $statusOpt == 'complete' ) ? 'done' : 'failed';
        $actifend_array = array('status'        => $backupStatus,
                                'zip_enabled'   => $this->zip_check(),
                                'reason'        => $this->addlMsg,
                                'reqid'         => $this->req_id);

        $actifend_params = json_encode( $actifend_array );
        // send status done status by curl usind the PATCH method when backup send to azure successfully.
        $actifend_url = ACTIFEND_BACKUP_END_POINT . $assetid . '/wpbackup';
        $this->utiObj->actifend_postViaCurl( $actifend_url, $actifend_params, 'PATCH' );
        debug_log( 'Status update sent to Actifend BE.' );
    }

    /**
     * actifend_wordpress_backup
     *
     * This function create the receive the backup request after that create
     * the zip of all your wordpress files and database as per the request full
     * backup and the at send those files to the azure stroage in
     * chunks of 3MB and then send the done status after completion of all steps.
     *
     * @return null
     */
    public function actifend_wordpress_backup( $asset_id ) {
        try {
            debug_log( 'Check for backup requests initiated ...' );
            // check if any backup requests are waiting at BE
            $response  = $this->utiObj->getViaCurl(ACTIFEND_BACKUP_END_POINT, $asset_id, 'wpbackup');

            if ( isset( $response ) && !empty( $response ) && !is_wp_error( $response ) ) {
                if ( $response['ResponseCode'] == '2000' && $response['Message'] == 'success' ) {
                    // It create the backup of the files of the wordpress
                    $assetid            = $response['Result']['asset_id'];
                    $account_name       = $response['Result']['stor_name'];
                    $share_name         = $response['Result']['share_name'];
                    $sas_token          = $response['Result']['sas_token'];
                    $this->req_id       = $response['Result']['_id'];
                    $backup_type        = $response['Result']['backup_type'];

                    $this->max_uploads_size = intval( $response['Result']['max_uploads_size'] );
                    // suspend the crons until backup & restore is completed
                    $this->utiObj->reset_actifend_crons( 'remove' );
                    // option setting
                    $this->actifend_update_backup_status( 'initiated' );

                    $file         = BACKUP_FILE;  // defined in actifendConstants
                    $max_range    = 3 * 1024 * 1024;
                    if ( strcmp( $backup_type, 'full' ) === 0 ) {
                        // create the backup zip file
                        $this->actifend_wp_backup();
                    } else {
                        $backup_type = 'content';
                        @$inc_from_time = $response['Result']['full_backup_timestamp'];
                        debug_log( "Latest Full Backup Time: $inc_from_time" );
                        if ( is_null( $inc_from_time ) ) {
                            $inc_from_time = '2017-03-01 00:00:00';
                            debug_log( "Revised Full Backup Time: $inc_from_time" );
                        }
                        $filesChanged = $this->afend_get_incremental_changes( $inc_from_time );
                        // TODO - backup the files identified
                        $this->afend_do_backup( $filesChanged );
                        // create the backup zip file
                        $this->actifend_db_backup();
                    }
                    global $wp_filesystem;
                    $content_path = $wp_filesystem->wp_content_dir();
                    $backup_path = trailingslashit( $content_path ) . basename( BACKUP_DIR );
                    // remove the directory used to keep the backup content directory.
                    $this->utiObj->actifend_rrmdir( $backup_path );
                    // List the files and the directory  ----------------------------------
                    // check if the folder with assetid as name exists
                    $uri = 'https://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "?" . $sas_token . "&restype=directory";
                    $today = gmdate( 'D, d M Y G:i:s T' );
                    $az_headers = array( 'x-ms-date' => $today, 'x-ms-version' => '2015-04-05' );

                    $resp_status = $this->actifend_storage_asset_check( $uri, $az_headers );
                    if ($resp_status === 404) {
                        debug_log( "$assetid Folder does not exist!" );
                        // create the folder!
                        $uri = 'http://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "?" . $sas_token . "&restype=directory";
                        $az_headers = array_merge( $az_headers, array( 'Content-Length' => '0' ) );
                        $response = wp_remote_request($uri, array('headers' => $az_headers,
                                                                  'method' => 'PUT'));
                        if ( $response['response']['code'] == 201 ) {
                            debug_log( "$assetid Folder created on Actifend-cloud!" );
                        }
                        // Now create a sub folder
                        $subfolder = date( 'Y-m-d_G_i_s' );

                        $uri = 'http://' . $account_name . '.file.core.windows.net/' . $share_name . '/' . $assetid . '/' . $subfolder . '?' . $sas_token . '&restype=directory';

                        $addl_headers = array('x-ms-meta-bkp_type' => $backup_type,
                                              'x-ms-meta-category' => 'backup');
                        $args = array_merge($az_headers, $addl_headers);
                        $response = wp_remote_request($uri, array('headers' => $args, 'method' => 'PUT'));
                        if ($response['response']['code'] === 201) {
                            debug_log("Subfolder $subfolder with metadata created. Now move on and place the file there ...<br/>");
                        }
                    } elseif ( $resp_status === 200 ) {
                        debug_log("Folder $assetid exists! move on and look for the subfolder...<br/>");
                        // Build the subfolder name
                        // create the subfolder as it would not be existing!!
                        $subfolder = date( 'Y-m-d_G_i_s' );
                        $uri = 'http://' . $account_name . '.file.core.windows.net/' . $share_name . "/" . $assetid . "/" . $subfolder . "?" . $sas_token . "&restype=directory";

                        $addl_headers = array('x-ms-meta-bkp_type' => $backup_type,
                                              'x-ms-meta-category' => 'backup',
                                              'Content-Length'     => '0');
                        $args = array_merge( $az_headers, $addl_headers );
                        $response = wp_remote_request( $uri, array( 'headers' => $args, 'method' => 'PUT' ) );
                        if ( $response['response']['code'] === 201 ) {
                            debug_log( "Subfolder $subfolder with metadata created. Now move on and place the file there ...<br/>" );
                        } else {
                            debug_log( 'Unable to create the Subfolder in Actifend-cloud.' );
                            $this->actifend_update_backup_status( 'upload_error1' );
                            // exit("Unable to create the folder, exiting!");
                            return;
                        }
                    } else {
                        $error_msg = 'Storage Lookup Error: ' . $response['response']['message'];
                        debug_log( $error_msg );
                        $this->actifend_update_backup_status( 'upload_error2', $response['response']['message'] );
                        return;
                    }

                    // Call Azure API to save the file in the storage.
                    $fsize = filesize( trailingslashit( $content_path ) . BACKUP_FILE );
                    $uri   = 'https://' . $account_name . '.file.core.windows.net/' . $share_name . '/' . $assetid . '/' . $subfolder . '/' . $file . '?' . $sas_token;
                    $today = gmdate( 'D, d M Y G:i:s T' );

                    $addl_headers = array('Content-Length'      => '0',
                                          'x-ms-type'           => 'file',
                                          'x-ms-content-length' => (string) $fsize);
                    $heads = array_merge( $az_headers, $addl_headers );
                    $response = wp_remote_request($uri, array('headers' => $heads,
                                                              'method'  => 'PUT'));

                    if ( is_wp_error( $response ) || $response['response']['code'] == 400 ) {
                        debug_log( 'Starting file upload to Actifend-cloud failed!' );
                        $this->actifend_update_backup_status( 'upload_error3' );
                        return;
                    }

                    $handle = fopen( trailingslashit( $content_path ) . BACKUP_FILE, 'rb' );
                    $uri_range = $uri . '&comp=range';

                    // For an update operation, the range can be up to 4 MB in size.
                    // file needs to be uploaded in chunks of $max_range (defined above)
                    if ( $fsize > $max_range ) {
                        $mod = $fsize / $max_range;
                        $iters = round( $mod, 0 );
                        if ( $iters < $mod ) {
                            $iters += 1;
                        }
                    } else {
                        $iters = 1;
                    }
                    $start_byte = 0;
                    $ie = 0;
                    for ( $i = 1; $i <= $iters; $i++ ) {
                        // set the start and end byte values for the range
                        $end_byte = $start_byte + $max_range - 1;
                        if ( $i == $iters ) {
                            $end_byte = $fsize - 1;
                        }

                        // Read the contents in the file
                        $contentLength = $end_byte - $start_byte + 1;
                        $contents = fread( $handle, $contentLength );

                        // initiate the api call
                        $addl_headers = array('x-ms-write' => 'update',
                                              'x-ms-range' => "bytes=" . $start_byte . "-" . $end_byte,
                                              'Content-Length' => (string) $contentLength);
                        $heads = array_merge($az_headers, $addl_headers);
                        $response = wp_remote_request($uri_range,
                                                      array('headers' => $heads,
                                                            'body' => $contents,
                                                            'method' => 'PUT'));
                        if ( is_wp_error($response) || $response['response']['code'] !== 201 ) {
                            $this->actifend_update_backup_status( 'upload_error4' );
                        } else {
                            $start_byte = $end_byte + 1;
                            $ie = $i;
                        }
                    }

                    if ( $ie != $iters ) {
                        debug_log( 'File upload incomplete! :(' );
                        $this->actifend_update_backup_status( 'upload_error5' );
                    } else {
                        debug_log( 'File upload to Azure storage completed!<br/>' );
                        // delete backup zip file
                        $wp_filesystem->delete( trailingslashit( $content_path ) . BACKUP_FILE );
                        // update status of the process
                        $this->actifend_update_backup_status( 'complete' );
                        // reinitate the cron for backup & restore
                        $this->utiObj->reset_actifend_crons( 'add' );
                    }
                } else {
                    $this->actifend_update_backup_status( 'none' );
                    return;
                }
            } elseif( is_wp_error( $response ) ) {
                debug_log( $response->get_error_message() );
                $this->actifend_update_backup_status( 'error' );
                return;
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            $this->actifend_update_backup_status( 'error' );
            return;
        }
    }


    /**
     * actifend_storage_asset_check
     * @az_uri actifend storage url
     * @az_headers request header information
     * @return response status code
     * @access private
     */
    public function actifend_storage_asset_check( $az_uri, $az_headers ) {
        $response = wp_remote_head( $az_uri, array( 'headers' => $az_headers ) );
        $resp_status = $response['response']['code'];
        // v1.4.4.3 Retry if the check fails first time
        if ( $resp_status != 404 || $resp_status != 200 ) {
            debug_log( "Received $resp_status .... retrying ... ");
            // v1.5.3 changed head to get on retry
            $response = wp_remote_get( $az_uri, array( 'headers' => $az_headers ) );
            $resp_status = $response['response']['code'];
        }
        return $resp_status;
    }


    /**
     * actifend_db_backup
     * Takes database backup and creates a zip file
     */
    public function actifend_db_backup() {
        try {
            global $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            // database access credentials.
            $dbcreds = array(
                'db_host'           => DB_HOST,  //mysql host
                'db_uname'          => DB_USER,  //user
                'db_password'       => DB_PASSWORD,  //pass
                'db_to_backup'      => DB_NAME,  //database name
                'db_backup_path'    => trailingslashit( BACKUP_DIR ) . trailingslashit( 'wp-db' ),  //where to backup
                'db_exclude_tables' => array()  //tables to exclude
            );
            // use function to backup the sql.
            $res = $this->__backup_mysql_database( $dbcreds );
            if ( $res ) {
                debug_log( 'Database dump completed successfully.' );
            } else {
                $this->actifend_update_backup_status( 'db_error' );
                return false;
            }
            // // copy the wp-content/uploads in wp_backup directory
            // $destination = trailingslashit( BACKUP_DIR ) . "wp-content/uploads";
            // $this->copy_directory(UPLOADS_DIR, $destination);
            // send files with pcl zip compression using PclZip
            $zipDone = $this->utiObj->pclZipData(trailingslashit( $backup_path ),
                                                 trailingslashit( $content_path ) . BACKUP_FILE);
            if ($zipDone != 0) {
                debug_log( 'Pcl zip compression is used!' );
                $this->actifend_update_backup_status( 'zip_complete' );
                return true;
            } else {
                debug_log( 'Pcl zip compression failed.' );
                // chek if zip extension is enabled
                if ( $this->zip_check() ) {
                    $this->utiObj->zipData(trailingslashit( $backup_path ),
                                           trailingslashit( $content_path ) . BACKUP_FILE);
                    if ( $wp_filesystem->exists( trailingslashit( $content_path ) . BACKUP_FILE ) ) {
                        $wp_filesystem->chmod( trailingslashit( $content_path ) . BACKUP_FILE, 0777 );
                    }
                    debug_log( 'DB backup Zipping complete!' );
                    $this->actifend_update_backup_status( 'zip_complete' );
                    return true;
                } else {
                   $this->actifend_update_backup_status( 'zip_error' );
                    return false;
                }

            }
        } catch ( Exception $e ) {
            $this->actifend_update_backup_status( 'error' );
            return false;
        }
    }

    /**
     * actifend_uploads_backup
     * Takes uploads folder backup to the stating location
     */
    public function actifend_uploads_backup() {
        global $wp_filesystem;

        $abspath = $wp_filesystem->abspath();
        $content_path = $wp_filesystem->wp_content_dir();
        $uploads_dir = trailingslashit( $content_path ) . 'uploads';
        if ( is_link( $uploads_dir ) ) $uploads_dir = readlink( $uploads_dir );
        $backup_path = trailingslashit( $content_path ) . 'wp_backup';

        // Add uploads folder upto a limit`specified
        $uploads_size     = $this->utiObj->get_size_of_folder( $uploads_dir );
        if ( $uploads_size < $this->max_uploads_size
            || $this->max_uploads_size !== 0 ) {
            // create necessary folders in the staging location
            if ( defined( 'UPLOADS' ) ) {
                $uploads = UPLOADS;
            } else {
                $abslen = strlen( $abspath );
                $uploads = substr( $uploads_dir, $abslen );
            }
            $uploads_dest = trailingslashit( $backup_path ) . untrailingslashit( $uploads );
            // Recursive directory creation based on full path
            wp_mkdir_p( $uploads_dest );
            $is_added = copy_dir( $uploads_dir, $uploads_dest );
            if ( $is_added ) {
                debug_log( $uploads . " folder added to backup... " );
            } else {
                debug_log( $uploads . " folder could NOT be aded to backup..." );
            }
        }
    }

    /**
    * Actifend Wp Backup
    *
    * This function copy the all wordpress files and create a .aql file of wordpress database  and add  those to  wp_backup directory.Create a zip file in the wp-content
    * with wp_backup.zip file.
    *
    * @return null
    * @access private
    */
    private function actifend_wp_backup() {
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

            $locations = array( $content_path  => trailingslashit( $backup_path ) . 'wp-content',
                                $admin_path    => trailingslashit( $backup_path ) . 'wp-admin',
                                $includes_path => trailingslashit( $backup_path ) . 'wp-includes',
                                $abspath       => $backup_path );

            // if plugins and themes are in a custom location (other then default wp-content)
            if (! is_link( trailingslashit( $content_path ) . 'plugins' )
                && dirname( $plugins_dir ) !== $content_path
                && ! in_array( dirname( $plugins_dir ), array_keys( $locations ) ) ) {
                debug_log( 'Backing up plugins from custom location ...' );
                $locations[$plugins_dir] = trailingslashit( $backup_path )
                                           . trailingslashit( 'wp-content' )
                                           . 'plugins';
            }

            if (! is_link( trailingslashit( $content_path ) . 'themes' )
                && dirname( $themes_dir ) !== $content_path
                && ! in_array( dirname( $themes_dir ), array_keys( $locations ) ) ) {
                debug_log( 'Backing up themes from custom location ...' );
                $locations[ $themes_dir ] = trailingslashit( $backup_path )
                                            . trailingslashit( 'wp-content' )
                                            . 'themes';
            }

            // Add uploads folder upto a limit`specified
            $uploads_size = $this->utiObj->get_size_of_folder( $uploads_dir );
            if ( $uploads_size > $this->max_uploads_size
                && $this->max_uploads_size !== 0 ) {
                array_push( $this->wpcontent2skip, 'uploads' );
                debug_log( 'Skipping uploads folder backup ... ' );
            }

            foreach ( $locations as $source => $destination ) {
                if ( strcmp( $source, $abspath ) === 0 ) {
                    // recursive should be false
                    $this->copy_directory( $source, $destination, false );
                    debug_log( "$source FILES are added to backup." );
                } else {
                    // $this->copy_directory($source, $destination);
                    $this->actifend_cpdir( $source, $destination );
                }
                // debug_log("$source folder added to backup.");
            }

            // database access credentials.
            $para = array(
                'db_host'           => DB_HOST,  //mysql host
                'db_uname'          => DB_USER,  //user
                'db_password'       => DB_PASSWORD,  //pass
                'db_to_backup'      => DB_NAME,  //database name
                'db_backup_path'    => trailingslashit( $backup_path ) . trailingslashit('wp-db'),  //where to backup
                'db_exclude_tables' => array()  //tables to exclude
            );
            // use function to backup the sql.
            $res = $this->__backup_mysql_database( $para );
            if ( $res ) {
                debug_log( 'Database dump completed successfully.' );
            } else {
                debug_log( 'ERROR: Database dump failed.' );
            }

            // send files with pcl zip compression using PclZip
            $zipDone = $this->utiObj->pclZipData(trailingslashit( $backup_path ),
                                                 trailingslashit( $content_path ) . BACKUP_FILE);
            if ( $zipDone != 0 ) {
                debug_log( 'PclZip compression DONE!' );
                return true;
            }else {
                debug_log( 'pcl zip compression failed.' );
                if ( $this->zip_check() ) {
                    debug_log( 'Checking with ZIP extension ... ' );
                    $this->utiObj->zipData(trailingslashit( $backup_path ),
                                           trailingslashit( $content_path ) . BACKUP_FILE);
                    global $wp_filesystem;
                    if ($wp_filesystem->exists( trailingslashit( $content_path ) . BACKUP_FILE )) {
                        $wp_filesystem->chmod( trailingslashit( $content_path ) . BACKUP_FILE, 0777 );
                    }
                    // if (file_exists(trailingslashit( WP_CONTENT_DIR ) . BACKUP_FILE)) {
                    //     chmod(trailingslashit( WP_CONTENT_DIR ) . BACKUP_FILE, 0777);
                    // }
                    debug_log( 'Site backup Zipping complete!' );
                    return true;
                } else {
                    $this->actifend_update_backup_status( 'zip_error' );
                    return false;
                }
            }

        } catch ( Exception $e ) {
            $this->actifend_update_backup_status( 'error' );
            return False;
        }
    }

    /**
     * copy directory
     *
     * Copy directory function is used to copy the full content of one directory to another directory.
     * Skips folders that are mentioned in the class array variable wpcontent2skip
     * Skips files with extension .log and file defined as BACKUP_FILE constant that are
     * inside the source folder
     *
     * @param string $source Source directory path.
     * @param string $destination Destination path where the content of source directory copied.
     */
    private function copy_directory( $source, $destination, $recursive=true ) {
        try {
            global $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            $wp_filesystem->mkdir( $backup_path );

            if ( $source != $backup_path ) {
                $directory = opendir( $source );
                $wp_filesystem->mkdir( $destination );
                while ( false !== ( $file = readdir( $directory ) ) ) {
                    if ( $file != '.' && $file != '..' ) {
                        if ( is_dir( trailingslashit( $source ) . $file ) && $recursive === true ) {
                            if ( ! in_array( $file, $this->wpcontent2skip ) ) {
                                $this->copy_directory(trailingslashit( $source ) . $file,
                                                      trailingslashit( $destination ) . $file);
                            }
                        } else {
                            @$ext = pathinfo($file, PATHINFO_EXTENSION);
                            if ($ext != 'log'
                                && $file != BACKUP_FILE
                                && !is_dir( trailingslashit( $source ) . $file )) {
                                $wp_filesystem->copy(trailingslashit( $source ) . $file,
                                                     trailingslashit( $destination ) . $file);
                            }
                        }
                    }
                }
                closedir( $directory );
                return True;
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            $this->actifend_update_backup_status( 'error' );
            return False;
        }
    }


    private function actifend_cpdir( $source, $destination ) {
        try {
            global $wp_filesystem;
            $content_dir = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_dir ) . 'wp_backup';

            $wp_filesystem->mkdir( $backup_path );
            $skip_lst = $this->wpcontent2skip;
            array_push( $skip_lst, '*.log' );
            array_push( $skip_lst, BACKUP_FILE );

            if ( $source != $backup_path ) {
                $wp_filesystem->mkdir( $destination );
                $ret = copy_dir( $source, $destination, $skip_lst );
                if ( is_wp_error( $ret ) ) {
                    $error_msg = $ret->get_error_message();
                    debug_log( $error_msg );
                    return $error_msg;
                }
                debug_log( "Backup of $source completed." );
            }

            return true;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            $this->actifend_update_backup_status( 'error' );
            return false;
        }
    }

    /**
     * __backup_mysql_database (using mysqldump method)
     * @param sql filename
     * @return void
     */
    private function __backup_mysql_database( $params ) {
        try {
            global $wp_filesystem;
            $content_path = $wp_filesystem->wp_content_dir();
            $backup_path = trailingslashit( $content_path ) . 'wp_backup';

            $this->fiObj->createBaseline();

            if ( !is_dir( $params['db_backup_path'] ) ) {
                mkdir( $params['db_backup_path'], 0777, true );
            }

            $dir = trailingslashit( $backup_path ) . trailingslashit( 'wp-db' );
            $backup_file_name = $dir . 'actifend-db-backup.sql';

            $cmd = 'mysqldump';
            $host = explode( ':', DB_HOST );
            $port = strpos( DB_HOST, ':' ) ? end( $host ) : '';
            $host = reset( $host );
            // We don't want to create a new DB
            $cmd .= ' --no-create-db';
            // Allow lock-tables to be overridden
            $cmd .= ' --single-transaction';
            // Make sure binary data is exported properly
            $cmd .= ' --hex-blob';
            // Username
            $cmd .= ' -u ' . escapeshellarg( DB_USER );
            // Don't pass the password if it's blank
            if ( DB_PASSWORD )
                $cmd .= ' -p' . escapeshellarg( DB_PASSWORD );
            // Set the host
            $cmd .= ' -h ' . escapeshellarg( $host );
            // Set the port if it was set
            if ( !empty( $port ) && is_numeric( $port ) )
                $cmd .= ' -P ' . $port;
            // The file we're saving too
            $cmd .= ' -r ' . escapeshellarg( $backup_file_name );
            // Exclude tables if any (default is empty array)
            $wp_db_exclude_table = array();
            if ( !empty( $wp_db_exclude_table ) ) {
                foreach ( $wp_db_exclude_table as $wp_db_exclude_table ) {
                    $cmd .= ' --ignore-table=' . DB_NAME . '.' . $wp_db_exclude_table;
                }
            }
            // The database we're dumping
            $cmd .= ' ' . escapeshellarg( DB_NAME );
            // Pipe STDERR to STDOUT
            $cmd .= ' 2>&1';
            // Store any returned data in an error
            $stderr = shell_exec( $cmd );
            // Skip the new password warning that is output in mysql > 5.6
            if ( trim( $stderr ) === 'Warning: Using a password on the command line interface can be insecure.' ) {
                $stderr = '';
            }
            debug_log( 'mysqldump: SQL Dump completed.' );
            return $this->verify_mysqldump( $backup_file_name );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            $this->actifend_update_backup_status( 'error' );
            return False;
        }
    }


    public function verify_mysqldump( $SQLfilename ) {
        // If we've already passed then no need to check again
        if ( !empty( $this->mysqldump_verified ) )
            return true;

        // If we have an empty file delete it
        if ( @filesize( $SQLfilename ) === 0 )
            unlink( $SQLfilename );

        // If the file still exists then it must be good
        if ( file_exists( $SQLfilename ) )
            debug_log( 'DB backup verification complete.' );
            return $this->mysqldump_verified = true;

        return false;
    }

    /**
     * afend_get_incremental_changes
     *
     * Gets files from uploads folder incrementally since last backup
     * If no last backup, uploads folder should be considered fully
     * @param timestamp from where incremental changes need to apply
     * format -> "Y-m-d H:M:S"
     * @return array of files
     */
    private function afend_get_incremental_changes( $fromTime ) {
        global $wp_filesystem;
        $abspath = $wp_filesystem->abspath();
        $content_path = $wp_filesystem->wp_content_dir();

        $fromTime_ms = strtotime( $fromTime );
        $selectedFiles = array ();
        $fiObj = new ActifendFileIntegrity( $abspath );

        $prefix = trailingslashit( basename( $content_path ));
        $ignore_dirs = array(
            $prefix . 'actifend_tmp',
            $prefix . 'wp_backup',
            $prefix . 'wflogs',
            $prefix . 'afend_quarantine'
        );

        $files = $fiObj->takeSnapshot( 'mtime', $abspath, $ignore_dirs );
        $this->__get_fs_as_json( $files );

        foreach ( $files as $file ) {
            if ( is_file( $file['file_path'] )
                && $file['file_mtime'] > $fromTime_ms ) {
                $selectedFiles[] = $file;
            }
        }
        debug_log( "# of changed files after $fromTime is " . (string) count($selectedFiles) );
        return $selectedFiles;
    }

    /**
     * afend_do_backup
     *
     * does backup of the files specified to the staging folder
     * @param files to backup
     * @return null
     */
    private function afend_do_backup( $files ) {
        global $wp_filesystem;
        $wpfs = $wp_filesystem;
        $abspath = $wpfs->abspath();
        $content_path = trailingslashit( $abspath ) . 'wp-content';
        $admin_dir = trailingslashit( $abspath ) . 'wp-admin';
        $includes_dir = trailingslashit( $abspath ) . 'wp-includes';
        $backup_path = trailingslashit( $content_path ) . 'wp_backup';

        $wpfs->mkdir( $backup_path );
        $count = 0;
        $nocount = 0;
        $fcount = 0;
        foreach ( $files as $file ) {
            if ( $wpfs->is_file( $file['file_path' ]) ) {
                $fcount++;
                $dirName = dirname( $file['file_path'] );
                $fName = basename( $file['file_path'] );
                if ( strcmp( $dirName, untrailingslashit( $abspath ) ) === 0 ) {
                        $postCdir = '';
                        $count++;
                } elseif ( strstr( $dirName, $content_path ) ) {
                    $postCdir = substr( $dirName, strlen( $content_path ) );
                    $postCdir = trailingslashit( basename( $content_path ) ) . $postCdir;
                    $count++;
                } elseif ( strstr( $dirName, $admin_dir ) ) {
                    $postCdir = substr( $dirName, strlen( $admin_dir ) );
                    $postCdir = trailingslashit( basename( $admin_dir ) ) . $postCdir;
                    $count++;
                } elseif ( strstr( $dirName, $includes_dir ) ) {
                    $postCdir = substr( $dirName, strlen( $includes_dir ) );
                    $postCdir = trailingslashit( basename( $includes_dir ) ) . $postCdir;
                    $count++;
                } else {
                    debug_log( "$dirName not available to backup." );
                    $nocount++;
                }
                $dest = trailingslashit( $backup_path ) . $postCdir;
                // create folder recursively
                wp_mkdir_p( $dest );
                if (! $wpfs->copy( $file['file_path'],
                                   trailingslashit( $dest ) . $fName ) ) {
                    debug_log( "Could not copy $fName to $dest" );
                }
            }
        }
        debug_log( "$count of $fcount files copied to staging folder." );
        debug_log( "$nocount files skipped..." );
    }

    /**
    * actifend_update_backup_status
    *
    * Updates the status of the backup process
    */
    public function actifend_update_backup_status( $status, $message=null ) {
        $status_lst = array (
            'failed'        => 'Backup process failed.',
            'initiated'     => 'Backup process initiated.',
            'complete'      => 'Backup to Actifend-cloud successful.',
            'error'         => 'Unexpected error during backup.',
            'db_error'      => 'DB error occurred.',
            'zip_error'     => 'Error during zip compression.',
            'zip_complete'  => 'Zip compression successfully completed.',
            'no_backup'     => 'Backup file does not exist.',
            'none'          => 'No backup request pending.',
            'upload_error1' => 'Unable to create the Subfolder in ActiFend-cloud',
            'upload_error2' => ( !is_null( $message ) ? $message : 'ActiFend-Cloud storage lookup error' ),
            'upload_error3' => 'Starting file upload to ActiFend-cloud failed!',
            'upload_error4' => 'Error while uploading backup file ' . BACKUP_FILE,
            'upload_error5' => 'File upload incomplete.'
        );
        if ( in_array( $status, array_keys( $status_lst )) ) {
            update_option( 'ActifendBackupStatus', $status );
            $this->addlMsg = $status_lst[$status];
            debug_log( $this->addlMsg );
        }
    }

    public function __get_fs_as_json( $files ) {
        global $wp_filesystem;
        $js_file = trailingslashit( $wp_filesystem->wp_content_dir() )
                   . trailingslashit( 'wp_backup' )
                   . 'files.json';
        if (! $wp_filesystem->exists( dirname( $js_file )))
            wp_mkdir_p( dirname( $js_file ));
        $wp_filesystem->put_contents( $js_file, json_encode( $files ), false );

        // $fsha1 = sha1_file( $file_path );
        // return fsha1;
    }
}
?>
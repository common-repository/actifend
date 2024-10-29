<?php
/**
 * Utility
 *
 * This Utility class contain function for commonly used by the plugin and validations used by other classes.
 *
 */
class Utility {

    private $user_asset_id;

    public static function userAssetID() {
        $utilObj = new Utility();
    	$info = $utilObj->getActifendInfo();
    	return $info->asset_id;
    }

    /**
     * getActifendInfo
     *
     * Get the user asset id from the database.
     * @return object  .
     */
    public function getActifendInfo() {
        try {
            global $wpdb;
            $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;
            $actifend_results = $wpdb->get_row("SELECT * FROM $actifend_table_name");
            return $actifend_results;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x02: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_postViaCurl
     *
     * This function is used to post the data to api endpoints.
     *
     * @param string $actifend_url url of the API.
     * @param string $actifend_params json string which send by curl.
     * @param string $method default value post which specify the method used by curl.
     * @return string json response string return
     */
    public function actifend_postViaCurl($actifend_url,
                                         $actifend_params,
                                         $method = "POST",
                                         $custom_headers = array()) {
        try {
            $heads = array('ACTIFEND_PLUGIN_VERSION' => ACTIFEND_PLUGIN_VERSION,
                           'ACTIFEND_ASSET_NAME'     => get_bloginfo( 'name' ));
            if ( !empty( $custom_headers ) ) {
                $heads = array_merge( $heads, $custom_headers );
            }
            $response = wp_remote_request( $actifend_url, array(
                'headers' => $heads,
                'timeout' => ACTIFEND_CURL_TIMEOUT,
                'method'  => $method,
                'body'    => $actifend_params) );

            if ( is_array( $response ) && ! is_wp_error( $response ) ) {
                $return = array();
                $return['headers'] = wp_remote_retrieve_headers($response);
                $return['output'] = wp_remote_retrieve_body($response);
                return json_encode($return);
            } else {
                $error = array();
                $error['ERROR_MSG'] = wp_remote_retrieve_response_message($response);
                $error['ERROR_CODE'] = wp_remote_retrieve_response_code($response);
                $error['url'] = $actifend_url;
                $return = array();
                $return['STATUS_ID'] = '222';
                $return['STATUS_MSG'] = 'REMOTE_REQUEST_ERROR';
                $return['RESPONSE'] = $error;
                // echo "<pre>";print_r($error);die;
                return json_encode( $return );
            }

        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x05: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_validIP
     *
     * This function is used to validate the IP address or range of ip address.
     * @param string $ip ip address string
     * @return boolean
     */
    public function actifend_validIP( $ip ) {
        if (preg_match("^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}^", $ip))
            return true;
        else
            return false;
    }

    /**
     * is_dir_empty
     *
     * This function is used to check if a folder is empty
     * @param folder name to check if empty
     * @return boolean
     */
    public function is_dir_empty( $dir ) {
        if ( !is_readable( $dir )) return NULL;
        $handle = opendir( $dir );
        while ( false !== ( $entry = readdir( $handle )) ) {
            if ( $entry != '.' && $entry != '..' ) {
            return false;
            }
        }
        return true;
    }

    /**
     * actifend_rrmdir
     *
     * This function is used to remove the directory that is not empty.
     *
     * @param string $path path of directory
     *
     */
    public function actifend_rrmdir( $path ) {
        // should not be used for wp-content folder
        try {
            global $wp_filesystem;
            // if ( $wp_filesystem->delete( $path, true ) )
            //     return true;
            // else
            //     return false;

            $files = list_files( $path );
            foreach ( $files as $file ) {
                $tmpdir = trailingslashit( $wp_filesystem->wp_content_dir() )
                          . 'actifend_tmp';
                if ( strstr( $file, UPLOADS_DIR )
                    || strstr( $file, $tmpdir ) ) continue;

                if ( is_file( $file ) ) {
                    $wp_filesystem->delete( $file );
                }
            }

           // Open the source directory to read in files
            // $i = new DirectoryIterator($path);
            // foreach ($i as $f) {
            //     if ($f->isFile()) {
            //         @unlink($f->getRealPath());
            //     } elseif (!$f->isDot() && $f->isDir()) {
            //         $tmpdir = trailingslashit(WP_CONTENT_DIR) . 'actifend_tmp';
            //         if (($f == UPLOADS_DIR) || ($f == $tmpdir)) continue;
            //         $this->actifend_rrmdir($f->getRealPath());
            //     }
            // }
            return true;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x16: ' . $e->getMessage() );
        }
    }

    /**
     * reset_actifend_crons
     * Disable / Enable crons that are accessing remote api
     * so they do not interfere with the running process
     * @param activate to be true or false
     */
    public function reset_actifend_crons( $activate ) {
        if ( $activate == 'remove' ) {
            remove_action( 'init', 'Actifend::enable_actifend_crons' );
            remove_filter( 'cron_schedules', 'Actifend::actifend_crons' );
            Actifend::clear_actifend_crons();
            debug_log( 'Actifend crons cleared!' );
        }

        if ( $activate == 'add' ) {
            add_action( 'init', 'Actifend::enable_actifend_crons' );
            Actifend::enable_actifend_crons();
            add_filter( 'cron_schedules', 'Actifend::actifend_crons' );
            debug_log( 'Actifend crons re-initiated!' );
        }
    }

    /**
     * pclZipData
     * This function is used to zip directory content using pcl zip
     * This will create a .zip file
     * @param string source dirname
     * @param string destination zip file
     * @return boolean
     * added in v1.3.7
     */
    public function pclZipData( $source, $dest ) {
        try{
			if ( ! class_exists( 'PclZip' ) ) {
                require( trailingslashit( ADMIN_DIR ) . trailingslashit( 'includes' ) . 'class-pclzip.php' );
			}

            $v_remove = $source;
            // To support windows and the C: root you need to add the
            // following 3 lines, should be ignored on linux
            // http://www.phpconcept.net/pclzip/faq#faq05
            if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
                if ( substr($source, 1, 1 ) == ':' ) {
                    $v_remove = substr( $source, 2 );
                }
            }

            $zip = new PclZip( $dest );
            if ( $zip->create( $source, PCLZIP_OPT_REMOVE_PATH, $v_remove ) == 0 ) {
                return false;
            }
            return true;
        }catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x51: ' . $e->getMessage() );
        }
    }

    /**
     * pclExtractZipData
     * This function is used to zip directory content using pcl zip
     * This will create a .zip file
     * @param string source dirname
     * @param string destination zip file
     * @return boolean
     * added in v1.3.7
     */
    public function pclExtractZipData( $source, $dest ) {
        try{
			if ( ! class_exists( 'PclZip' ) ) {
                require( trailingslashit( ABSPATH ) . 'wp-admin/includes/class-pclzip.php' );
			}

            $zip = new PclZip( $source );
            if ($zip->extract( PCLZIP_OPT_PATH, $dest, PCLZIP_OPT_STOP_ON_ERROR ) == 0 ) {
                debug_log( "$source extract to $dest failed." );
                return false;
            }
            debug_log( "$source extracted to $dest." );
            return true;
        }catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x52: ' . $e->getMessage() );
        }
    }

    /**
     * zipData
     *
     * This function is used to zip directory content.
     *
     * @param string $source sources directory.
     * @param string $destination destination directoy path where you want to create the zip file.
     * @return boolean
     */
    public function zipData( $source, $destination ) {
        try {
            if ( extension_loaded( 'zip' ) ) {
                if ( file_exists( $source ) ) {
                    $zip = new ZipArchive();
                    if ( $zip->open( $destination, ZIPARCHIVE::CREATE ) ) {
                        $source = realpath( $source );
                        if (is_dir( $source )) {
                            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source ), RecursiveIteratorIterator::SELF_FIRST );
                            foreach ( $files as $file ) {
                                $file = realpath( $file );
                                if ( is_dir( $file ) ) {
                                    $zip->addEmptyDir( str_replace( trailingslashit( $source ), '', trailingslashit( $file ) ) );
                                } elseif ( is_file( $file ) ) {
                                    $zip->addFromString( str_replace( trailingslashit( $source ), '', $file ), file_get_contents( $file ) );
                                }
                            }
                        } elseif ( is_file( $source )) {
                            $zip->addFromString( basename( $source ), file_get_contents( $source ));
                        }
                    }
                    return $zip->close();
                }
            }
            return false;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x36: ' . $e->getMessage());
        }
    }

    /**
     * actifend_unzip
     *
     * Function unzip the zipped file and create the directory.
     *
     * @param string $source_path zip file path
     * @param string $destination_path directory where zip file extract.
     * @return boolean
     */
    public function actifend_unzip( $source_path, $destination_path ) {
        try {
            $zip = new ZipArchive;
            $res = $zip->open( $source_path );
            mkdir( $destination_path );
            chmod( $destination_path, 0777 );
            if ( $res == true ) {
                $zip->extractTo( $destination_path );
                $zip->close();
                $this->chmod_r( $destination_path );
                debug_log( "$source_path extracted to $destination_path" );
                return true;
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x40: ' . $e->getMessage() );
        }
    }

    /**
     * chmod_r
     *
     * This function is used to change the permission mode of the files in directory.
     * @param string $path directory which directory structure
     *
     */
    public function chmod_r( $path ) {
        try{
            $dir = new DirectoryIterator( $path );
            foreach ( $dir as $item ) {
                if ( $item->isDot() ) continue;
                if ( $item->isDir() ) {
                    @chmod( $item->getPathname(), 0775 );
                    $this->chmod_r( $item->getPathname() );
                }elseif ( !chmod( $item->getPathname(), 0775 ) ) {
                    debug_log( "Could NOT do chmod for " . $item->getPathname() );
                    return false;
                }
            }
            return true;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x41: ' .$e->getMessage() );
        }
    }

    /**
     * getViaCurl
     *
     * This function is used get the data from API endpoint.
     *
     * @param string $uri API enpoint URL
     * @param string $asset_id user asset id
     * @param string $end_point select the endpiont.
     * @return string $response response of the curl resquest.
     */
    public function getViaCurl( $uri, $asset_id, $end_point, $headers=array() ) {
        $url = $uri . $asset_id . "/" . $end_point;
        $args = array( 'timeout' => ACTIFEND_CURL_TIMEOUT );
        $vers = array(
            'ACTIFEND_PLUGIN_VERSION' => ACTIFEND_PLUGIN_VERSION,
            'ACTIFEND_ASSET_NAME'     => get_bloginfo( 'name' )
        );

        $headers = array_merge( $vers, $headers );
        $heads = array( 'headers' => $headers );
        $args = array_merge( $args, $heads );
        $response = wp_remote_get($url, $args);
        if ( is_array( $response ) && ! is_wp_error( $response ) ) {
            return json_decode( $response['body'], true );
        } else {
            return $response;
        }
    }

    /**
     * actifend_validEmail
     *
     * Function validate the email id used in plugin registration.
     *
     * @param string $actifend_email  registration email id
     * @return  boolean
     */
    public function actifend_validEmail( $actifend_email ) {
        $email = $actifend_email;
        $email = filter_var( $email, FILTER_SANITIZE_EMAIL );
        // Validate e-mail
        // 9/5/2017 v1.4.6.4 - added domain check against MX records
        if ( !filter_var( $email, FILTER_VALIDATE_EMAIL ) === false ) {
            list( $user, $domain ) = explode('@', $email );
            return checkdnsrr( $domain, 'MX' );
        } else {
            return false;
        }
    }

    /**
     * actifend_rrmdir_wp_content
     *
     * Function remove the wp-content directory content except wp_backup directory from wp-content directory.
     *
     * @param string $actifend_email  registration email id
     * @return  boolean
     */
    public function actifend_rrmdir_wp_content( $path, $wpcontent2skip=array() ) {
        try {
            global $wp_filesystem;
            $i = new DirectoryIterator( $path );
            // $wpcontent2skip = array('uploads', 'actifend_tmp', 'wflogs');
            foreach ( $i as $f ) {
                $backup_dir = $f->getRealPath();
                if ( strcmp( $backup_dir, BACKUP_DIR ) !== 0 ) {
                    @$ext = pathinfo( $f, PATHINFO_EXTENSION );
                    if ( $f->isFile() && $ext != 'log' ) {
                        // delete the real folder / file
                        $wp_filesystem->delete( $f->getRealPath() );
                    } elseif ( !$f->isDot() && $f->isDir() ) {
                        if ( !in_array( $f, $wpcontent2skip ) ) {
                            $this->actifend_rrmdir_wp_content( $f->getRealPath() );
                        }
                    }
                }
            }
            // debug_log($path . " folder deleted recursively!");
            return true;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x45: ' .$e->getMessage() );
        }
    }

    /**
     * url_origin
     *
     * Function used to get exact origin url of the page accessed.
     *
     * @param string $s $_SERVER server poperties.
     * @param string $use_forwarded_host
     * @return  string return origin url.
     */
    private function url_origin( $s, $use_forwarded_host = false ) {
        $ssl = ( !empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
        $sp = strtolower( $s['SERVER_PROTOCOL'] );
        $protocol = substr( $sp, 0, strpos($sp, '/') ) . ( ( $ssl ) ? 's' : '' );
        $port = $s['SERVER_PORT'];
        $port = ( !$ssl && $port == '80' || $ssl && $port == '443' ) ? '' : ':' . $port;
        $host = ( $use_forwarded_host && isset($s['HTTP_X_FORWARDED_HOST']) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset($s['HTTP_HOST']) ? $s['HTTP_HOST'] : null );
        $host = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;
        return $protocol . '://' . $host;
    }

    /**
     * full_url
     *
     * Function provide the full origin url.
     *
     * @param string $s $_SERVER server poperties.
     * @param string $use_forwarded_host by defaul false.
     * @return  string return origin url.
     */
    public function full_url( $s, $use_forwarded_host = false ) {
        return $this->url_origin( $s, $use_forwarded_host ) . $s['REQUEST_URI'];
    }


    public function actifend_isValidAssetId( $asset_id, $response_code ) {
        $asset_id = trim( $asset_id );
        $response_code = trim( $response_code );
        if ( strlen( $asset_id ) <= 12 && ctype_alnum( $asset_id ) && $response_code == '2000' ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * actifend_pluginInstallation_log
     *
     * Function send the detail of intalled theme  and plugin  when plugin register
     */
    public function actifend_pluginInstallation_log() {
        try {
            Actifend::actifend_update_check();
            $asset_id = '';
            $result   = $this->getActifendInfo();
            $path = plugin_dir_path( __FILE__ );

            $reportObj = new report;
            $plugin_install_status = $reportObj->get_status();
            $flag                  = $plugin_install_status['status'];

            if ( $flag == '0' ) {
                if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                    $asset_id          = $result->asset_id;
                    $default_end_point = $result->default_end_point;
                    $size_of_response  = 0;
                    if ( count($_POST) > 0 ) {
                        $size_of_response = strlen( implode( " ", $_POST ) );
                    }
                    $installArray      = array();
                    $installed_plugins = array();
                    $count             = 1;
                    if ( !function_exists( 'get_plugins' ) ) {
                        require_once( trailingslashit( ADMIN_DIR ) . 'includes/plugin.php' );
                    }
                    $all_plugins = get_plugins();
                    foreach ( $all_plugins as $installed_plugins ) {
                        $installArray[] = array(
                                                "name" => @$installed_plugins['Name'],
                                                "version" => @$installed_plugins['Version']
                                                );
                    }

                    $insatlled_plugin_info = array();
                    $insatlled_plugin_info['admin_email'] = get_bloginfo( 'admin_email' );
                    $insatlled_plugin_info['wp_version'] = get_bloginfo( 'version' );
                    $insatlled_plugin_info['installed_plugins'] = $this->actifendPluginsLatestVersion();
                    $insatlled_plugin_info['installed_themes'] = $this->actifendThemesLatestVersion();

                    $final_end_point = ACTIFEND_ASSETS_END_POINT . $asset_id . "/wpinfo";
                    $actifend_json   = json_encode( $insatlled_plugin_info );
                    $res             = $this->actifend_postViaCurl( $final_end_point, $actifend_json, "PATCH" );
                    $res_json        = json_decode( $res );

                    if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                        if ( ACTIFEND_DEBUG_MODE_ON ) {
                            $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                        } else {
                            $res = "EXCEPTION: While opening " . $final_end_point;
                        }
                    } else {
                        $res = "ASSET ID: " . $asset_id;
                        global $wpdb;
                        $actifend_update_table = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
                        // $update_query_version  = "
                        //     UPDATE `" . $actifend_update_table . "` SET `status`='1' WHERE `status`='0';";

                        // if (!function_exists('dbDelta')) {
                        //     require_once(trailingslashit( ADMIN_DIR ) . 'includes/upgrade.php');
                        // }
                        // dbDelta($update_query_version);
                        $wpdb->update($actifend_update_table,
                                      array( 'status' => '1' ),
                                      array( 'status' => '0' )
                               );

                        $actifend_update_theme_info = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
                        // $update_query = "
                        //     UPDATE `" . $actifend_update_theme_info . "` SET `status`='1' WHERE `status`='0';";

                        // dbDelta($update_query);
                        $wpdb->update($actifend_update_theme_info,
                                      array( 'status' => '1' ),
                                      array( 'status' => '0' )
                               );
                    }

                    // echo "\r\n" . '<meta name="actifend" content="Response: ' . $res . '" />';
                    // echo "\r\n";
                }
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception('Exception 1x24: ' . $e->getMessage());
        }
    }

    /**
     * actifendPluginsLatestVersion
     *
     * Function is check the latest update available for the plugin.
     * @return array $plugin_deatil return the plugins with latest available update.
     */
    private function actifendPluginsLatestVersion() {
        try {
            $pluin_detail = array();
            if ( !function_exists( 'get_plugins' ) ) {
                require_once( trailingslashit( ADMIN_DIR ) . 'includes/plugin.php' );
            }
            $all_plugins = get_plugins();
            if (!function_exists( 'wp_update_plugins' )) {
                require_once( trailingslashit( INCLUDES_DIR ) . 'update.php' );
            }
            // This will set the site transient update_plugins
            wp_update_plugins();
            $update_plugins  = get_site_transient( 'update_plugins' );
            $update_response = $update_plugins->response;

            foreach ( $update_plugins->checked as $key => $value ) {
                $new_version = $value;
                if ( !empty( $update_response ) && isset( $update_response[$key] ) ) {
                    $new_version = $update_response[$key]->new_version;
                }
                $plugin_detail[] = array(
                                        'name'    => $all_plugins[$key]['Name'],
                                        'version' => $all_plugins[$key]['Version'],
                                        'latest'  => $new_version
                                        );
            }
            if ( !empty( $plugin_detail ) ) {
                return $plugin_detail;
            } else {
                return;
            }
        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x28: ' . $e->getMessage() );
        }
    }

    /**
     * actifendThemesLatestVersion
     *
     * Function is check the latest update available for the themes.
     * @return array $themeArray return the themes with latest available updates.
     */
    private function actifendThemesLatestVersion() {
        try {
            if ( !function_exists( 'wp_update_themes' ) ) {
                require_once( trailingslashit( ADMIN_DIR ) . 'includes/theme.php' );
            }

            wp_update_themes(); // Check for Theme updates
            $update_themes = get_site_transient( 'update_themes' );
            $theme_update_avail = $update_themes->response;

            $themes     = wp_get_themes();
            $themeArray = array();
            foreach ( $themes as $name => $theme ) {
                $new_verison = $theme->get('Version');
                if ( !empty( $theme_update_avail[$name] ) && isset( $theme_update_avail[$name] ) ) {
                    $new_verison = $theme_update_avail[$name]['new_version'];
                }
                $themeArray[] = array(
                                    'name'    => $theme->get('Name'),
                                    'version' => $theme->get('Version'),
                                    'latest'  => $new_verison
                                    );
            }
            if ( !empty( $themeArray ) ) {
                return $themeArray;
            } else {
                return;
            }
        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x29: ' . $e->getMessage() );
        }
    }

    public static function initFileSystem() {
        // check if file system permissions are there for the user
        global $wp_filesystem;
        $url = admin_url( 'admin.php' ) . '?page=get_actifend_email';
        $credentials = request_filesystem_credentials( wp_nonce_url( $url ) );
        WP_Filesystem( $credentials );
        if ( $wp_filesystem->errors->get_error_code() ) {
            foreach ( $wp_filesystem->errors->get_error_messages() as $message )
                debug_log( $message );
            exit;
        }
    }

    /**
     * check_file privileges
     * Check file privileges so restore can happen properly
     * @param array of files & folders to check
     * @return sets the option FilePrivilegesInsufficient value to 1 if
     * privileges are insufficient
     */
    public function check_file_privileges() {
        $this->initFileSystem();
        global $wp_filesystem;

        $fileList = array(
            trailingslashit( $wp_filesystem->abspath() ) . 'wp-admin',
            trailingslashit( $wp_filesystem->abspath() ) . 'wp-includes',
            $wp_filesystem->wp_content_dir(),
            $wp_filesystem->wp_plugins_dir(),
            $wp_filesystem->wp_themes_dir()
        );
        // By default assumption is that all folders are not writeable
        add_option( 'FilePrivilegesInsufficient', 1 );

        foreach ( $fileList as $value ) {
            if ( is_link( $value ) )
                $dirVal = readlink( $value );
            else
                $dirVal = $value;

            if ( ! @is_writable( $dirVal ) ) {
                debug_log( "$dirVal is not writeable!" );
                update_option( 'FilePrivilegesInsufficient', 1 );
                return 1;
            }
        }
        return 0;
    }

    /**
     * get_asset_status()
     * Gets the status of the asset on the app
     * @param string $actifend_url
     * @param string $actifend_h1
     * @param string $actifend_h2
     * @param string $actifend_fqdn
     * @return string $return
     */
    public function get_asset_status( $actifend_fqdn, $iso ) {
        try {
            $utilObj = new Utility;
            $actifend_timestamp = date( 'Y-m-d' );
            $actifend_asset_name = get_bloginfo( 'name' );

            $actifend_req = $actifend_asset_name . $actifend_timestamp . 'wordpress' . ACTIFEND_SALT2;
            $actifend_reqhash = hash_hmac('sha512',
                                          utf8_encode($actifend_req),
                                          utf8_encode(ACTIFEND_SALT1));

            $custom_headers = array('actifend_client_id' => ACTIFEND_SALT2,
                                    'ACTIFEND_PLUGIN_VERSION' => ACTIFEND_PLUGIN_VERSION,
                                    'ACTIFEND_NAME'  => $actifend_asset_name,
                                    'actifend_signature' => $actifend_reqhash);
            $actifend_params = array('ACTIFEND_FQDN'  => $actifend_fqdn,
                                     'ACTIFEND_NAME'  => $actifend_asset_name,
                                     'ACTIFEND_EMAIL' => $iso);

            $response = $utilObj->actifend_postViaCurl(ACTIFEND_REGISTER_END_POINT,
                                                       $actifend_params, "GET", $custom_headers);
            $RESULT = json_decode( $response );
            $OUT = @json_decode( $RESULT->output );

            if (!empty($OUT)) {
                if ( $OUT->ResponseCode == '2000' && $OUT->Message == 'success' ) {
                    // It create the backup of the files of the wordpress//
                    $status = $OUT->Result[0];
                    $asset_id = $OUT->Result[1];
                    update_option( 'mapp_activated', ( strcmp( strtolower( $status ), 'false') === 0 ) ? 1 : 0 );
                }
            }

            debug_log("get_asset_status executed!");
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return $e->getMessage();
        }
    }

    /**
     * get_subscription_status()
     * Gets the subscription status of the asset
     * @param string $actifend_url
     * @param string $actifend_h1
     * @param string $actifend_h2
     * @param string $actifend_fqdn
     * @return string $return
     */
    public static function get_subscription_status() {
        try {
            $utilObj = new Utility;
            $asset_info = $utilObj->getActifendInfo();
            $asset_id = $asset_info->asset_id;
            $actifend_timestamp = date( 'Y-m-d' );
            $actifend_asset_name = get_bloginfo( 'name' );

            $actifend_req = $actifend_asset_name . $actifend_timestamp . 'wordpress' . ACTIFEND_SALT2;
            $actifend_reqhash = hash_hmac('sha512',
                                          utf8_encode($actifend_req),
                                          utf8_encode(ACTIFEND_SALT1));

            $custom_headers = array('actifend_client_id' => ACTIFEND_SALT2,
                                    'actifend_signature' => $actifend_reqhash,
                                    'ACTIFEND_PLUGIN_VERSION' => ACTIFEND_PLUGIN_VERSION,
                                    'ACTIFEND_NAME' => $actifend_asset_name);

            $response = $utilObj->getViaCurl(ACTIFEND_ASSETS_END_POINT,
                                             $asset_id,
                                             'wpsubsinfo',
                                             $custom_headers);
            if ( isset( $response )
                && !empty( $response )
                && array_key_exists( 'ResponseCode', $response )
                && !is_wp_error( $response ) ) {
                if (($response['ResponseCode'] == '2000') && ($response['Message'] == 'success')) {
                    update_option("actifend_usage_category", $response['Result']['category']);
                    // $subs_valid_till = substr($response['Result']['valid_till'], 5);
                    $subs_plan = $response['Result']['subscribed_plan'];
                    // update_option("actifend_subs_validity", $subs_valid_till);
                    @$current_plan = get_option( 'actifend_subs_plan' );
                    update_option( 'actifend_subs_plan', $subs_plan );
                    update_option( 'actifend_plan_changed', ( $current_plan != $subs_plan ) ? true : false );

                    return $response['Result'];
                } else {
                    @debug_log( 'Response is ' . $response );
                    return false;
                }
            } else {
                debug_log( 'Response is invalid.' );
                return false;
            }

            debug_log( 'get_subscription_status executed!' );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return $e->getMessage();
        }
    }

    /**
     * get_size_of_folder
     * gets size of the folder including files and sub-folders in it
     * @param $folder name of the folder (with path)
     * @return size of folder (including files and folders inside) in bytes
     */
    public static function get_size_of_folder( $folder ) {
        if ( ! empty( $folder ) ) {
            $dir = new RecursiveDirectoryIterator( $folder, RecursiveDirectoryIterator::FOLLOW_SYMLINKS );
            $iter = new RecursiveIteratorIterator( $dir, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
            $result = 0;

            while ( $iter->valid() ) {
                if ( !$iter->isDot() ) {
                    $result += filesize( $iter->key() );
                }
                $iter->next();
            }

            return $result;
        } else {
            debug_log( 'No folder given to get size of...' );
            return false;
        }
    }

    public static function update_assetid( $actifend_fqdn, $iso ) {
        try {
            $status = get_asset_status( $actifend_fqdn, $iso );
            $asset_id = $status[1];
            // update the database
            global $wpdb;
            $last_checked = date( 'Y-m-d H:i:s' );
            $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;
            $res = $wpdb->update($actifend_table_name,
                                array("asset_id"       => $asset_id,
                                      "last_checked"   => $last_checked),
                                array("actifend_email" => $iso));

            if ( false === $res ) {
                debug_log( 'DB record not updated with email.' );
                return false;
            }
            return true;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return $e->getMessage();
        }
    }

    // Checks if a variable is of type associated array
    public static function is_assoc( $var ) {
        return is_array( $var ) && array_diff_key( $var, array_keys( array_keys( $var ) ) );
    }

    /**
     * Generate a 403 response for a request
     *
     * @since 1.5.3
     */
    public static function generate_403() {
        header('HTTP/1.1 403 Forbidden');
        header('Status: 403 Forbidden');
        header('Connection: Close');
        debug_log("403 generated.");
        exit;
    }

    // disabling the ability to call the xmlrpc pinback methods
    public static function afend_remove_xmlrpc_pingback( $methods ) {
        // unset( $methods['system.multicall'] );
        unset( $methods['pingback.ping'] );
        unset( $methods['pingback.extensions.getPingbacks'] );
        return $methods;
    }

}
?>
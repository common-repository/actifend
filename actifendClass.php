<?php

require_once( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'report.php');

if ( !function_exists( 'dbDelta' ) ) {
    require_once( trailingslashit( ADMIN_DIR ) . 'includes/upgrade.php' );
}

if ( !function_exists( 'get_plugins' )) {
    require_once( trailingslashit( ADMIN_DIR ) . 'includes/plugin.php' );
}

if ( !function_exists( 'wp_get_themes' ) ) {
    require_once( trailingslashit( ADMIN_DIR ) . 'includes/theme.php' );
}


class Actifend {

    // static vars
    public static $actifendSchedules = array(
        'min_all_processes',
        'daily_wpcore_update');

    public static $scheduleHooks = array(
        'min_all_processes'     => 'run_every_minute',
        'daily_wpcore_update'   => 'check_wpcore_update');
    // init - Fires after WordPress has finished loading but before any headers are sent.
    // wp_footer - Is triggered near the </body> tag of the user's template by the wp_footer() function.
    // wp_head - Is triggered within the <head></head> section of the user's template by the wp_head() function
    // admin_head - https://codex.wordpress.org/Plugin_API/Action_Reference/admin_head
    // login_form - This action hook fires following the "password" field in the login form.
    // admin_init -  Is triggered before any other hook when a user accesses the admin area.
    // wp_login -  Is triggered when a user logs in by the wp_signon() function.
    // wp_login_failed - Is triggered when a user login fails
    // wp_logout - Is triggered when a user logs out using the wp_logout() function.
    // upgrader_process_complete - Is run when the download process for a plugin install or update finishes.
    // switch_theme - It fires after the theme has been switched
    // check_wpcore_update - https://codex.wordpress.org/Plugin_API/Action_Reference/check_wpcore_update
    // activated_plugin - Is run immediately after any plugin is activated
    // deactivated_plugin - Is run immediately after any plugin is deactivated
    public static $actifend_actions = array(
        'plugins_loaded'        => 'blockBadQueries',
        'init'                  => 'Actifend::enable_actifend_crons',  // ensure cron events are scheduled
        'wp_footer'             => 'AccessLogs',  //v1.5.3 changed from wp_head to wp_footer
        'admin_head'            => 'AccessLogs',
        'login_form'            => 'AccessLogs',
        'activated_plugin'      => 'pluginUpdatelog',   // send plugin activated log
        'deactivated_plugin'    => 'pluginUpdatelog',   // send plugin deactivated log
        'wp_login'              => array('logInSuccess', 'getBlockedIPList'),   // login success, list of IPs blocked
        'admin_init'            => 'actifend_dismiss_usage_notice', // dismiss usage admin notice for that session
        'wp_logout'             => 'reset_dismiss_usage_notice', // resets dismiss usage admin notice
        'wp_login_failed'       => 'loginFailureAccess',   // login failure
        'switch_theme'          => 'actifendUpdatetheme',   // when theme is changed
        'upgrader_process_complete' => 'updateInstallationLogs',   // when a theme, plugin or core update is done
        'check_wpcore_update'   => 'availableWordPressUpdates',    // wordpress update available
        'run_every_minute'      => 'processes_running_every_minute');

    /**
     * initTmpDir
     * Function create the temp directory to create the log files.
     */
    public static function initTmpDir() {
        try {
            $tmp_dir = trailingslashit( WP_CONTENT_DIR ) . 'actifend_tmp';
            if (!file_exists($tmp_dir)) @mkdir($tmp_dir, 0750);

            @file_put_contents(trailingslashit( $tmp_dir ) . '.htaccess', 'deny from all');
            ini_set('error_log', trailingslashit( $tmp_dir ) . 'debug_tmp.log');
        } catch (Exception $e) {
            throw new Exception('Exception 1x01: ' . $e->getMessage());
        }
    } // end initTmpDir

    /**
     * isAdmin
     * return true if user is admin
     * @param user
     */
    public static function isAdmin( $user = false ) {
		if( $user ){
            if ( user_can($user, 'manage_options' ) ) {
                return true;
            }
		}
		return false;
	}

    /**
     * activation_warning
     * Displays any errors occured during activation
     *
     */
    public static function activation_warning() {
        $activationError = get_option( 'actifend_plugin_act_error', '' );
        if ( strlen( $activationError ) > 400 ) {
            $activationError = substr( $activationError, 0, 500 ) . '...[output truncated]';
        }
        if ( $activationError ) {
            echo '<div id="actifendActivationWarning" class="updated fade"><p><strong>Actifend generated an error during activation. The output received was:</strong> ' . wp_kses($activationError, array()) . '</p></div>';
        }
        delete_option( 'actifend_plugin_act_error' );
        // Actifend::actifend_dropTable();
        deactivate_plugins( dirname(__FILE__) . '/actifend.php' );
        exit;
    }

    /**
     * actifend_crons
     * sets the schedule for the actifend crons
     *
     */
    public static function actifend_crons( $schedules ) {
        $everyMinute = array('interval' => 60,
                             'display'  => __('Every Minute'));
        $onceDaily   = array('interval' => 86400,
                             'display'  => __('Once Daily'));

        // foreach (self::$actifendSchedules as $schedule) {
        //     if (substr($schedule, 0, 5) == 'daily') {
        //         $schedules[$schedule] = $onceDaily;
        //     }else {
        //         $schedules[$schedule] = $everyMinute;
        //     }
        // }
        $schedules['min_all_processes']   = $everyMinute;
        $schedules['daily_wpcore_update'] = $onceDaily;

        return $schedules;
    }

    /**
     * enable_actifend_crons
     * Enables cron jobs related to this plugin
     */
    public static function enable_actifend_crons() {
        // cron jobs
        foreach ( self::$scheduleHooks as $schedule => $hook ) {
            if ( ! wp_next_scheduled ( $hook ) ) {
                wp_schedule_event( current_time( 'timestamp', 1 ), $schedule, $hook );
                debug_log( "$schedule cron job scheduled." );
            }
        }
        // debug_log("Actifend cron jobs scheduled.");
    }

    /**
     * clear_actifend_crons
     * Disables / clears cron jobs related to this plugin
     */
    public static function clear_actifend_crons() {
        foreach ( self::$scheduleHooks as $schedule => $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        debug_log( 'Actifend cron schedules cleared!' );
    }

    /**
     * add_actifend_actions
     * adds various actions required for functioning of the plugin
     */
    public static function add_actifend_actions() {
        foreach ( self::$actifend_actions as $action => $hook ) {
            if ( is_array( $hook ) ) {
                foreach ( $hook as $eachHook ) {
                    add_action( $action, $eachHook );
                }
            }else {
                add_action( $action, $hook );
            }
        }
    }

    /**
     * remove_actifend_actions
     * remove various actions required for functioning of the plugin
     */
    public static function remove_actifend_actions() {
        foreach ( self::$actifend_actions as $action => $hook ) {
            if ( is_array( $hook ) ) {
                foreach ( $hook as $eachHook ) {
                    remove_action( $action, $eachHook );
                }
            } else {
                remove_action( $action, $hook );
            }
        }
    }

    /**
     * actifend_deletedPlugins
     * Function check if any of the plugin deleted then update the plugin table in database.
     */
    public static function actifend_deletedPlugins() {
        try {
            $utiObj = new Utility;
            $result =$utiObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $reportObj = new report;
                $new_plugin_version = $reportObj->new_version_table();
                $old_plugin_version = $reportObj->old_version_table( 'plugin' );
                if ( !empty( $old_plugin_version ) ) {
                    foreach ( $old_plugin_version as $key1 => $value1 ) {
                        if ( !in_array($value1, $new_plugin_version) ) {
                            // $del_name    = $value1['name'];
                            // $del_version = $value1['version'];
                            global $wpdb;
                            $plugin_detail_table  = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
                            $wpdb->delete( $plugin_detail_table, array( 'name' => $value1['name'] ) );
                        }
                    }
                }
            }
        debug_log('actifend_deletedPlugins executed!');
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x05: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_update_check
     * Function check if the actifend updated then create the database tables.
     */
    public static function actifend_update_check() {
        try {
            $utiObj = new Utility;
            $result = $utiObj->getActifendInfo();
            date_default_timezone_set( 'UTC' );
            $timeNow = date( 'Y-m-d H:i:s' );
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                global $wpdb;
                $charset_collate = $wpdb->get_charset_collate();
                $actifend_ip_table    = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                $actifend_sql_command = "
                    CREATE TABLE IF NOT EXISTS `" . $actifend_ip_table . "` (
                    `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `ips` longtext NOT NULL,
                    `entry_time` datetime NULL,
                    `ban_for` int(4) DEFAULT 3600 NOT NULL,
                    PRIMARY KEY  (id)
                    ) $charset_collate;";
                dbDelta( $actifend_sql_command );

                $actifend_theme_table = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
                $actifend_theme_sql   = "
                    CREATE TABLE IF NOT EXISTS `" . $actifend_theme_table . "` (
                    `pid` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(100)  NOT NULL,
                    `version` varchar(100) NOT NULL,
                    `author` varchar(100)  NOT NULL,
                    `update_last_time` datetime NULL,
                    `status` varchar(2) NULL DEFAULT '0',
                    PRIMARY KEY  (pid)
                    ) $charset_collate;";
                dbDelta( $actifend_theme_sql );

                $installed_plugin_table = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
                $install_sql_query = "
                    CREATE TABLE IF NOT EXISTS `" . $installed_plugin_table . "` (
                    `pid` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(100)  NOT NULL,
                    `version` varchar(100) NOT NULL,
                    `author` varchar(100)  NOT NULL,
                    `update_last_time` datetime NULL,
                    `status` varchar(2) NULL DEFAULT '0',
                    PRIMARY KEY  (pid)
                    ) $charset_collate;";

                dbDelta( $install_sql_query );
                $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
                $data = $wpdb->get_results("SELECT name,version FROM `" . $actifend_table_name . "`;");
                if (empty($data)) {
                    $all_plugins = get_plugins();
                    foreach ($all_plugins as $plugin) {
                        $wpdb->insert($installed_plugin_table,
                                      array(
                                        'name'              => $plugin['Name'],
                                        'version'           => $plugin['Version'],
                                        'author'            => $plugin['Author'],
                                        'update_last_time'  => $timeNow
                                      ));
                    }
                }

                $actifend_table_name = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
                $themedata           = $wpdb->get_results("SELECT name,version FROM `" . $actifend_table_name . "`;");
                if ( empty( $themedata ) ) {
                    $themes = wp_get_themes();
                    foreach ( $themes as $name => $theme ) {
                        $wpdb->insert($actifend_theme_table,
                                      array(
                                        'name'              => $theme->get('Name'),
                                        'version'           => $theme->get('Version'),
                                        'author'            => $theme->get('Author'),
                                        'update_last_time'  => $timeNow
                                      ));
                    }
                }
            }
        debug_log( 'actifend_update_check executed!' );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x06: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_deletedThemes
     * Function check if any of the theme deleted then update the theme table in database.
     */
    public static function actifend_deletedThemes() {
        try {
            // self::actifend_update_check();
            $utiObj = new Utility;
            $result = $utiObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                // $asset_id = $result->asset_id;
                // $default_end_point = $result->default_end_point;
                // $path = plugin_dir_path(__FILE__);
                $reportObj = new report;
                $new_theme_version = $reportObj->theme_new_version();
                $old_theme_version = $reportObj->old_version_table( 'theme' );
                // $del_name = '';
                if ( !empty( $old_theme_version ) )
                    foreach ( $old_theme_version as $key1 => $value1 ) {
                        if ( !in_array($value1, $new_theme_version) ) {
                            // $del_name = $value1['name'];
                            // $del_version = $value1['version'];
                            global $wpdb;
                            $theme_detail_table = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
                            $wpdb->delete( $theme_detail_table, array( 'name' => $value1['name'] ) );
                        }
                    }
            }
            debug_log( 'actifend_deletedThemes executed!' );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x08: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_createTables
     * Function create the table in wordpress database for the plugin when plugin is activated.
     */
    public static function actifend_createTables() {
        try {
            global $wpdb;
            $charset_collate = $wpdb->get_charset_collate();
            $timeNow = current_time( 'mysql', true );
            $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;
            $actifend_sql = "CREATE TABLE IF NOT EXISTS`" . $actifend_table_name . "` (
                    `aid` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `asset_id` varchar(16) NOT NULL,
                    `default_end_point` text NULL,
                    `actifend_email` varchar(1024) NOT NULL,
                    `actifend_optin` tinyint(1) unsigned NOT NULL,
                    `last_checked` datetime NULL,
                    PRIMARY KEY  (aid)
                  ) $charset_collate; ";
            dbDelta( $actifend_sql );

            $actifend_ip_table = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
            $actifend_sql_command = "CREATE TABLE IF NOT EXISTS `" . $actifend_ip_table . "` (
                    `id` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `ips` longtext NOT NULL,
                    `entry_time` datetime NULL,
                    `ban_for` int(4) DEFAULT 3600 NOT NULL,
                    `set_number` varchar(12) NOT NULL,
                    PRIMARY KEY  (id)
                    ) $charset_collate;";
            dbDelta( $actifend_sql_command );

            $actifend_theme_table = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
            $actifend_theme_sql = "CREATE TABLE IF NOT EXISTS `" . $actifend_theme_table . "` (
                    `pid` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(100)  NOT NULL,
                    `version` varchar(100) NOT NULL,
                    `author` varchar(100)  NOT NULL,
                    `update_last_time` datetime NULL,
                    `status` varchar(2) NULL DEFAULT '0',
                    PRIMARY KEY  (pid)
                  ) $charset_collate;";
            dbDelta( $actifend_theme_sql );

            $installed_plugin_table = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
            $install_sql_query = "CREATE TABLE IF NOT EXISTS `" . $installed_plugin_table . "` (
                    `pid` tinyint unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(100)  NOT NULL,
                    `version` varchar(100) NOT NULL,
                    `author` varchar(100)  NOT NULL,
                    `update_last_time` datetime NULL,
                    `status` varchar(2) NULL DEFAULT '0',
                    PRIMARY KEY  (pid)
                  ) $charset_collate;";
            dbDelta( $install_sql_query );

            $actifend_integrity_files = $wpdb->prefix . ACTIFEND_INTEGRITY_FILES_TABLE;
            $create_ifiles_Table = "CREATE TABLE IF NOT EXISTS `" . $actifend_integrity_files . "` (
                            `file_path` VARCHAR(191) NOT NULL,
                            `file_size` INT NOT NULL,
                            `file_mtime` INT(10) UNSIGNED NOT NULL,
                            `file_type` VARCHAR(4) NOT NULL) $charset_collate;";
            dbDelta( $create_ifiles_Table );

            $actifend_integrity_hashes = $wpdb->prefix . ACTIFEND_INTEGRITY_HASHES_TABLE;
            $create_iHashes_Table = "CREATE TABLE IF NOT EXISTS `" . $actifend_integrity_hashes . "` (
                            `file_path` VARCHAR(191) NOT NULL,
                            `file_hash` CHAR(40) NOT NULL) $charset_collate;";
            dbDelta( $create_iHashes_Table );

            $all_plugins = get_plugins();
            foreach ( $all_plugins as $plugin ) {
                $wpdb->insert($installed_plugin_table,
                                array(
                                    "name"    => $plugin['Name'],
                                    "version" => $plugin['Version'],
                                    "author"  => $plugin['Author'],
                                    "update_last_time" => $timeNow
                                ));
            }

            $themes = wp_get_themes();
            foreach ( $themes as $name => $theme ) {
                $wpdb->insert($actifend_theme_table,
                                array(
                                    'name'    => $theme->get('Name'),
                                    'version' => $theme->get('Version'),
                                    'author'  => $theme->get('Author'),
                                    'update_last_time' => $timeNow
                                ));
            }

            debug_log( 'actifend_createTables executed!' );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x18: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_dropTable
     * Function drop the table in wordpress database that associate with plugin when plugin is deactivate.
     */
    public static function actifend_dropTable() {
        try {
            global $wpdb;
            $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;
            $del = "DROP TABLE IF EXISTS " . $actifend_table_name . "; ";
            $wpdb->query( $del );

            $installed_plugin_table = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
            $del_query = "DROP TABLE IF EXISTS " . $installed_plugin_table . "; ";
            $wpdb->query( $del_query );

            $theme_table      = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
            $drop_theme_table = "DROP TABLE IF EXISTS " . $theme_table . "; ";
            $wpdb->query( $drop_theme_table );

            // Drop this table only if ...
            if ( ACTIFEND_PLUGIN_VERSION <= '1.5.2' ) {
                $blocked_ip_list = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                $drop_blocked_ip = "DROP TABLE IF EXISTS " . $blocked_ip_list . "; ";
                $wpdb->query( $drop_blocked_ip );
            } elseif ( ACTIFEND_PLUGIN_VERSION < '1.6' ) {
                $blocked_ip_list = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                $alter_query = "ALTER TABLE {$blocked_ip_list} ADD `ban_for` int(4) DEFAULT 3600 NOT NULL AFTER `entry_time`;";
                debug_log( "{$blocked_ip_list} table definition altered. " );
                $wpdb->query( $alter_query );
            }

            // drop actifend file integrity tables
            $integrity_files_table = $wpdb->prefix . ACTIFEND_INTEGRITY_FILES_TABLE;
            $drop_integrity_files = "DROP TABLE IF EXISTS {$integrity_files_table};";
            $wpdb->query( $drop_integrity_files );

            $integrity_hashes_table = $wpdb->prefix . ACTIFEND_INTEGRITY_HASHES_TABLE;
            $drop_integrity_hashes = "DROP TABLE IF EXISTS {$integrity_hashes_table};";
            $wpdb->query( $drop_integrity_hashes );

            debug_log("actifend_dropTable executed!");
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x22: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_portTest
     * Function Used to test the outbound ports by send the request to url and then check the response.
     * @param string $test_url request url
     */
    public static function actifend_portTest( $test_url, $port ) {
        try {
            $response = wp_remote_get( $test_url );
            $response_code = wp_remote_retrieve_response_code( $response );
            if ( !in_array( $response_code, array( 301, 302, 200 ) ) ) {
//             if ( $response_code != 301
//                 && $response_code != 302
//                 && $response_code != 200 ) {
                echo 'This plugin require outbound port ' . $port . ' open. Please allow permission and try again';
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                add_action( 'admin_notices', 'Actifend::activation_warning' );
            }
            debug_log("actifend_portTest executed!");
        } catch (Exception $e) {
            debug_log($e->getMessage());
            throw new Exception("Exception 1x26: " . $e->getMessage());
        }
    }

    /**
     * actifend_prerequisiteTest
     * Function prerequisite test when plugin is activated without this plugin can't activate.
     */
    public static function actifend_prerequisiteTest() {
        try {
            $utilObj = new Utility;
            //PHP Version Check
            if ( version_compare( PHP_VERSION, '5.4.0' ) < 0 ) {
                echo 'This plugin requires at least PHP version 5.4.0. The Current PHP version is ' . PHP_VERSION;
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                add_action( 'admin_notices', 'Actifend::activation_warning' );
            }
            // Removed in v1.3.7 inform backend about this so mobile app can in turn inform the user.
            // this check will now be done when backup is initiated
            if ( !extension_loaded( 'zip' ) ) {
            //    wp_die('This plugin requires zip extention. Please enable it and try again.');
                debug_log( 'Zip extension is NOT enabled!' );
            }

            $htaccess_path = trailingslashit( ABSPATH ) . '.htaccess';
            if (file_exists($htaccess_path) && !is_writable($htaccess_path)) {
                echo 'This plugin require writable permission for .htaccess file. Please allow permission and try again.';
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                add_action( 'admin_notices', 'Actifend::activation_warning' );
            }
            // Out bound 80 and 443 check
            $port1 = 443;
            $port2 = 80;
            $url1  = 'https://www.wordpress.com';
            $url2  = 'http://www.example.org';

            //Check for the Directory Writable permission
            $plugin_dir = plugin_dir_path( __FILE__ );
            if ( !is_writable( $plugin_dir ) ) {
                echo __('This plugin require directory writable permission. Please allow permission and try again');
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                // add_action('admin_notices', 'Actifend::activation_warning');
                Actifend::activation_warning();
            }
            //Out Bound connection and port check
            $hostname = "example.com";
            $ip       = gethostbyname($hostname);
            $long     = ip2long($ip);
            if ( $long == -1 || $long === false ) {
                echo __('This plugin requires outbound connection. Please enable it and try again');
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                // add_action('admin_notices', 'Actifend::activation_warning');
                Actifend::activation_warning();
            }

            // check if the URL has IP address in it
            $siteURL = get_site_url();
            if ( stristr($siteURL, 'localhost') !== false
                 || Actifend::filter_ip_in_url( $siteURL ) ) {
                echo __('Cannot have localhost / IP addresses in the site url.');
                update_option( 'actifend_plugin_act_error', ob_get_contents() );
                // add_action('admin_notices', 'Actifend::activation_warning');
                Actifend::activation_warning();
            }

            debug_log('actifend_prerequisiteTest executed!');
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x27: ' . $e->getMessage() );
        }
    }

    /**
     * pluginDeactivationLog
     * Will be executed when Actiend is deactivated by the admin for some reason
     */
    public static function pluginDeactivationLog() {
        try{
            $utilObj = new Utility;
            $result = $utilObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $deactivationArray = array( 'deactivated' => 'True' );

                $final_end_point = ACTIFEND_WP_UPDATES_END_POINT . $asset_id . '/wpupdate';
                if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                    $final_end_point = $default_end_point;
                }

                $json_data = json_encode( $deactivationArray );
                $res       = $utilObj->actifend_postViaCurl( $final_end_point, $json_data );
                $res_json  = json_decode( $res );

                if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                    if ( ACTIFEND_DEBUG_MODE_ON ) {
                        $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode( $res_json );
                    } else {
                        $res = "EXCEPTION: While opening " . $final_end_point;
                    }
                    debug_log( $res );
                } else {
                    $res = "ASSET ID: " . $asset_id;
                }
            }
            debug_log( 'pluginDeactivationLog function executed.' );
        } catch( Exception $e ) {
            throw new Exception( 'Exception 1x09: ' . $e->getMessage() );
        }
    }

    // Filters IP address from url
    public static function filter_ip_in_url( $url ) {
        $x = strpos( $url, '://' );
        if ( ! $x ) {
            $x = strpos( $url, '/' );
            if ( $x )
                $url = substr( $url, 0, $x );
        } else {
            $pos = strpos( $url, '://' );
            $url = substr( $url, $pos+3 );
            $x = strpos( $url, '/' );
            if ( $x )
                $url = substr( $url, 0, $x );
        }

        $res = ( filter_var( $url, FILTER_VALIDATE_IP ) ? true : false );
        return( $res );
    }
}
?>
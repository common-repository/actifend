<?php
/**
 * @package actifend
 * @version 1.6.2
 */
/*
  Plugin Name: ActiFend Security Monitoring and Recovery
  Plugin URI: http://actifend.com
  Description: ActiFend Plugin is designed to enhance your web security. This Plugin sends your access logs to a SIEM + Incident Response Platform where they're analyzed to detect attacks on your web site. Security Alerts are sent to the ActiFend Mobile App. ActiFend helps you to Actively Defend your web site.
  Author: DSDInfosec
  Version: 1.6.2
  Author URI: http://dsdinfosec.com
*/

/** ABSPATH - usually the root directory for wordpress files
 * app-root on shared hosting installation
 * apache2 doc folder /var/www/html for dedicated hosting installation
 */
if ( !defined( 'ABSPATH' ) )
    define( 'ABSPATH', dirname(__FILE__) . '/' );

if ( (int) @ini_get( 'memory_limit' ) < 512 ) {
    if ( strpos( ini_get( 'disable_functions' ), 'ini_set' ) === false ) {
        // Some shared hosting sites have php memory limit set to 32 megs.
        // We need 512M so we can push and pull chunks to and from Actifend-cloud
        @ini_set( 'memory_limit', '512M' );
        @ini_set( 'max_execution_time', 600 );
        update_option( 'dsd_iniset_disabled', false );
    } else {
        update_option( 'dsd_iniset_disabled', true );
    }
}

$actifend_dependencies = array(
    'wp_die',
    'add_action',
    'add_filter',
    'remove_filter',
    'wp_remote_get',
    'wp_remote_request'
);

// Terminate execution if any of the functions mentioned above is not defined.
foreach ( $actifend_dependencies as $dependency ) {
    if ( !function_exists( $dependency ) ) {
        exit(0);
    }
}

// Remove the WordPress version number generator meta-tag
function dsdaf_remove_wp_version() { return ''; }
add_filter( 'the_generator', 'dsdaf_remove_wp_version' );

// Include all necessary plugin files
require( 'actifendConstants.php' );
require( 'Utility.php' );
require( 'actifendClass.php' );
require( 'ActifendUserRegister.php' );
require( 'ActifendFileIntegrity.php' );
require( 'ActifendAccessAlerts.php' );
require( 'ActifendIPBlock.php' );
require( 'ActifendBackup.php' );
require( 'ActifendRestoreBackup.php' );
require( 'ActifendScan.php' );

// Initiate temp dir
Actifend::initTmpDir();

// display any activation errors on the screen
if ( get_option( 'actifendActivated' ) != 1 ) {
    add_action( 'activated_plugin','actifend_save_activation_error' );
    function actifend_save_activation_error() {
        update_option( 'actifend_plugin_act_error', ob_get_contents() );
    }
}

/**
 * This function writes to debug log if WP_DEBUG and WP_DEBUG_LOG are set to true
 * These settings are done in wp-config.php file
 */
function debug_log( $message ) {
    if ( WP_DEBUG === true ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            error_log ( print_r( $message, true ) );
        } else {
            error_log ( $message );
        }
    }
}

//Remove Table on pluging uninstallation
register_deactivation_hook( __FILE__, 'actifend_remove' );

function actifend_remove() {
    // Let Actifend know that plugin has been deactivated
    Actifend::pluginDeactivationLog();
    Actifend::actifend_dropTable();
    Actifend::clear_actifend_crons();
    // remove cron schedules
    remove_filter( 'cron_schedules', 'Actifend::actifend_crons' );
    // remove actifend options
    $actifend_option_names = array(
        'dsd_iniset_disabled',
        'actifendActivated',
        'actifend_plugin_act_error',
        'FilePrivilegesInsufficient',
        'ActifendBackupStatus',
        'ActifendRestoreStatus',
        'mapp_activated',
        'actifend_fi_disc',
        'actifend_usage_category',
        'actifend_subs_validity',
        'actifend_subs_plan',
        'actifend_banned_ips',
        'actifend_wp_nginx_server',
        'actifend_disable_xmlrpc',
        'actifend_disable_xmlrpc_pingback',
        'actifend_plan_changed');

    foreach ( $actifend_option_names as $option_name ) {
        delete_option( $option_name );
        delete_site_option( $option_name );
    }

    // remove actions
    Actifend::remove_actifend_actions();
    debug_log( "Actifend Plugin Deactivated!" );
}

//Create Table on Installing the plugin
register_activation_hook( __FILE__, 'actifend_init' );

function actifend_init() {
    try {
        debug_log( 'Activating Actifend!' );
        update_option( 'actifendActivated', 0 );
        update_option( 'mapp_activated', 0 );
        update_option( 'actifend_disable_xmlrpc', false );
        update_option( 'actifend_disable_xmlrpc_pingback', false );
        // start output buffers
        ob_end_clean();
        ob_start();
        Actifend::actifend_prerequisiteTest();
        debug_log( 'Pre-requisite tests completed.' );
        Actifend::actifend_dropTable();
        // Create required tables in wordpress db
        debug_log( 'Starting to create Actifend tables ...' );
        Actifend::actifend_createTables();
        // // set cookie with one hour expiry
        setcookie( 'ActifendRedirect', 'OK', time()+3600 );
        $userRegObj = new ActifendUserRegister;
        $userRegObj->autoRegisterTrial();
        // take a snapshot of the files for integrity checking
        Utility::initFileSystem();
        global $wp_filesystem;
        $integrity = new ActifendFileIntegrity( $wp_filesystem->abspath() );
        $integrity->createBaseline();
        // get the banned ips from db, if any
        update_option( 'actifend_banned_ips', ActifendScan::get_ips_from_db_table() );
        // enable cron jobs
        Actifend::enable_actifend_crons();
        if ( get_option( 'dsd_iniset_disabled' ) === true ) {
            debug_log("ini_set function is disabled. Cannot set memory limit and execution time!");
        }
        debug_log("ActiFend plugin activated, but registration process not yet complete.!");
    } catch (Exception $e) {
        echo $e->getMessage();
        update_option( 'actifend_plugin_act_error', ob_get_contents() );
        add_action( 'admin_notices', 'Actifend::activation_warning' );
        deactivate_plugins( plugin_basename( __FILE__ ) );
    }
 }

//Registration process  (Screen after installation)
add_action( 'admin_menu', 'actifend_get_email' );

function actifend_get_email() {
    if ( !Actifend::isAdmin( wp_get_current_user() ) ) {
        debug_log( 'Current user does not have Admin privileges.' );
        return;
    }

    $currentUrl = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    $pluginActivated = get_option( 'actifendActivated', 0 );
    if ( $pluginActivated === 0 ) {
        # Get post vars sand store
        $email_passed = ( !empty( $_POST['actifend_email'] ) ? $_POST['actifend_email'] : null );
        $mapp = ( !empty( $_POST['app'] ) ? $_POST['app'] : 'false' );
        if ( ! empty( $email_passed ) ) {
            update_option( 'mapp_user', $email_passed );
        }
        $mapp = ( strtolower( $mapp ) == 'true' ? 1 : 0 );
        update_option( 'mapp_activated', $mapp );
    }

    $_currentUrl =  explode( '?', $currentUrl );
    $_currentUrl = ( count( $_currentUrl ) > 1 ? $_currentUrl[0] : $currentUrl );

    $PHP_SELF = $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];

    if ( $_currentUrl == $PHP_SELF && @$_COOKIE['ActifendRedirect'] == 'OK' ){
        // unset cookie
        setcookie( 'ActifendRedirect', 'OK', 1 );
        header( 'Location: ' . ACTIFEND_PLUGIN_URL );
        exit;
    }

    add_menu_page('ActiFend',
                  'ActiFend',
                  'manage_options',
                  'get_actifend_email',
                  'actifend_show_form',
                  plugins_url( 'actifend/images/ActiFend_16x21_icon.png' ),
                  1);
    debug_log("actifend_get_email executed!");
}

function actifend_show_form() {
    debug_log( 'Entered actifend_show_form function.' );
    if ( get_option( 'actifend_plugin_act_error', false ) ) {
        add_action( 'admin_notices', 'Actifend::activation_warning' );
    }

    $actifend_dir = plugin_dir_path( __FILE__ );
    if ( get_option('actifendActivated' ) == 0) {
        require_once( trailingslashit( $actifend_dir ) . 'form.php' );
    } else {
        $utiObj = new Utility;
        global $current_user;
        $current_user = wp_get_current_user();

        $result = $utiObj->getActifendInfo();
        if ( $result->actifend_email != 'None' ) {
            $asset_id       = $result->asset_id;
            $actifend_email = $current_user->user_email;
            $utiObj->get_asset_status( get_site_url(), $actifend_email );
            $mapp = get_option( 'mapp_activated', 0 );

            $template = ( $mapp == 0 ? 'store.php' : 'usage.php' );
            require_once( trailingslashit($actifend_dir) . $template );
        } else {
            debug_log( 'actifend_email not updated in the db.' );
        }
    }
}


if ( !function_exists( 'actifend_onboarding_notice' ) ) {
    add_action( 'admin_notices', 'actifend_onboarding_notice' );
    /**
     * actifend_email_optin_notice
     * serve admin notice if the user has not completed step 2
     * @return void
     */
    function actifend_onboarding_notice() {
        try {
            if ( Actifend::isAdmin( wp_get_current_user() ) ) {
                global $pagenow;

                $actifendActivated = get_option( 'actifendActivated' );
                $mappActivated = get_option( 'mapp_activated', 0 );

                if ( $pagenow == 'index.php' ) {
                    if ( get_option( 'actifend_plan_changed' ) === true ) {
                        $message = __('Site has been shifted to a FREE plan. '
                                      . ' To enable all features please renew '
                                      . 'subscription.');
                        echo '<div id="mapp_activate" style="vertical-align: middle" class="notice notice-info is-dismissible"><p><strong>ActiFend: </strong>' . $message . '</p></div>';
                    }
                }

                if ( $pagenow == 'plugins.php' ) {
                    $page_url = admin_url( 'admin.php?page=get_actifend_email' );
                    if ( $actifendActivated === 0 )
                    {
                        $message = __('To access the ActiFend security '
                                      . 'dashboard, please link your email '
                                      . 'address to your ActiFend account for '
                                      . 'authentication. ');
                        $message .= "\x20<a href='" . $page_url . "'><i>Okay, Take me there.</i></a>";
                        echo '<div id="optin_incomplete" style="vertical-align: middle" class="notice notice-warning is-dismissible"><p><strong>ActiFend: </strong>' . $message . '</p></div>';
                    }
                    elseif ( $actifendActivated == 1 && $mappActivated === 0)
                    {
                        $message = __('Install Mobile App and Enable ActiFend '
                                      . 'Mobile Security Center, for Actively '
                                      . 'defending your website. ');
                        $message .= "\x20<a href='" . $page_url . "'><i>OK. Take me there!</i></a>";
                        echo '<div id="mapp_activate" class="notice notice-info is-dismissible"><p><strong>ActiFend: </strong>' . $message . '</p></div>';
                    }
                    else
                    {
                        return;
                    }
                    debug_log( 'actifend_onboarding_notice executed!' );
                }
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return;
        }
    }
}


if (! function_exists( 'actifend_usage_notice' )) {
    add_action( 'admin_notices', 'actifend_usage_notice' );
    /**
     * actifend_usage_notice
     * serve admin notice based on the effective usage of MSC
     * @return void
     */
    function actifend_usage_notice() {
        try {
            if (Actifend::isAdmin( wp_get_current_user()) ) {
                global $pagenow;
                $ignoreNotice = get_user_meta( get_current_user_id(), 'dismiss_actifend_usage_notice' );
                if ( $pagenow == 'index.php' && ! $ignoreNotice) {
                    $page_url = admin_url( 'admin.php?page=get_actifend_email' );
                    $category = get_option( 'actifend_usage_category', 'HEALTHY' );
                    if (get_option( 'mapp_activated' ) == 1
                        && ( $category != 'HEALTHY' )) {

                        if ( $category == 'CRITICAL' ) {
                            $className = 'notice notice-error is-dismissible';
                            $message = __('Security Alerts are pending ... please use the ActiFend App and act on them.');
                        } else {
                            $className = 'notice notice-warning is-dismissible';
                            $message = __('Vulnerabilities found in website ... please use the ActiFend App and act on them.');
                        }

                        echo "<div id='usage_notice' style='vertical-align: middle;' class='"
                            . $className . "'><p><strong>ActiFend: </strong>";
                        printf( $message . ' | <a href="%1$s">Dismiss Notice</a>', '?actifend_dismiss_usage_notice=0' );
                        echo "</p></div>";
                    }
                }
            }

        } catch (Exception $e) {
            debug_log( $e->getMessage() );
            return;
        }
    }
}

function actifend_dismiss_usage_notice()  {
    if ( isset( $_GET['actifend_dismiss_usage_notice'] )
        && '0' == $_GET['actifend_dismiss_usage_notice'] ) {
        add_user_meta( get_current_user_id(),
            'dismiss_actifend_usage_notice', 'true', true );
        debug_log( 'Usage notice user meta data updated' );
    }
}

function reset_dismiss_usage_notice() {
    update_user_meta( get_current_user_id(),
        'dismiss_actifend_usage_notice', 'false');
    debug_log( 'Usage notice user meta data reset!' );
}

// block unauthenticated xmlrpc requests if desired
if ( get_option( 'actifend_disable_xmlrpc', false ) === true ) {
    add_filter( 'xmlrpc_enabled', '__return_false' );
    debug_log( 'XML-RPC disabled.' );
}

// block system.multicall xmlrpc
if ( get_option( 'actifend_disable_xmlrpc_pingback', false ) === true ) {
    add_filter( 'xmlrpc_methods', 'Utility::afend_remove_xmlrpc_pingback' );
    debug_log( 'XML-RPC pingback method removed.' );
}

// Add actifend crons to wordpress cron schedules
add_filter( 'cron_schedules', 'Actifend::actifend_crons' );
// Add actions required for actifend to function
Actifend::add_actifend_actions();

function blockBadQueries() {
    ActifendScan::find_and_block_bad_requests();
}

function wordpressBackup() {
    $backup = new ActifendBackup;
    $backup->actifend_backup_process();
}

function wordpressRestore() {
    $restore = new ActifendRestoreBackup;
    $restore->actifend_restore_process();
}

function fileIntegrityCheck() {
    Utility::initFileSystem();
    global $wp_filesystem;

    $integrity = new ActifendFileIntegrity( $wp_filesystem->abspath() );
    $integrity->getModifiedFiles_bySize_and_mtime();
}

function loginFailureAccess() {
    $loginObj = new ActifendAccessAlerts;
    $loginObj->actifend_push_logs( 'LOGIN-FAILED' );
}

function logInSuccess() {
    $loginObj = new ActifendAccessAlerts;
    $loginObj->actifend_push_logs( 'LOGIN-SUCCESS' );
}

function AccessLogs() {
    $loginObj = new ActifendAccessAlerts;
    $var = $loginObj->actifend_push_logs();
}

function pluginUpdatelog() {
    $loginObj = new ActifendAccessAlerts;
    $loginObj->actifend_updatePlugin_logs();
    debug_log( 'pluginUpdatelog function executed.' );
}

function updateInstallationLogs() {
    $updateObj = new ActifendAccessAlerts;
    $updateObj->actifendUpdateInstallTrigger();
    debug_log( 'updateInstallationLogs function executed.' );
}

function availableWordPressUpdates() {
    $themeloginObj = new ActifendAccessAlerts;
    $themeloginObj->actifend_wordpress_updates_available();
    debug_log( 'availableWordPressUpdates function executed.' );
}

function actifendUpdatetheme() {
    $upthemeObj = new ActifendAccessAlerts;
    $upthemeObj->actifend_update_theme();
    debug_log( 'actifendUpdatetheme function executed.' );
}

function getBlockedIPList() {
    $ipObj = new ActifendIPBlock;
    $ipObj->actifendGetBlockedIpsList();
    debug_log( 'getBlockedIPList function executed.' );
}

function getIPListToBlock() {
    $blockIp = new ActifendIPBlock;
    $blockIp->actifendIpListForBlock();
    debug_log( 'getIPListToBlock function executed.' );
}

function DeleteBlockedIPs() {
    $delObj = new ActifendIPBlock;
    $delObj->actifend_delete_ip_list();
    debug_log( 'DeleteBlockedIPs function executed.' );
}

function do_eval_scan() {
    $ascan = new ActifendScan;
    $ascan->malScan( ABSPATH );
    if (! empty( $ascan->infectedFiles ) && sizeof( $ascan->infectedFiles['eval'] ) > 0 )  {
        $ascan->sendInfectedFilesData( $ascan->infectedFiles );
    }
    debug_log("do_eval_scan function executed.");
}

// Execute all processes that need to run every minute
function processes_running_every_minute() {
    wordpressRestore();
    wordpressBackup();
    fileIntegrityCheck();
    getIPListToBlock();
    DeleteBlockedIPs();
}
?>

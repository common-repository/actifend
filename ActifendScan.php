<?php
/**
 * Actifend Malware scan - Looks for bad strings in files and requests
 *
 * @category MalwareScan
 * @package  actifend
 * @since    1.5.3
 */

require_once( plugin_dir_path(__FILE__) . 'actifendClass.php' );
require_once( plugin_dir_path(__FILE__) . 'Utility.php' );


class ActifendScan {
    public $infectedFiles = array();
    private $scannedFiles = array();
    var $utiObj;

    /**
     * constructor function
     *
     * @param null
     * @return object this will return  object of ActifendScan class.
     */
    function __construct() {
        $this->utiObj = new Utility;
        $this->utiObj->initFileSystem();
        update_option( 'actifend_maliciousCodeFound', false );
    }

    /**
     * get_patterns
     *
     * @return a associated array of patterns to look for in files
     */
     function get_patterns() {
        try {
            debug_log( 'Getting Scan patterns ...' );
            // check if any backup requests are waiting at BE
            $response  = $this->utiObj->getViaCurl( ACTIFEND_V3_ASSETS_END_POINT,
                                                    $asset_id, 'scan' );

            if ( isset( $response ) && !empty( $response ) && !is_wp_error( $response ) ) {
                if ( ( $response['ResponseCode'] == '2000' )
                    && ( $response['Message'] == 'success' ) ) {
                    // It create the backup of the files of the wordpress
                    $result = $response['Result'];
                    debug_log( $result );
                    return $result;
                }
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Scan get_patterns: ' . $e->getMessage() );
        }
     }

    /**
     * malScan
     *
     * Function used to scan a given folder for malware
     * @param folder in which files are to be scanned
     */
    function malScan( $folder=UPLOADS_DIR ) {
        global $wp_filesystem;

        debug_log("Actifend eval scan initiated ...");
        $abspath = $wp_filesystem->abspath();
        $this->scannedFiles[] = $folder;
        $files = list_files( $folder );
        $evalFoundFiles = array();

		foreach($files as $file) {
			if(is_file( $file )
                && !in_array( $file, $this->scannedFiles)) {
                // TODO - Do the actual scan
                $this->scannedFiles[] = $file;
                $contents = file_get_contents( $file );
                $evalReg = '/(?<![a-z0-9_])eval\\((base64|get_option|stripslashes|eval\\$_|\\$\\$|\\$[A-Za-z_0-9\\{]*(\\(|\\{|\\[))/i';
                if( @preg_match( $evalReg, $contents ) ) {
                    $evalFoundFiles[] = substr( $file, strlen( $abspath ) );
                    update_option( 'actifend_maliciousCodeFound', true );
                }
			}
		}
        $this->infectedFiles['eval'] = $evalFoundFiles;
        $scannedFileCount = count( $this->scannedFiles );
        debug_log( "$scannedFileCount files scanned for malware ..." );
        $infectedFileCount = count( $this->infectedFiles['eval'] );
        debug_log( "$infectedFileCount files found infected ..." );
    }

    /**
     * sendInfectedFilesData
     *
     * send the files list to Actifend BE for raising an alert
     */
    function sendInfectedFilesData( $files, $type='badStrings' ) {
        try {
            if ( !empty( $files ) ) {
                $actifend_array = array( $type => $files );
                $result = $this->utiObj->getActifendInfo();
                if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                    $assetid = $result->asset_id;
                } else {
                    debug_log( 'No ASSET ID assigned .... ' );
                    return;
                }
                $actifend_url = ACTIFEND_ASSETS_END_POINT . $assetid . '/scan';
                $utiObj->actifend_postViaCurl( $actifend_url, json_encode( $actifend_array ) );
                debug_log( 'Scan result sent to Actifend BE.' );
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Scan sendInfectedFilesData: ' . $e->getMessage() );
        }
    }

    // Block bad queries
    public static function find_and_block_bad_requests() {

        if ( !Actifend::isAdmin( wp_get_current_user() ) ) {
            $request_uri_array = apply_filters( 'request_uri_items', array(
                'eval\(', 'UNION(.*)SELECT', '\(null\)', 'base64_',
                '\/localhost', '\%2Flocalhost', '\/pingserver', '\/config\.',
                '\/wwwroot', '\/makefile', 'crossdomain\.',
                'proc\/self\/environ', 'etc\/passwd', '\/https\:', '\/http\:',
                '\/ftp\:', '\/cgi\/', '\.cgi', '\.exe', '\.sql', '\.ini',
                '\.dll', '\.asp', '\.jsp', '\/\.bash', '\/\.git', '\/\.svn',
                '\/\.tar', ' ', '\<', '\>', '\/\=', '\.\.\.', '\+\+\+', '\/&&',
                '\/Nt\.', '\;Nt\.', '\=Nt\.', '\,Nt\.', '\.exec\(',
                '\)\.html\(', '\{x\.html\(', '\(function\(', '\.php\([0-9]+\)',
                '(benchmark|sleep)(\s|%20)*\(' ) );
            $query_string_array = apply_filters( 'query_string_items', array(
                '\.\.\/', '127\.0\.0\.1', 'localhost', 'loopback', '\%0A',
                '\%0D', '\%00', '\%2e\%2e', 'input_file', 'execute',
                'mosconfig', 'path\=\.', 'mod\=\.', 'wp-config\.php') );
            $ua_bot_array = apply_filters( 'user_agent_items', array(
                'acapbot', 'binlar', 'casper', 'cmswor', 'diavol', 'dotbot',
                'finder', 'flicky', 'morfeus', 'nutch', 'planet', 'purebot',
                'pycurl', 'semalt', 'skygrid', 'snoopy', 'sucker', 'turnit',
                'vikspi', 'zmeu' ) );

            $request_uri = false;
            $query_string = false;
            $ua_bot = false;

            // This portion is implemented for WP-SPs who have a reverse proxy
            // in front of the app (like openshift)
            $ips_banned = get_option( 'actifend_banned_ips', array() );
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                //to check ip is pass from proxy
                $actual_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $actual_ip = $_SERVER['REMOTE_ADDR'];
            }

            if ( !empty( $ips_banned ) ) {
               if ( in_array( $actual_ip, $ips_banned ) ) {
                    debug_log( "Request from banned ip $actual_ip blocked." );
                    Utility::generate_403();
                } else {
                    debug_log( "Request from $actual_ip" );
                }
            } else {
                debug_log( "Request from $actual_ip" );
            }

            // Check if the request is XML-RPC
            if ( ActifendScan::is_xmlrpc() ) {
                debug_log( 'This is a XML-RPC request.' );
                // Check the payload to see if it is a multicall
                // if yes, stop it
                $raw_post_data = file_get_contents( 'php://input' );
                if ( strpos( $raw_post_data, 'system.multicall' ) ) {
                    Utility::generate_403();
                    debug_log( 'XML-RPC multicall request blocked.' );
                }
            }

            // Block requests with bad strings
            if ( isset( $_SERVER['REQUEST_URI'] ) && !empty( $_SERVER['REQUEST_URI'] ) )
                $request_uri = $_SERVER['REQUEST_URI'];

            if ( isset( $_SERVER['QUERY_STRING'] ) && !empty( $_SERVER['QUERY_STRING'] ) )
                $query_string = $_SERVER['QUERY_STRING'];

            if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && !empty( $_SERVER['HTTP_USER_AGENT'] ) )
                $ua_bot = $_SERVER['HTTP_USER_AGENT'];

            if ( $request_uri || $query_string || $ua_bot ) {
                if ( strlen($_SERVER['REQUEST_URI'] ) > 255 ||
                        preg_match( '/' . implode('|', $request_uri_array) . '/i', $request_uri ) ||
                        preg_match( '/' . implode('|', $query_string_array) . '/i', $query_string ) ||
                        preg_match( '/' . implode('|', $ua_bot_array) . '/i', $ua_bot )
                ) {
                    debug_log( "Request URI: $request_uri" );
                    debug_log( "Query String: $query_string" );
                    debug_log( "User Agent: $ua_bot" );
                    Utility::generate_403();
                }
            }
        }
    }

    public static function get_ips_from_db_table() {
        try {
            global $wpdb;
            $actifend_ip_table = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
            $query = "SELECT ips from $actifend_ip_table;";
            $results = $wpdb->get_results( $query );

            if ( empty( $results ) ) {
                return array();
            } else {
                $banned_ips = array();
                foreach ( $results as $row ) {
                    array_push( $banned_ips, $row->ips );
                }
                return $banned_ips;
            }

        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Scan get_ips_from_db_table: ' . $e->getMessage() );
        }
    }

    /**
     * is_xmlrpc
     * Detect if the request is an XML RPC request
     *
     */
    public static function is_xmlrpc() {
        return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;
    }

}

?>
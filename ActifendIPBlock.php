<?php
/**
 * ActifendIPBlock
 *
 * This class will handle the blocked Ip and block ip for one hour which
 * user block through the app. Every ip released after one hour automatically.
 */
class ActifendIPBlock
{
    var $utiObj;
    var $htpath;
    var $deny_string;

    public function __construct() {
        $this->utiObj = new Utility;
        // Default server is apache; hence htaccess
        $this->htpath = trailingslashit( ABSPATH ) . '.htaccess';
        $this->deny_string = 'deny from';

        /**
         * Block ip on nginx server
         * Note: This is not effective as nginx needs to be reloaded
         * @since 1.5.3
         */
        global $is_nginx;
        if ( $is_nginx ) {
            debug_log( 'NGINX webserver identified ...' );
            update_option( 'actifend_wp_nginx_server', true );
            // TODO nginx.conf location to be finalized
            $this->htpath = trailingslashit( ABSPATH ) . 'nginx.conf';
            $this->deny_string = 'deny';
        }
    }

    /**
     * actifendGetBlockedIpsList
     *
     * This function get the blocked ip list which blocked in htaccess by the user send the deatil to the SOC.
     */
    public function actifendGetBlockedIpsList() {
        try {
            global $wp_version;

            $ips_array = array();
            // get the contetnt of htaccess file and get ip list from the htaccess file.
            // 21/7/2017 - NOTE: Why not get this list from db?
            if ( file_exists( $this->htpath ) ) {
                $string_ip = file_get_contents( $this->htpath );
                $ips       = explode( '#ACTIFEND', $string_ip );
                if ( empty( $ips[1] ) ) {
                    return $ips_array;
                }
                $ips_array = array_map( 'trim', explode( $this->deny_string, $ips[1] ) ) ;
                unset( $ips_array[0] );
                $blocked_ip_list = array_values( $ips_array );

                $result          = $this->utiObj->getActifendInfo();
                if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                    $asset_id          = $result->asset_id;
                    $default_end_point = $result->default_end_point;
                    $blocked_ips_list  = json_encode( $blocked_ip_list );
                    $actifendArray     = array();
                    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                        //check ip from share internet
                        $from_ip_address = $_SERVER['HTTP_CLIENT_IP'];
                    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                        //to check ip is pass from proxy
                        $from_ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
                    } else {
                        $from_ip_address = $_SERVER['REMOTE_ADDR'];
                    }

                    $actifendArray['message']['source']     = 'wordpress';
                    $actifendArray['message']['assetid']    = $asset_id;
                    $actifendArray['message']['fromaddr']   = $from_ip_address;
                    $actifendArray['message']['clientaddr'] = $_SERVER['SERVER_ADDR'];
                    $actifendArray['message']['method']     = $_SERVER['REQUEST_METHOD'];
                    $actifendArray['message']['uri']        = $_SERVER['REQUEST_URI'];
                    $actifendArray['message']['protocol']   = $_SERVER['SERVER_PROTOCOL'];
                    if ( !isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
                        $actifendArray['message']['ua'] = 'Wordpress ' . $wp_version;
                    } else {
                        $actifendArray['message']['ua'] = $_SERVER['HTTP_USER_AGENT'];
                    }
                    $actifendArray['message']['referrer']   = @$_SERVER['HTTP_REFERER'];
                    $actifend_epoch = $_SERVER['REQUEST_TIME'];
                    $actifend_dt    = new DateTime("@$actifend_epoch");
                    $actifend_rtime = $actifend_dt->format('Y-m-d H:i:s');
                    $actifendArray['message']['req_time'] = $actifend_rtime;

                    if ( version_compare( PHP_VERSION, '5.4.0' ) >= 0 ) {
                        $actifendArray['message']['res_code'] = http_response_code();
                    } else {
                        $full_url     = $this->utiObj->full_url( $_SERVER );
                        $header       = get_headers( $full_url, 1 );
                        $res_code     = $header[0];
                        $get_respose  = explode( " ", $res_code );
                        $actifendArray['message']['res_code'] = $get_respose[1];
                    }
                    $actifendArray['message']['tags']            = 'BLOCKED_IP_LIST';
                    $actifendArray['message']['BLOCKED_IP_LIST'] = $blocked_ips_list;
                    $size_of_response = 0;
                    if ( count( $actifendArray ) > 0 ) {
                        $size_of_response = strlen( implode( " ", $actifendArray['message'] ) );
                    }
                    $actifendArray['message']['res_size'] = $size_of_response;
                    $final_end_point = ACTIFEND_EVENTS_END_POINT;
                    if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                        $final_end_point = $default_end_point;
                    }
                     // post the data to the server by curl.
                    $string = json_encode( $actifendArray );
                    $res = $this->utiObj->actifend_postViaCurl( $final_end_point, $string );
                    $res_json = json_decode( $res) ;

                    if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                        if ( ACTIFEND_DEBUG_MODE_ON ) {
                            $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                        } else {
                            $res = "EXCEPTION: While opening " . $final_end_point;
                        }
                    } else {
                        $res = "ASSET ID: " . $asset_id;
                    }
                }
            }
        } catch ( Exception $e ) {
            throw new Exception( "Exception 1x12: " . $e->getMessage() );
        }
    }

    /**
     * ReleaseBlockedIPList
     *
     * This funtion will release ip address which are blocked using the htaccess file after 1 hour.
     * @param string $ip ip address which release after one hour.
     * @return boolean
     */
    private function ReleaseBlockedIPList( $ip ) {
        try {
            $htpath = $this->htpath;
            if ( file_exists( $htpath ) ) {
                if ( !empty( $ip ) ) {
                    $string_ip     = file_get_contents( $htpath );
                    $htaccess_file = explode( '#ACTIFEND', $string_ip );
                    $upper_part    = $htaccess_file[0];
                    file_put_contents( $htpath, $upper_part );

                    $delete_ip = $htaccess_file[1];
                    $ips2 = array_map( 'trim', explode( $this->deny_string, $delete_ip ) );
                    $key = array_search( $ip, $ips2, true );
                    if ( !empty( $key ) ) {
                        unset( $ips2[$key] );
                    }

                    if ( !empty( $ips2 ) ) {
                        file_put_contents( $htpath, "#ACTIFEND\r\n", FILE_APPEND | LOCK_EX );
                        // file_put_contents($htpath, "order allow,deny\r\n", FILE_APPEND | LOCK_EX);
                        foreach ( $ips2 as $key => $value ) {
                            if ( !empty( $value ) ) {
                                $deny_ip = $this->deny_string . " $value\r\n";
                                file_put_contents( $htpath, $deny_ip, FILE_APPEND | LOCK_EX );
                            }
                        }
                        file_put_contents( $htpath, "#ACTIFEND\r\n", FILE_APPEND | LOCK_EX );
                    }
                    $lower_part = $htaccess_file[2];
                    if ( !empty( $lower_part ) ) {
                        file_put_contents( $htpath, $lower_part, FILE_APPEND | LOCK_EX );
                    }
                    debug_log( "Ban of $ip released." );
                    return true;
                }
            } else {
                debug_log( 'htaccess file does not exist!' );
                return false;
            }

        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x13: ' . $e->getMessage() );
        }
    }

    /**
     * addIPToDenyList
     *
     * This function will add the ip address to htaccess deny list.
     * @param string $ip_for_block This contain the ip address which add to the htaccess file by the function.
     * @return boolean
     */
    private function addIPToDenyList( $ip_for_block ) {
        try {
            $htpath = $this->htpath;
            // htaccess file creation added in v1.4
            if ( !file_exists( $htpath ) ) @touch( $htpath );
            if ( file_exists( $htpath ) ) {
                if ( !empty( $ip_for_block ) ) {
                    $ipaddr = $ip_for_block['ip'];
                    $banfor = $ip_for_block['ban_for'];
                    debug_log( "About to block $ipaddr for $banfor seconds." );

                    $string_ip     = file_get_contents( $htpath );
                    $htaccess_file = explode( '#ACTIFEND', $string_ip );
                    if ( empty( $htaccess_file[1] ) ) {
                        debug_log( "htaccess file does not contain any Actifend records!" );
                        file_put_contents( $htpath, "\r\n#ACTIFEND\r\n", FILE_APPEND | LOCK_EX );
                        $ip_block         = $this->deny_string . " $ipaddr\r\n";
                        file_put_contents( $htpath, $ip_block, FILE_APPEND | LOCK_EX );
                        file_put_contents( $htpath, "#ACTIFEND\r\n", FILE_APPEND | LOCK_EX );
                        debug_log( 'htaccess file update completed.' );
                        return true;
                    } else {
                        debug_log( 'htaccess file contains Actifend records!' );
                        $upper_part = $htaccess_file[0];
                        file_put_contents( $htpath, $upper_part );
                        file_put_contents( $htpath, "#ACTIFEND\r\n", FILE_APPEND | LOCK_EX );
                        $ip_list = $htaccess_file[1];
                        $ips     = array_map( 'trim', explode( $this->deny_string, $ip_list ) );
                        $key     = array_search( $ipaddr, $ips, true );
                        if ( empty( $key ) ) {
                            array_push( $ips, $ipaddr );
                        }
                        foreach ( $ips as $key => $value ) {
                            if ( !empty( $value ) ) {
                                $deny_ip = $this->deny_string . " $value\r\n";
                                file_put_contents( $htpath, $deny_ip, FILE_APPEND | LOCK_EX );
                            }
                        }

                        file_put_contents( $htpath, '#ACTIFEND', FILE_APPEND | LOCK_EX );
                        $lower_part = $htaccess_file[2];
                        if ( !empty( $lower_part ) ) {
                            file_put_contents( $htpath, $lower_part, FILE_APPEND | LOCK_EX );
                        }
                        debug_log( 'htaccess file update completed.' );
                        return true;
                    }
                }
                return true;
            } else {
                debug_log( 'htaccess file does not exist!' );
                return false;
            }

        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x15: ' . $e->getMessage());
        }
    }

    /**
     * actifendIpListForBlock
     *
     * This function simply get the list send by the user from the server
     * and then block the that ip address.
     * @param null
     * @return null
     */
    public function actifendIpListForBlock() {
        $result = $this->utiObj->getActifendInfo();
        if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
            $asset_id = $result->asset_id;
        } else {
            debug_log( 'No ASSET ID assigned .... ' );
            return 'NO_ASSETID';
        }
        // $actifend_asset_id= $asset_id;
        try {
            $curl_uri = ACTIFEND_IP_GET_END_POINT;
            $end_point = 'banips';
            $response = $this->utiObj->getViaCurl( $curl_uri, $asset_id, $end_point );
            if ( isset( $response ) && !empty( $response ) ) {
                if ($response['Message'] == 'success' && $response['ResponseCode'] == '2000') {
                    // debug_log( $response['Result'] );
                    $list = $response['Result']['ban'];
                    $set_num = $response['Result']['set'];
                    $this->insertIPToDBTable($list, $set_num);

                    $release_ip_list = array();
                    $release_ips = array();
                    foreach ( $list as $ip_for_block ) {
                        debug_log( 'IP to Block: (see below)' );
                        debug_log( $ip_for_block );
                        if ( ! $this->addIPToDenyList( $ip_for_block ) )
                            $release_ip_list['status'] = 'failed';
                        else
                            $release_ip_list['status'] = 'done';

                        array_push( $release_ips, $ip_for_block['ip'] );
                    }
                    $release_ip_list['ip'] = $release_ips;
                    $release_ip_list['set'] = $set_num;
                    // status done when all procress is done.
                    $actifend_json = json_encode( $release_ip_list );
                    $banips_endpoint = ACTIFEND_IP_GET_END_POINT . $asset_id . '/banips';
                    $this->utiObj->actifend_postViaCurl( $banips_endpoint, $actifend_json, 'PATCH' );
                } else {
                    debug_log( 'Nothing to Ban!' );
                    return;
                }
            }
        } catch (Exception $e) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x19: ' . $e->getMessage() );
        }
    }

    /**
     * insertIPToDBTable
     *
     * This function insert the list of ip addresses to the database so be track the timing and have the ip addresses list.
     * @param array $iparray This array have the ip address list which we send to the database table in json format.
     * @param setnum - set number
     * @return null
     */
    private function insertIPToDBTable( $iparray, $setnum ) {
        try {
            if (!empty($iparray)) {
                // $array_json = json_encode($iparray);
                $time = date( 'Y-m-d H:i:s' );
                global $wpdb;
                $actifend_ip_table = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                // debug_log( $iparray );
                foreach ( $iparray as $value ) {
                    $wpdb->insert( $actifend_ip_table,
                                array('ips'        => $value['ip'],
                                      'entry_time' => $time,
                                      'ban_for'    => $value['ban_for'],
                                      'set_number' => $setnum)
                                );
                }
                // Update global var
                $afend_banned_ips = get_option( 'actifend_banned_ips', array() );
                $afend_banned_ips = array_merge( $afend_banned_ips, $iparray );
                update_option( 'actifend_banned_ips', $afend_banned_ips );
                // debug_log( $afend_banned_ips );
                debug_log( 'IP block table updated.' );
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x20: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_delete_ip_list
     *
     * This is the main function which is used to release the ip addresses.
     * @param null
     * @return null
     */
    public function actifend_delete_ip_list() {
        try {
            $asset_info = $this->utiObj->getActifendInfo();
            if ( isset( $asset_info->asset_id ) && !empty( $asset_info->asset_id ) ) {
                global $wpdb;
                $actifend_ip_table = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                $data = $wpdb->get_results( "SELECT ips,entry_time,ban_for,set_number FROM  `" . $actifend_ip_table . "`;" );
                if ( !empty( $data ) ) {
                    foreach ( $data as $result ) {
                        $entry_time     = $result->entry_time;
                        $setnum         = $result->set_number;
                        $banfor         = $result->ban_for;
                        $expiry_time    = date( 'Y-m-d H:i:s' );
                        $seconds        = strtotime( $expiry_time ) - strtotime( $entry_time );

                        if ( $seconds >= $banfor ) {
                            $ip_list = $result->ips;
                            if ( !is_array( $ip_list ) ) {
                                $ip_list = array( $ip_list );
                            }

                            foreach ( $ip_list as $value ) {
                                debug_log( "Ban on $value is about to be released." );
                                $this->ReleaseBlockedIPList( $value );
                            }

                            // update the db table
                            global $wpdb;
                            $ip_list_delete_table = $wpdb->prefix . ACTIFEND_TABLE_IP_BLOCKED;
                            $wpdb->delete( $ip_list_delete_table, array( 'entry_time' => $entry_time ) );
                            debug_log('IP Ban release done.');

                            $release_ip_list = array( 'status' => 'released',
                                                      'ip'     => $ip_list,
                                                      'set'    => $setnum );

                            $actifend_json   = json_encode( $release_ip_list );
                            $banips_endpoint = ACTIFEND_IP_GET_END_POINT . $asset_info->asset_id . '/banips';

                            // update the option as well
                            $afend_banned_ips = get_option( 'actifend_banned_ips' );
                            $abi_updated = array_diff( $afend_banned_ips, $ip_list );
                            update_option( 'actifend_banned_ips', $abi_updated );
                            // Communicate to BE
                            $this->utiObj->actifend_postViaCurl( $banips_endpoint, $actifend_json, 'PATCH' );
                        }
                    }
                   return true;
                } else {
                    $this->delete_zombie_ip_in_htaccess();
                }
                debug_log( 'Nothing to release!' );
                return false;
            }
            debug_log( 'actifend_delete_ip_list function executed.' );
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x21: ' . $e->getMessage() );
        }
    }

    private function delete_zombie_ip_in_htaccess() {
        try {
            $htpath = $this->htpath;
            $asset_info = $this->utiObj->getActifendInfo();
            if ( file_exists( $htpath ) ) {
                $string_ip     = file_get_contents( $htpath );
                $htaccess_file = explode( '#ACTIFEND', $string_ip );
                $upper_part    = $htaccess_file[0];
                file_put_contents( $htpath, $upper_part );
                if ( count( $htaccess_file ) == 1 ) {
                    debug_log( 'No zombie ips to release in htaccess.');
                    return true;
                }
                $delete_ip = $htaccess_file[1];
                if ( empty( $delete_ip ) ) {
                    $ips2 = array_map( 'trim', explode( $this->deny_string, $delete_ip ) );
                    // Release ips2 and intimate Actifend BE
                    $release_ip_list = array( 'status' => 'released',
                                              'ip'     => $ips2);

                    $actifend_json   = json_encode( $release_ip_list );
                    $banips_endpoint = ACTIFEND_IP_GET_END_POINT . $asset_info->asset_id . '/banips';
                    // Communicate to BE
                    $this->utiObj->actifend_postViaCurl( $banips_endpoint, $actifend_json, 'PATCH' );

                    debug_log( "Ban of zombie ip-addresses released." );
                }
                return true;
            } else {
                debug_log( 'htaccess file does not exist!' );
                return false;
            }

        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception 1x13: ' . $e->getMessage() );
        }
    }

}
?>
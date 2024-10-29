<?php
/**
 * ActifendUserRegister
 * This class is used register plugin automatically
 */
class ActifendUserRegister {

    /**
     * autoRegisterTrial
     * register the plugin automatically with just the public url
     * get the asset id from the endpoint
     */
    public function autoRegisterTrial( $mapp_user=NULL ) {
        try {
            global $current_user;
            $utilityObj = new Utility;

            $error = 0;
            $current_user = wp_get_current_user();
            // $actifend_timestamp = time();
            $actifend_timestamp = date( 'Y-m-d' );
            // $actifend_asset_name = $_SERVER['SERVER_NAME'];
            $actifend_asset_name = get_bloginfo( 'name' );
            $actifend_req = $actifend_asset_name
                            . $actifend_timestamp
                            . ACTIFEND_ASSET_TYPE
                            . ACTIFEND_SALT2;
            $actifend_reqhash = hash_hmac('sha512',
                                          utf8_encode( $actifend_req ),
                                          utf8_encode( ACTIFEND_SALT1 ));

            $payloadArray = array();
            $payloadArray["ACTIFEND_ASSET_NAME"] = $actifend_asset_name;
            $payloadArray["ACTIFEND_ASSET_TYPE"] = ACTIFEND_ASSET_TYPE;
            # Verify Site URL - ensure that it does not contain an IP Address
            # Actifend does not support IP addresses in the url, at this time
            $payloadArray["ACTIFEND_FQDN"] = get_site_url();
            // ZIP extension check for backup and restore features
            $payloadArray['ZIP_ENABLED'] = ( !extension_loaded( 'zip' ) ? 'false' : 'true' );
            // check file privileges for restore feature
            $privCheck = $utilityObj->check_file_privileges();
            $payloadArray['FILE_PRIVILEGES_INSUFFICIENT'] = ( $privCheck === 1 ? 'true' : 'false' );
            $payloadArray['INISET_DISABLED'] = ( get_option( 'dsd_iniset_disabled' ) ? 'true' : 'false' );

            debug_log( "Low File Privileges: " . $payloadArray['FILE_PRIVILEGES_INSUFFICIENT'] );
            debug_log( "ini_set disabled: " . $payloadArray['INISET_DISABLED'] );

            $actifend_result = $this->actifend_register(ACTIFEND_REGISTER_END_POINT,
                                                        ACTIFEND_SALT2,
                                                        $actifend_reqhash,
                                                        $payloadArray);
            $RESULT = json_decode( $actifend_result );
            $OUT = @json_decode( $RESULT->output );
            if ( empty( $OUT ) ) {
                $ERROR_MSG = "ERROR: Received invalid response from server. Please try again after sometime";
            } else {
                // If Result is an object then it should be properly dealt with
                if ( is_object( $OUT->Result ) ) {
                    $asset_id = ($OUT->Result->asset_id);
                    debug_log( "Asset ID: $asset_id" );
                    $isoList = $OUT->Result->iso;
                    # Check if the current user is in the iso list
                    $admin_email = sha1( $current_user->user_email );
                    $is_registered = ( in_array( $admin_email, $isoList ) ? true : false );

                    $mappActivated = $OUT->Result->mapp_activated;
                    @$new_asset = $OUT->Result->new_asset;

                    if ($mappActivated === 1
                        || strcmp($mappActivated, '1') === 0
                        || $new_asset == 1
                        || strcmp( $new_asset, '1') === 0)
                        update_option( 'mapp_activated', 1 );
                    else
                        update_option( 'mapp_activated', 0 );

                    $mappActivated = get_option('mapp_activated');
                } else {
                    $asset_id = $OUT->Result;
                    $is_registered = false;
                }

                $response_code = $OUT->ResponseCode;
                if ( $utilityObj->actifend_isValidAssetId( $asset_id, $response_code ) ) {
                    $last_checked = date( 'Y-m-d H:i:s' );
                    global $wpdb;
                    if ( $is_registered || get_option( 'mapp_activated' ) === 1 ) {
                        $actifend_optin = 1;
                        $admin_email = ( is_null( $mapp_user ) ? $current_user->user_email : $mapp_user);
                        debug_log( "Registration email : $admin_email" );
                        update_option( 'actifendActivated', 1 );
                    } else {
                        $actifend_optin = 0;
                        $admin_email = 'None';
                        update_option( 'actifendActivated', 0 );
                    }

                    $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;
                    $wpdb->insert($actifend_table_name,
                                    array('asset_id'        => $asset_id,
                                          'actifend_email'  => $admin_email,
                                          'actifend_optin'  => $actifend_optin,
                                          'last_checked'    => $last_checked));

                    debug_log( 'Actifend Trial Registration Completed!' );
                    $utilityObj->actifend_pluginInstallation_log();

                    # Check if it is a new asset, if coming from actifend app
                    # execute step2 as well
                    @$new_asset = $OUT->Result->new_asset;
                    if ( $is_registered ) {
                        debug_log('User is registered with Actifend. Executing step2 of registration.' );
                        // update_option('mapp_activated', 1);
                        $ret = $this->actifend_register_step2( $admin_email, false );
                        if ( strcmp($ret, 'NO_ASSETID' ) === 0 ) {
                            // get assetid and update
                            $res = $this->utilityObj->update_assetid( get_site_url(), $current_user->user_email );
                            if ( $res ) {
                                debug_log( 'DB updated with asset id.' );
                            }
                        }
                    }

                }
            }
            // debug_log("Actifend Plugin Activated but onboarding is not complete!");
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( $e->getMessage() );
        }
    }

    /**
     * actifend_register_step2
     * Step 2: Update email with Actifend
     * @param email
     * @return 200 response
     */
    public function actifend_register_step2( $actifend_email, $mobile=false ) {
        try {
            $utilityObj = new Utility;

            if ( $utilityObj->actifend_validEmail( $actifend_email ) ) {
                $error = 0;

                $actifend_timestamp = date( 'Y-m-d' );
                $actifend_asset_name = get_bloginfo( 'name' );
                $actifend_req = $actifend_asset_name
                                . $actifend_timestamp
                                . ACTIFEND_ASSET_TYPE
                                . ACTIFEND_SALT2;
                $actifend_reqhash = hash_hmac('sha512',
                                              utf8_encode( $actifend_req ),
                                              utf8_encode( ACTIFEND_SALT1 ));

                $custom_headers = array('actifend_client_id' => ACTIFEND_SALT2,
                                        'actifend_signature' => $actifend_reqhash);

                $result = $utilityObj->getActifendInfo();
                if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                    $asset_id = $result->asset_id;
                } else {
                    debug_log( 'No ASSET ID assigned .... ' );
                    return 'NO_ASSETID';
                }
                $payloadArray = array('ACTIFEND_EMAIL'      => $actifend_email,
                                      'ACTIFEND_ASSET_ID'   => $asset_id,
                                      'ACTIFEND_ASSET_NAME' => get_bloginfo( 'name' ));
                // $payloadArray['ACTIFEND_APP'] = ( $mobile ) ? 1 : 0;

                $actifend_result = $utilityObj->actifend_postViaCurl(ACTIFEND_REGISTER_END_POINT,
                                                                     json_encode($payloadArray),
                                                                     "PATCH",
                                                                     $custom_headers);

                $RESULT = json_decode($actifend_result);
                $OUT = @json_decode($RESULT->output);
                if (empty($OUT)) {
                    $ERROR_MSG = 'ERROR: Received invalid response from server. Please try again after sometime';
                } else {
                    if ( $OUT->ResponseCode == '2000' ) {
                        $last_checked = date( 'Y-m-d H:i:s' );
                        global $wpdb;
                        $actifend_optin = 1;
                        $last_checked = date( 'Y-m-d H:i:s' );
                        $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_NAME;

                        $res = $wpdb->update($actifend_table_name,
                                             array('actifend_email' => $actifend_email,
                                                   'actifend_optin' => $actifend_optin,
                                                   'last_checked'   => $last_checked),
                                             array( 'asset_id' => $asset_id ));

                        if ( false === $res ) {
                            debug_log( 'DB record not updated with email.');
                        }
                        debug_log( 'Actifend Registration Completed!' );
                        $result = strtolower( $OUT->Result );
                        $result = ( strcmp($result, 'false') === 0 ? 1 : 0 );
                        update_option( 'mapp_activated', $result );

                        $utilityObj->actifend_pluginInstallation_log();
                        update_option( 'actifendActivated', 1 );
                    } else {
                        $ERROR_MSG = "ERROR: Received invalid asset_id ($OUT->Message). Please try again after sometime";
                        debug_log( $ERROR_MSG );
                    }
                }
            }
            debug_log( 'Actifend Plugin Step 2 completed' );
            // send the output buffers and turn off
            ob_end_flush();
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return false;
        }
    }

    /**
     * actifend_register
     *
     * Send the client detail to the endpoint.
     * @param string $actifend_url endpoint url
     * @param string $actifend_h1  header used in url
     * @param string $actifend_h2  header info
     * @param string $actifend_params client detail.
     * @return string $return
     */
    public function actifend_register ( $actifend_url, $actifend_h1, $actifend_h2, $actifend_params ) {
        try {
            $utilObj = new Utility;
            $custom_headers = array('actifend_client_id' => $actifend_h1,
                                    'actifend_signature' => $actifend_h2);
            $response = $utilObj->actifend_postViaCurl($actifend_url,
                                                       $actifend_params,
                                                       'POST',
                                                       $custom_headers);
            return $response;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return $e->getMessage();
        }
    }

}
?>

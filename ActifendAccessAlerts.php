<?php
/**
 * Actifend Access Alert
 *
 * This class is used to send Access logs like login access logs, login
 * failure logs, Plugin installation logs, Themes installtion logs and
 * wordpress update logs.
 *
 * @category Alerts
 * @package  actifend
 */

require_once( plugin_dir_path(__FILE__) . "report.php" );

class ActifendAccessAlerts {
    var $utilityObj;

    public function __construct() {
        $this->utilityObj = new Utility;
    }

    /**
     * actifend_push_logs
     *
     * This function is used to send the access logs and capture the
     * user activitites after that send the logs.
     */
    public function actifend_push_logs( $status=NULL ) {
        try {
            global $wp_version;

            $result = $this->utilityObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $default_end_point = $result->default_end_point;

                $final_end_point = ACTIFEND_EVENTS_END_POINT;
                if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                    $final_end_point = $default_end_point;
                }

                $actifendArray = array();
                # Get the actual ip of ther request
                if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                    //check ip from share internet
                    $from_ip_address = $_SERVER['HTTP_CLIENT_IP'];
                } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                    //to check ip is pass from proxy
                    $from_ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
                } else {
                    $from_ip_address = $_SERVER['REMOTE_ADDR'];
                }

                # set values for various parameters to be sent
                $message = array(
                    'source'        => 'wordpress',
                    'assetid'       => $asset_id,
                    'fromaddr'      => $from_ip_address,
                    'clientaddr'    => $_SERVER['SERVER_ADDR'],
                    'method'        => $_SERVER['REQUEST_METHOD'],
                    'uri'           => $_SERVER['REQUEST_URI'],
                    'protocol'      => $_SERVER['SERVER_PROTOCOL'],
                    'referrer'      => @$_SERVER['HTTP_REFERER'],
                    'req_time'      => current_time( 'mysql', true )
                );

                if ( version_compare( PHP_VERSION, '5.4.0' ) >= 0 ) {
                    $message['res_code'] = http_response_code();
                } else {
                    $full_url     = $this->utiObj->full_url( $_SERVER );
                    $header       = get_headers( $full_url, 1 );
                    $res_code     = $header[0];
                    $get_respose  = explode( " ", $res_code );
                    $message['res_code'] = $get_respose[1];
                }

                if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
                    $message['ua'] = $_SERVER['HTTP_USER_AGENT'];
                } else {
                    $message['ua'] = 'Wordpress ' . $wp_version;
                }

                if ( !is_null( $status ) ) {
                    $message['tags'] = array( $status );
                }

                # content length would be set for POST requests only
                if ( isset( $_SERVER['CONTENT_LENGTH'] ) ) {
                    $message['res_size'] = $_SERVER['CONTENT_LENGTH'];
                } else {
                    $message['res_size'] = 0;
                }

                $actifendArray['message'] = $message;
                $actifend_json = json_encode( $actifendArray );
                $res = $this->utilityObj->actifend_postViaCurl( $final_end_point, $actifend_json );
                $res_json = json_decode($res);

                if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                    if ( ACTIFEND_DEBUG_MODE_ON ) {
                        $res = "EXCEPTION: While opening $final_end_point <br>  Response: = " . json_encode($res_json);
                    } else {
                        $res = "EXCEPTION: While opening $final_end_point";
                    }
                    debug_log( $res );
                } else {
                    $res = "ASSET ID: $asset_id";
                }
            }
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            return $e->getMessage();
            // throw new Exception("Exception 1x03: " . $e->getMessage());
        }
    }


    /**
     * actifend_updatePlugin_logs
     *
     * This function send logs whenever a plugin is updated.
     */
    public function actifend_updatePlugin_logs() {
        try {
            global $wpdb;
            // Actifend::actifend_update_check();
            $result = $this->utilityObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $default_end_point = $result->default_end_point;
                // $size_of_response = 0;
                // if ( count( $_POST ) > 0 ) {
                //     $size_of_response = (int) $_SERVER['CONTENT_LENGTH'];
                // }

                $update_plugin_name = '';
                $new_version = '';
                $update_array = array();
                // $path = plugin_dir_path(__FILE__);
                // echo $path;
                $reportObj = new report;
                $new_plugin_version = $reportObj->new_version_table();
                $old_plugin_version = $reportObj->old_version_table("plugin");
                if ( !empty( $new_plugin_version ) && !empty( $old_plugin_version ) ) {
                    foreach ( $new_plugin_version as $key => $value2 ) {

                        foreach ( $old_plugin_version as $key1 => $value1 ) {
                            if ($value2['name'] == $value1['name'] && $value2['version'] != $value1['version']) {
                                $update_plugin_name = $value2['name'];
                                $new_version = $value2['version'];

                                if (!function_exists( 'get_plugins' )) {
                                    require_once trailingslashit( ADMIN_DIR ) . trailingslashit ('includes') . 'plugin.php';
                                }
                                $all_plugins = get_plugins();
                                foreach ( $all_plugins as $all_update_plugins ) {
                                    if ($update_plugin_name == $all_update_plugins['Name'])
                                        $update_array = $all_update_plugins;
                                }

                                $actifend_update_table = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
                                $wpdb->update($actifend_update_table,
                                              array( "version" => $new_version ),
                                              array( "name" => $update_plugin_name ));

                                $final_end_point = ACTIFEND_ASSETS_END_POINT . $asset_id . '/wpinfo';
                                if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                                    $final_end_point = $default_end_point;
                                }
                                $update_plugin_info[] = array(
                                                            "name"    => @$update_array['Name'],
                                                            "version" => @$update_array['Version']
                                                        );
                                $update_plugins   = $update_plugin_info;

                                $updated_plugin_info = array();
                                $updated_plugin_info['installed_plugins'] = $update_plugins;

                                $actifend_json = json_encode( $updated_plugin_info );
                                $res = $this->utilityObj->actifend_postViaCurl( $final_end_point, $actifend_json, 'PATCH' );
                                $res_json = json_decode($res);

                                if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                                    if ( ACTIFEND_DEBUG_MODE_ON ) {
                                        $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                                    } else {
                                        $res = "EXCEPTION: While opening " . $final_end_point;
                                    }
                                } else {
                                    $res = 'ASSET ID: ' . $asset_id;
                                }
                            }
                        }
                    }
                }

            }
        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x07: ' . $e->getMessage() );
        }
    }

    /**
     * actifendUpdateInstallTrigger
     *
     * This function is used to capture the action when plugins, themes and
     * core are installed and updated. After capturing the action send logs.
     * @param array $option This array contain the what is type for example
     * plugin core or theme and action confirm the update of install.
     */
    public function actifendUpdateInstallTrigger( $options=array('core', 'theme', 'plugin') ) {
        try {
            foreach ( $options as $type ) {
                if ( $type == 'core' ) {
                    $this->actifend_update_core();
                }
                if ( $type == 'theme' ) {
                    $this->actifend_update_theme();
                }
                if ( $type == 'plugin' ) {
                    $this->actifend_updatePlugin_logs();
                }
            }
        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x11: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_update_theme
     *
     * This function is used to send the logs when theme is updated.
     * @param null
     * @return null
     */
    public function actifend_update_theme() {
        try {
            // Actifend::actifend_update_check();
            $result = $this->utilityObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $theme_array = array();
                $reportObj = new report;
                $installed_theme_array = $reportObj->old_version_table( 'theme' );
                if (! function_exists( 'wp_get_themes' ))
                    require_once( trailingslashit( INCLUDES_DIR ) . 'theme.php' );
                $themes = wp_get_themes();
                foreach ( $installed_theme_array as $key => $value ) {
                    foreach ( $themes as $name => $theme ) {
                        if ($theme->get( 'Name' ) == $value['name'] && $theme->get( 'Version' ) != $value['version']) {
                            $theme_array[] =    array(
                                                     'Name' => $theme->get('Name'),
                                                     'Version' => $theme->get('Version'),
                                                     'Author' => $theme->get('Author')
                                                     );
                        }
                    }
                }
                if ( !empty( $theme_array ) && isset( $theme_array ) ) {
                    global $wpdb;
                    $actifend_update_theme_info = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
                    foreach ( $theme_array as $key => $value ) {
                        $name = $value['Name'];
                        $version = $value['Version'];
                        $wpdb->update( $actifend_update_theme_info,
                                      array("version" => $version),
                                      array("name" => $name) );

                        $update_theme_info[] = array(
                                                    "name"    => $name,
                                                    "version" => $version
                                                    );
                    }

                    $final_end_point = ACTIFEND_ASSETS_END_POINT . $asset_id . '/wpinfo';
                    if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                        $final_end_point = $default_end_point;
                    }
                    $updated_themes_info = array();
                    $updated_themes_info['installed_themes'] = $update_theme_info;

                    $actifend_json = json_encode( $updated_themes_info );
                    $res = $this->utilityObj->actifend_postViaCurl( $final_end_point, $actifend_json, 'PATCH' );
                    $res_json = json_decode( $res );

                    if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                        if ( ACTIFEND_DEBUG_MODE_ON ) {
                            $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                        } else {
                            $res = "EXCEPTION: While opening " . $final_end_point;
                        }
                    } else {
                        $res = 'ASSET ID: ' . $asset_id;
                    }
                }
            }
        } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x12: ' . $e->getMessage() );
        }
    }

    /**
     * actifend_update_theme
     *
     * This function is used to send the logs when theme is updated.
     * @param null
     * @return null
     */
     public function actifend_update_core() {
         try {
            $result = $this->utilityObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $default_end_point = $result->default_end_point;
                $actifend_dir = plugin_dir_path( __FILE__ );
                $current_plugin_path_name = plugin_basename( __FILE__ );

                $final_end_point = ACTIFEND_ASSETS_END_POINT . $asset_id . '/wpinfo';
                if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                    $final_end_point = $default_end_point;
                }
                $core_update_info = array();
                $core_update_info['wp_version'] = get_bloginfo( 'version' );

                $json_core_info = json_encode( $core_update_info );
                $res = $this->utilityObj->actifend_postViaCurl( $final_end_point, $json_core_info, 'PATCH' );
                $res_json = json_decode( $res );

                if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                    if ( ACTIFEND_DEBUG_MODE_ON ) {
                        $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                    } else {
                        $res = "EXCEPTION: While opening " . $final_end_point;
                    }
                } else {
                    $res = 'ASSET ID: ' . $asset_id;
                }
            }
         } catch ( Exception $e ) {
            throw new Exception( 'Exception 1x13: ' . $e->getMessage());
         }
     }

    /**
     * actifend_wordpress_updates_available
     *
     * This function is used to send the logs when new updates are available
     * for the wordpress core, plugins and themes.
     */
    public  function actifend_wordpress_updates_available() {
        try {
            $result = $this->utilityObj->getActifendInfo();
            if ( isset( $result->asset_id ) && !empty( $result->asset_id ) ) {
                $asset_id = $result->asset_id;
                $data = wp_get_update_data();
                if ( $data['counts']['plugins'] > 0 || $data['counts']['themes'] > 0 || $data['counts']['wordpress'] > 0 ) {
                    // Check plugin update .........
                    if( !function_exists( 'wp_update_plugins' ) ) {
                        require_once( trailingslashit( INCLUDES_DIR ) . 'update.php' );
                    }
                    $update_info = wp_update_plugins();
                    $update_plugins = get_site_transient('update_plugins');
                    $new_plugins_updates = '';
                    if ( isset( $update_plugins->response ) && !empty( $update_plugins->response ) ) {
                        foreach ( $update_plugins->response as $name => $data ) {
                            $new_plugins_updates[] = array(
                                'name'   => $data->slug,
                                'latest' => $data->new_version
                            );
                        }
                    }

                    // check for wp core update.
                    global $wp_version;
                    wp_version_check();
                    $core_for_update = '';
                    $update_core = get_preferred_from_update_core();
                    if ( $update_core->response == 'upgrade' ) {
                        $core_for_update = $update_core->current;
                    }

                    if ( $update_core->response == 'latest' ) {
                        $core_for_update = $wp_version;
                    }

                    //Check for theme update.
                    wp_update_themes();
                    $new_themes_updates = '';
                    $update_themes = get_site_transient('update_themes');
                    if ( isset( $update_themes->response ) && !empty( $update_themes->response ) ) {
                        foreach ( $update_themes->response as $key => $value ) {
                            $new_themes_updates[] = array(
                                'name' => $value['theme'],
                                'latest' => $value['new_version']
                            );
                        }
                    }
                    $final_end_point = ACTIFEND_WP_UPDATES_END_POINT . $asset_id . "/wpupdate";
                    if ( isset( $default_end_point ) && !empty( $default_end_point ) ) {
                        $final_end_point = $default_end_point;
                    }

                    $data = array();
                    if ( !empty( $core_for_update ) ) $data['core_version'] = $core_for_update;
                    if ( !empty( $new_plugins_updates ) ) $data['plugins'] = $new_plugins_updates;
                    if ( !empty( $new_themes_updates ) ) $data['themes'] = $new_themes_updates;

                    if ( empty( $data ) ) return;
                    // send the update information if any update is available.
                    $json_data = json_encode( $data );
                    $res = $this->utilityObj->actifend_postViaCurl( $final_end_point, $json_data );
                    $res_json = json_decode( $res );

                    if ( empty( $res_json ) || !isset( $res_json->headers ) ) {
                        if ( ACTIFEND_DEBUG_MODE_ON ) {
                            $res = "EXCEPTION: While opening " . $final_end_point . "<br >  Response: = " . json_encode($res_json);
                        } else {
                            $res = "EXCEPTION: While opening $final_end_point";
                        }
                    } else {
                        $res = 'ASSET ID: ' . $asset_id;
                    }
                }
            }
        } catch ( Exception $e ) {
            throw new Exception("Exception 1x30: " . $e->getMessage());
        }
    }
}
?>
<?php

class report {
    // This function will return the new and old versions of plugins and Themes.
    public function old_version_table( $type = 'plugin' ) {
        $old_versions=array();
        global $wpdb;
        if ( $type == 'plugin' ) {
            $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
        }
        if ( $type == 'theme' ) {
            $actifend_table_name = $wpdb->prefix . ACTIFEND_THEMES_TABLE;
        }

        $data = $wpdb->get_results("SELECT name,version FROM  `" . $actifend_table_name . "`;");
        foreach ( $data as $result ) {
            $key=$result->name;
            $old_version[$key]['name']    = $result->name;
            $old_version[$key]['version'] = $result->version;
        }
        return $old_version;
    }

    // This function return the status of the plugin installation.
    public function get_status() {
        $plugin_install_status=array();
        global $wpdb;
        $actifend_table_name = $wpdb->prefix . ACTIFEND_TABLE_VERSION;
        $data = $wpdb->get_results("SELECT status FROM  `" . $actifend_table_name . "`;");
        if (!empty($data)) {
            $plugin_install_status['status'] = $data['0']->status;
        } else {
            $plugin_install_status = false;
        }

        return $plugin_install_status;
    }

    // This function will return the plugins versions currentlly used by wordpress admim.
    public function new_version_table() {
        $version_array = array();
        if ( !function_exists( 'get_plugins' ) ) {
            require_once( trailingslashit( ADMIN_DIR ) . trailingslashit( 'includes' ) . 'plugin.php' );
        }
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $value ) {
            $key=$value['Name'];
            $version_array[$key]['name']    = $value['Name'];
            $version_array[$key]['version'] = $value['Version'];
        }
          return $version_array;
    }

    // This function will return the themes version currently used by wordpress
    public function theme_new_version() {
        if ( !function_exists( 'wp_get_themes' )) {
            require_once( trailingslashit( INCLUDES_DIR ) . 'theme.php' );
        }
        $themes = wp_get_themes();
        foreach ( $themes as $name => $theme ) {
            $key = $theme->get('Name');
            $theme_array[$key]['name']    = $theme->get('Name');
            $theme_array[$key]['version'] = $theme->get('Version');
        }
        return $theme_array;
    }

    // This function will return the new installed plugins.
    public function new_install() {
        try {
            $old_version = $this->old_version_table();
            $new_version = $this->new_version_table();
            foreach( $new_version as $key => $value ) {
                if ( !in_array($value, $old_version )) {
                    $recent_install[] = $value;
                }
            }

            return $recent_install;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception( 'Exception Rx01: ' . $e->getMessage() );
        }
    }

    // This function return the new installed themes array.
    public function new_theme_install() {
        try {
            $old_version = $this->old_version_table("theme");
            $new_version = $this->theme_new_version();
            foreach ($new_version as $key => $value ) {
                if ( !in_array( $value, $old_version ) ) {
                    $recent_theme_install[] = $value;
                }
            }
            return $recent_theme_install;
        } catch ( Exception $e ) {
            debug_log( $e->getMessage() );
            throw new Exception('Exception Rx02: ' . $e->getMessage());
        }

    }
}
?>
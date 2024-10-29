<?php
define( 'ACTIFEND_PORT',443 );
$actifendHost = 'https://app.dsdinfosec.com';

// endpoints
define( 'ACTIFEND_REGISTER_END_POINT', $actifendHost . '/apiv2/assets/provision' );
define( 'ACTIFEND_EVENTS_END_POINT', $actifendHost . '/events' );
define( 'ACTIFEND_ASSETS_END_POINT', $actifendHost . '/apiv2/assets/' );
define( 'ACTIFEND_WP_UPDATES_END_POINT', $actifendHost . '/apiv2/assets/' );
define( 'ACTIFEND_IP_GET_END_POINT', $actifendHost . '/apiv2/assets/' );
define( 'ACTIFEND_BACKUP_END_POINT', $actifendHost . '/apiv2/assets/' );
define( 'ACTIFEND_RESTORE_END_POINT', $actifendHost . '/apiv2/assets/' );

// other plugin vars
define( 'ACTIFEND_PLUGIN_VERSION', '1.6.2' );
define( 'ACTIFEND_SSL_VERIFY', FALSE );
define( 'ACTIFEND_DSD_CRT_FILE', trailingslashit( WP_PLUGIN_DIR ) . 'actifend/crt/dsdinfosec.crt' );
define( 'ACTIFEND_CURL_TIMEOUT', 20 );
define( 'ACTIFEND_SALT1', '722a8730cba22046271959e0dc372d55a8ebe3c2e8fb7a3300a3992e2e5f112f' );
define( 'ACTIFEND_SECRET_KEY', '271959e0dc372d55a834d3d34ebe3c2e8fb7a3300a3992e2e5f112f' );
define( 'ACTIFEND_ASSET_TYPE', 'wordpress' );
define( 'ACTIFEND_SALT2', 'd3AtcGx1Z2luQGRzZGluZm9zZS5jb20=' );
define( 'ACTIFEND_TABLE_NAME', 'actifend_table' );
define( 'ACTIFEND_DEBUG_MODE_ON', FALSE );
define( 'ACTIFEND_PLUGIN_URL', site_url() . '/wp-admin/admin.php?page=get_actifend_email' );
define( 'ACTIFEND_TABLE_VERSION', 'actifend_install_detail' );
define( 'ACTIFEND_TABLE_IP_BLOCKED', 'actifend_blocked_ips' );
define( 'ACTIFEND_THEMES_TABLE', 'actifend_themes_detail' );
define( 'ACTIFEND_INTEGRITY_FILES_TABLE', 'actifend_integrity_files' );
define( 'ACTIFEND_INTEGRITY_HASHES_TABLE', 'actifend_integrity_hashes' );

// Actifend BACKUP folder - place where Actifend places the files before backup
define( 'BACKUP_DIR', trailingslashit( WP_CONTENT_DIR ) . 'wp_backup' );
// Actifend BACKUP file name
define( 'BACKUP_FILE', 'wp_backup.zip' );

// Directory constants for Backup & Restore
// Themes directory
// define('THEMES_DIR', (is_link(get_theme_root()) ? readlink(get_theme_root()) : get_theme_root()));
define( 'THEMES_DIR', get_theme_root() );
// if (is_link(get_theme_root()))
//     define('THEMES_DIR', readlink(get_theme_root()));
// else
//     define('THEMES_DIR', get_theme_root());

// wp-admin directory
define( 'ADMIN_DIR', trailingslashit( ABSPATH ) . 'wp-admin' );

// wp-includes directory
define( 'INCLUDES_DIR', trailingslashit( ABSPATH ) . 'wp-includes' );
// if (is_link(trailingslashit( ABSPATH ) . 'wp-includes'))
//     define('INCLUDES_DIR', readlink(trailingslashit( ABSPATH ) . 'wp-includes'));
// else
//     define('INCLUDES_DIR', trailingslashit( ABSPATH ) . 'wp-includes');

// Constants defined by Wordpress
// WP_CONTENT_DIR  // no trailing slash, full paths only
// WP_CONTENT_URL  // full url
// WP_PLUGIN_DIR  // full path, no trailing slash
// WP_PLUGIN_URL  // full url, no trailing slash

// Available per default in MS, not set in single site install
// Can be used in single site installs (as usual: at your own risk)
// UPLOADS (If set, uploads folder, relative to ABSPATH) (for e.g.: /wp-content/uploads)
$uploads_dir = wp_upload_dir();
define( 'UPLOADS_DIR', $uploads_dir['path'] );
?>

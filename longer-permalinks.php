<?php 

/*
Plugin Name: Longer Permalinks

Plugin URI: https://github.com/gecon/longer-permalinks/archive/master.zip

Description: This plugin allows longer permalinks by extending slug length (post_name) from default 200 to 3000.
In a way that is future WordPress core updates compatible, by extending always the current/installed core functionality.
Useful for permalinks using non latin characters in URLs. Long permalinks will now work.

Author: Giannis Economou

Version: 1.30

Author URI: http://www.antithesis.gr

*/

defined( 'ABSPATH' ) OR exit;

define('LONGER_PERMALINKS_PLUGIN_VERSION', "130");
define('REDEF_FILE', WP_PLUGIN_DIR."/longer-permalinks/sanitize_override.inc");

register_activation_hook( __FILE__, 'longer_permalinks_plugin_install' );

$last_plugin_ver = get_option('longer-permalinks-pluginver');
$last_wp_ver = get_option('longer-permalinks-wpver');
$current_wp_ver = get_bloginfo('version');
$last_db_ver = get_option('longer-permalinks-dbver');
$current_db_ver = get_option('db_version');


$redefined = file_exists(REDEF_FILE);

// First install or updating plugin from 1.14- or updating version 1.30
if ( empty($last_plugin_ver) || ($last_plugin_ver == '') || $last_plugin_ver < '130' ) {
        // Mark the need to backup all post_names so far
        update_option( 'longer-permalinks-backup-needed', 1 );
        update_option( 'longer-permalinks-wpver', $current_wp_ver );
        update_option( 'longer-permalinks-dbver', $current_db_ver );
}

// Plugin update
if ($last_plugin_ver != LONGER_PERMALINKS_PLUGIN_VERSION) {
        update_option( 'longer-permalinks-backup-needed', 1 );
        update_option( 'longer-permalinks-pluginver', LONGER_PERMALINKS_PLUGIN_VERSION );
}

// Backup all post_names if needed
if ( get_option('longer-permalinks-backup-needed') == 1 ) {
        longer_permalinks_backup_existing_postnames();
}


if ( ($last_wp_ver != $current_wp_ver) || ($last_db_ver != $current_db_ver) ) {
        longer_permalinks_alter_post_name_length();

    if ($last_wp_ver != $current_wp_ver)
                update_option( 'longer-permalinks-wpver', $current_wp_ver );
    if ($last_db_ver != $current_db_ver)
                update_option( 'longer-permalinks-dbver', $current_db_ver );
}


if ( !$redefined || ($last_wp_ver != $current_wp_ver) ) {
        $redefined = redefine_sanitize_title_with_dashes();
}
if ($redefined) {
        include(REDEF_FILE);
        // Replace the standard filter
        remove_filter( 'sanitize_title', 'sanitize_title_with_dashes' );
        add_filter( 'sanitize_title', 'longer_permalinks_sanitize_title_with_dashes', 10, 3 );
}


// Restore longer permalinks in case of wp upgrade
// Applying default wp db schema on upgrade will truncate post_name
// We cannot filter anything on upgrade process, so we revert our longer slugs
if ( (!get_option('longer-permalinks-backup-needed')) && (get_option('longer-permalinks-revert-needed') == 1) ) {
                #error_log("Longer Permalinks - proceed to revert.");
                longer_permalinks_revert_longer_titles();
}


// Keep our longer slugs backups on post updates
add_action('save_post', 'longer_permalinks_backup_post_name_on_update', 10,2);
add_action('wp_insert_post', 'longer_permalinks_backup_post_name_on_update', 10,2);
add_action('rest_after_insert_post', 'longer_permalinks_backup_post_name_on_update', 10,2);

// Update our post_name backup when post is updated
function longer_permalinks_backup_post_name_on_update($post_ID, $post_after) {
    // Do not proceed on auto-draft, auto-save, and post revisions
    if (wp_is_post_revision($post_ID) !== false) {
            return;
    }

    // Check if post status is 'auto-draft'
    if (isset($post_after->post_status) && $post_after->post_status === 'auto-draft') {
            return;
    }

    // Check if autosave is being performed
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
    }

    // Ignore ACF post_types
    if (isset($post_after->post_type) && substr($post_after->post_type, 0, 4) === 'acf-') {
            return;
    }

    
    update_post_meta($post_ID, 'longer-permalinks-post-name-longer', $post_after->post_name);
}


function longer_permalinks_revert_longer_titles() {
    global $wpdb;

    // Lock name for the operation, truncated to 60 characters for compatibility
    $lock_name = substr(DB_NAME . '_' . __FUNCTION__, 0, 60);

    $get_lock_sql = "SELECT GET_LOCK('$lock_name',0)";
    $release_lock_sql = "SELECT RELEASE_LOCK('$lock_name')";

    // Using direct sql for speed (avoid long delays on sites with a lot of posts)
    // Our UPDATE is safe even if postname backup failed in the beginning and later succeeded (UPDATE uses the first match)
    $sql = "UPDATE {$wpdb->prefix}posts p JOIN {$wpdb->prefix}postmeta m ON m.post_id = p.ID SET p.post_name = m.meta_value WHERE m.meta_key = 'longer-permalinks-post-name-longer';";

    // Try to acquire the lock to avoid multiple runs  (we would prefer a transaction but maybe InnoDB is not in use)
    if ($wpdb->get_var($get_lock_sql) ) {
            #error_log("Longer Permalinks - got a lock will exec the revert.");

            // Execute the update query and set the revert flag option if successful (to update on next call)
            if ($wpdb->query($sql) !== false) {
                    update_option( 'longer-permalinks-revert-needed', 0 );
            }

            #error_log("Longer Permalinks - releasing the lock (revert).");

            // Release the lock after the operation
            $wpdb->query($release_lock_sql);
    } else {
            #error_log("Longer Permalinks - could not acquire LOCK for longer permalinks restore.");
    }
}


function longer_permalinks_backup_existing_postnames() {
    global $wpdb;

    // Lock name for the operation, truncated to 60 characters for compatibility
    $lock_name = substr(DB_NAME . '_' . __FUNCTION__, 0, 60);

    $get_lock_sql="SELECT GET_LOCK('$lock_name',0)";
    $release_lock_sql="SELECT RELEASE_LOCK('$lock_name')";

    // Using direct sql for speed (avoid delays on sites with a lot of posts)
    $sql_delete="DELETE FROM {$wpdb->prefix}postmeta WHERE {$wpdb->prefix}postmeta.meta_key = 'longer-permalinks-post-name-longer'";
    $sql_insert="INSERT INTO {$wpdb->prefix}postmeta (post_id, meta_key, meta_value) SELECT ID, 'longer-permalinks-post-name-longer', {$wpdb->prefix}posts.post_name FROM {$wpdb->prefix}posts WHERE post_type != 'revision' AND post_status != 'auto-draft' AND post_type NOT LIKE 'acf-%'";

    // Try to acquire the lock to avoid multiple runs (we would prefer a transaction but maybe InnoDB is not in use)
    if ($wpdb->get_var($get_lock_sql) ) {
            #error_log("Longer Permalinks - got a lock will exec the backup.");

            if ( $wpdb->query($sql_delete) !== false && $wpdb->query($sql_insert) !== false ) {
                    update_option('longer-permalinks-backup-needed', '0' );
            }
            #error_log("Longer Permalinks - releasing the lock (backup).");

            // Release the lock after the operation
            $wpdb->query($release_lock_sql);
    } else {
            #error_log("Longer Permalinks - could not acquire LOCK for longer permalinks backup.");
    }
}


function redefine_sanitize_title_with_dashes() {
    if ( !is_writable( dirname(REDEF_FILE) ) ) {
        add_action('admin_notices','longer_permalinks_notice__error_dir_write_access');
        return 0;
    }
    if ( file_exists(REDEF_FILE) && !is_writable( REDEF_FILE ) ) {
        add_action('admin_notices','longer_permalinks_notice__error_file_write_access');
        return 0;
    }

    try {
        // Get the core function with Reflection
        $func = new ReflectionFunction('sanitize_title_with_dashes');
        $filename = $func->getFileName();
        $start_line = $func->getStartLine() - 1;
        $end_line = $func->getEndLine();
        $length = $end_line - $start_line;

        $source = file($filename);
        $body = implode("", array_slice($source, $start_line, $length));

        $body = preg_replace('/function sanitize_title_with_dashes/','function longer_permalinks_sanitize_title_with_dashes',$body);
        $body = preg_replace('/\$title = utf8_uri_encode\( ?\$title\, 200\ ?\);/','$title = utf8_uri_encode($title, 3000);',$body, -1, $success);

        if ($success) {
            if (strlen($body) > 0) {
                $body = '<' . "?php\n" .$body;
                file_put_contents(REDEF_FILE, $body);
                return 1;
            }
            // Indeed unexpected
            add_action('admin_notices','longer_permalinks_notice__error_unexpected');
        }
        else {
            // Could not apply core changes - new WordPress version probably (keypoint differences on sanitize_title_with_dashes)
            add_action('admin_notices','longer_permalinks_notice__error_extending_core');
        }

    } catch (ReflectionException $e) {
        // Reflection error
        add_action('admin_notices', ERROR_EXTENDING_CORE);
    }

    return 0;
}

function longer_permalinks_notice__error_dir_write_access() {
    $error_message = __('Could not write into plugin directory.') . REDEF_FILE . "<br>";
    $error_message .= __('Plugin Longer Permalinks will not work. Please make plugin directory writable.');

    printf(
        '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
        esc_html($error_message)
    );
}

function longer_permalinks_notice__error_file_write_access() {
    $error_message = __('Could not write file ') . REDEF_FILE . "<br>";
    $error_message .= __('Plugin Longer Permalinks will not work. Please make file writable.');

    printf(
         '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
         esc_html($error_message)
    );
}


function longer_permalinks_notice__error_extending_core() {
    $error_message = __('Could not apply required functionality to core') . "<br>";
    $error_message .= __('Plugin Longer Permalinks could not extend required core functionality. The plugin seems not compatible with your WordPress version. Please contact developer about it.');

    printf(
         '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
         esc_html($error_message)
    );
}
function longer_permalinks_notice__error_unexpected() {
    $error_message = __('Could not apply required functionality to core') . "<br>";
    $error_message .= __('Plugin Longer Permalinks could not extend required core functionality, due to an unexpected error. Please contact developer about it.');

    printf(
         '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
         esc_html($error_message)
    );
}

function longer_permalinks_plugin_install() {
    global $wpdb;

    if ( !current_user_can( 'activate_plugins' ) )
            return;

    try {
            longer_permalinks_alter_post_name_length();
            update_option( 'longer-permalinks-backup-needed', 1 );
    } catch ( Exception $e ) {
            // Handle any exceptions that occur during the operation.
            error_log( 'Error installing Longer Permalinks plugin: ' . $e->getMessage() );
    }

}



//update posts table field length
function longer_permalinks_alter_post_name_length() {
        global $wpdb;

       // check MySQL version
       $mysql_version = $wpdb->db_version();
       if ( version_compare( $mysql_version, '5.0.3', '<' ) ) {
           trigger_error( __('Plugin requires at least MySQL 5.0.3 - Plugin will fail'), E_USER_ERROR );
           return;
       }

        $sql = "CREATE TABLE {$wpdb->prefix}posts (
          post_name varchar(3000) DEFAULT '' NOT NULL
        );";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        if($wpdb->last_error !== '') {
                trigger_error( _e('Longer Permalinks plugin got an error applying required changes to the database'), E_USER_ERROR );
        }


    update_option( 'longer-permalinks-revert-needed', 1 ); // to update on next call
}


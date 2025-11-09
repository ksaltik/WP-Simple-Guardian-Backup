<?php
/**
 * Plugin Name: WP Simple Guardian Backup
 * Plugin URI: https://example.com/
 * Description: Creates a full backup of the WordPress directory and the database defined in wp-config.php.
 * Version: 1.3
 * Author: Kenan SALTIK
 * Author URI: https://oneyhosting.com
 * License: GPL2
 */

defined( 'ABSPATH' ) || exit;

// --- Plugin Constants and Hooks ---

// Define backup directory path
if ( ! defined( 'WP_SIMPLE_BACKUP_DIR' ) ) {
    define( 'WP_SIMPLE_BACKUP_DIR', WP_CONTENT_DIR . '/backups/' );
}

// Add admin menu item
add_action( 'admin_menu', 'wsgb_add_admin_menu' );

/**
 * Adds the 'WP Guardian Backup' item under the Tools menu.
 */
function wsgb_add_admin_menu() {
    $hook_suffix = add_management_page(
        'WP Guardian Backup',         // Page Title
        'Simple Guardian Backup',     // Menu Title
        'manage_options',             // Capability required
        'wsgb_backup_page',           // Menu slug
        'wsgb_backup_page_content'    // Function to display the page content
    );
    // Add hook to enqueue scripts only on our plugin page
    add_action( 'admin_enqueue_scripts', function( $hook ) use ( $hook_suffix ) {
        if ( $hook === $hook_suffix ) {
            wsgb_enqueue_admin_scripts();
        }
    });
}

// Handle the backup process (now via AJAX)
add_action( 'wp_ajax_wsgb_backup_ajax', 'wsgb_backup_ajax_handler' );

// Handle deleting files (still uses admin_init for link-based action)
add_action( 'admin_init', 'wsgb_handle_delete_action' );

/**
 * Displays any pending admin notices after redirect.
 */
function wsgb_display_admin_notices() {
    if ( $messages = get_transient( 'wsgb_backup_messages' ) ) {
        delete_transient( 'wsgb_backup_messages' );
        foreach ( $messages as $message ) {
            echo '<div class="' . esc_attr( 'notice notice-' . $message['type'] ) . ' is-dismissible"><p>' . wp_kses_post( $message['message'] ) . '</p></div>';
        }
    }
}
add_action( 'admin_notices', 'wsgb_display_admin_notices' );


// --- Cron Task for Background Backup ---

/**
 * Hook for the scheduled cron event.
 */
add_action( 'wsgb_run_backup_cron', 'wsgb_execute_backup_cron' );

/**
 * Executes the backup process in the background via WP-Cron.
 */
function wsgb_execute_backup_cron() {
    // Increase execution time limit for this background process
    @set_time_limit( 600 ); // 10 minutes
    @ignore_user_abort( true );

    // Run the main backup function
    $backup_file_path = wsgb_create_full_backup();

    $result_data = [
        'time' => time(),
    ];

    if ( is_wp_error( $backup_file_path ) ) {
        $result_data['status'] = 'error';
        $result_data['message'] = $backup_file_path->get_error_message();
    } else {
        $result_data['status'] = 'success';
        $result_data['file'] = basename( $backup_file_path );
        $result_data['size'] = filesize( $backup_file_path );
    }

    // Store the result of the last backup
    update_option( 'wsgb_last_backup_result', $result_data );

    // Delete the 'running' transient
    delete_transient( 'wsgb_backup_running' );
}


// --- Core Backup Functions ---
// [Functions wsgb_create_full_backup, wsgb_backup_database, wsgb_backup_database_pure_php, wsgb_create_zip_archive remain unchanged]

/**
 * Main function to coordinate the directory and database backup.
 *
 * @return string|WP_Error Path to the final ZIP file on success, WP_Error otherwise.
 */
function wsgb_create_full_backup() {
    // 1. Prepare backup directory
    if ( ! wp_mkdir_p( WP_SIMPLE_BACKUP_DIR ) ) {
        return new WP_Error( 'dir_creation_failed', 'Could not create backup directory: ' . WP_SIMPLE_BACKUP_DIR );
    }

    $timestamp = gmdate( 'Ymd-His' ); // Use gmdate() for consistency
    $temp_name = "full-backup-{$timestamp}";
    $db_sql_file = WP_SIMPLE_BACKUP_DIR . "{$temp_name}.sql";
    $zip_file_path = WP_SIMPLE_BACKUP_DIR . "{$temp_name}.zip";

    // 2. Backup Database
    $db_result = wsgb_backup_database( $db_sql_file );
    if ( is_wp_error( $db_result ) ) {
        return $db_result;
    }

    // 3. Create ZIP archive (Files + SQL file)
    $zip_result = wsgb_create_zip_archive( $zip_file_path, $db_sql_file );
    if ( is_wp_error( $zip_result ) ) {
        // Clean up the temporary SQL file before returning error
        if ( file_exists( $db_sql_file ) ) {
            wp_delete_file( $db_sql_file );
        }
        return $zip_result;
    }

    // 4. Clean up the temporary SQL file
    if ( file_exists( $db_sql_file ) ) {
        wp_delete_file( $db_sql_file );
    }

    return $zip_file_path;
}


/**
 * Backs up the WordPress database to a SQL file.
 *
 * @param string $output_file_path Path where the SQL file will be saved.
 * @return true|WP_Error True on success, WP_Error otherwise.
 */
function wsgb_backup_database( $output_file_path ) {
    global $wpdb;

    $mysqldump_success = false;
    $mysqldump_output = '';

    // 1. Try to use mysqldump first (fastest method)
    if ( function_exists( 'shell_exec' ) && ! ini_get( 'safe_mode' ) ) {
        $host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
        $user = defined( 'DB_USER' ) ? DB_USER : '';
        $pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';
        $name = defined( 'DB_NAME' ) ? DB_NAME : '';

        // Construct the mysqldump command
        $command = sprintf(
            'mysqldump --opt -h %s -u %s -p%s %s > %s 2> %s.err',
            escapeshellarg( $host ),
            escapeshellarg( $user ),
            escapeshellarg( $pass ), // Note: no space between -p and password
            escapeshellarg( $name ),
            escapeshellarg( $output_file_path ),
            escapeshellarg( $output_file_path ) // Will create a .sql.err file
        );

        $mysqldump_output = shell_exec( $command );
        $error_file = $output_file_path . '.err';

        // Check if the command succeeded (backup file > 0 bytes AND error file is 0 bytes)
        if ( file_exists( $output_file_path ) && filesize( $output_file_path ) > 0 && file_exists( $error_file ) && filesize( $error_file ) == 0 ) {
            // Success!
            $mysqldump_success = true;
            wp_delete_file( $error_file ); // Clean up empty error file
            return true;
        } else {
            // Failure
            $mysqldump_success = false;
            // Get error message for logging, if it exists
            if ( file_exists( $error_file ) && filesize( $error_file ) > 0 ) {
                $mysqldump_output = file_get_contents( $error_file );
            } else if ( empty( $mysqldump_output ) ) {
                $mysqldump_output = 'mysqldump check failed. File size 0 or error file not created.';
            }
            wp_delete_file( $error_file ); // Clean up the error file
            wp_delete_file( $output_file_path ); // Clean up the failed (likely empty or error-filled) sql file
        }
    }

    // 2. If mysqldump failed or wasn't available, use the pure PHP fallback
    if ( ! $mysqldump_success ) {
        // Log the mysqldump failure if it was tried
        if ( ! empty( $mysqldump_output ) ) {
            // This isn't shown to user, but good for debugging if we add a log file later.
            error_log( 'WSGB: mysqldump failed. Output: ' . $mysqldump_output );
        }

        // Fallback: Use slower, pure PHP method
        return wsgb_backup_database_pure_php( $output_file_path );
    }

    // This line should not be reachable, but as a failsafe:
    return new WP_Error( 'db_backup_failed', 'An unknown database backup error occurred.' );
}

/**
 * Pure PHP fallback for backing up the database.
 * (Simplified for brevity and to avoid large memory usage for huge tables)
 *
 * @param string $output_file_path Path where the SQL file will be saved.
 * @return true|WP_Error True on success, WP_Error otherwise.
 */
function wsgb_backup_database_pure_php( $output_file_path ) {
    global $wpdb, $wp_filesystem;

    // Initialize the WP_Filesystem
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }

    $tables = $wpdb->get_results( "SHOW TABLES LIKE '{$wpdb->prefix}%'", ARRAY_N );

    if ( empty( $tables ) ) {
        return new WP_Error( 'no_tables', 'No WordPress tables found to backup.' );
    }

    $sql_content = ''; // Store SQL content in a variable

    // Write file header
    $sql_content .= "-- WordPress Database Backup\n";
    $sql_content .= "-- Host: " . esc_html( DB_HOST ) . "\n";
    $sql_content .= "-- Database: " . esc_html( DB_NAME ) . "\n";
    $sql_content .= "-- Generation Time: " . gmdate( 'M d, Y \a\t H:i' ) . " GMT\n\n"; // Use gmdate()
    $sql_content .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    $sql_content .= "START TRANSACTION;\n";
    $sql_content .= "SET time_zone = \"+00:00\";\n\n";

    foreach ( $tables as $table_row ) {
        // Sanitize table name by removing backticks and re-wrapping them
        // This prevents SQL injection vulnerabilities flagged by scanners.
        $table_name = '`' . str_replace( '`', '', $table_row[0] ) . '`';

        // 1. Table structure
        // Note: $table_name now includes its own backticks, so they are removed from the query.
        $table_schema = $wpdb->get_results( "SHOW CREATE TABLE {$table_name}", ARRAY_N );
        if ( ! empty( $table_schema ) ) {
            $sql_content .= "DROP TABLE IF EXISTS {$table_name};\n";
            $sql_content .= $table_schema[0][1] . ";\n\n";
        }

        // 2. Table data
        $row_offset = 0;
        $row_count = 100; // Process 100 rows at a time to save memory

        // Note: $table_name now includes its own backticks, so they are removed from the query.
        while ( $rows = $wpdb->get_results( "SELECT * FROM {$table_name} LIMIT {$row_offset}, {$row_count}", ARRAY_A ) ) {
            if ( ! empty( $rows ) ) {
                // Note: $table_name now includes its own backticks, so they are removed from the query.
                $insert_sql = "INSERT INTO {$table_name} VALUES \n";
                $value_rows = [];

                foreach ( $rows as $row ) {
                    $row_values = [];
                    foreach ( $row as $value ) {
                        if ( is_null( $value ) ) {
                            $row_values[] = 'NULL';
                        } else {
                            // Use $wpdb->_real_escape() for proper escaping
                            $row_values[] = "'" . $wpdb->_real_escape( $value ) . "'";
                        }
                    }
                    $value_rows[] = '(' . implode( ', ', $row_values ) . ')';
                }

                $insert_sql .= implode( ",\n", $value_rows ) . ";\n\n";
                $sql_content .= $insert_sql;
            }

            if ( count( $rows ) < $row_count ) {
                // We've processed all rows for this table
                break;
            }

            $row_offset += $row_count;
        }
    }

    $sql_content .= "\nCOMMIT;\n";

    // Write the content to the file using WP_Filesystem
    if ( ! $wp_filesystem->put_contents( $output_file_path, $sql_content ) ) {
        return new WP_Error( 'file_write_error', 'Could not write SQL file using WP_Filesystem: ' . $output_file_path );
    }

    return true;
}


/**
 * Creates a ZIP archive of the entire WordPress root directory, including the SQL file.
 *
 * @param string $zip_file_path Path where the ZIP file will be saved.
 * @param string $db_sql_file Path to the database SQL file to include.
 * @return true|WP_Error True on success, WP_Error otherwise.
 */
function wsgb_create_zip_archive( $zip_file_path, $db_sql_file ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return new WP_Error( 'zip_archive_missing', 'The PHP ZipArchive module is not installed on your server. File backup cannot proceed.' );
    }

    $zip = new ZipArchive();
    if ( $zip->open( $zip_file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== TRUE ) {
        return new WP_Error( 'zip_open_failed', 'Cannot create ZIP file.' );
    }

    // Add the temporary SQL file to the root of the ZIP
    if ( file_exists( $db_sql_file ) ) {
        $zip->addFile( $db_sql_file, basename( $db_sql_file ) );
    }

    // Recursively add the entire WordPress root directory
    $root_path = rtrim( ABSPATH, '/\\' ); // Ensure no trailing slash
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $root_path, RecursiveDirectoryIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ( $files as $file ) {
        $file = realpath( $file );
        $relative_path = ltrim( str_replace( $root_path, '', $file ), '/\\' );

        // Exclude common directories and the zip file itself
        $exclude_paths = [
            'wp-admin',
            'wp-includes',
            'wp-content/backups',
            'wp-content/cache',
            'wp-content/upgrade',
            'wp-content/w3tc-cache',
            'wp-content/wp-rocket-cache',
            basename( $zip_file_path )
        ];
        
        $excluded = false;
        foreach ( $exclude_paths as $path ) {
            if ( strpos( $relative_path, $path ) === 0 ) {
                $excluded = true;
                break;
            }
        }

        if ( $excluded ) {
            continue;
        }

        if ( is_dir( $file ) ) {
            $zip->addEmptyDir( $relative_path );
        } else if ( is_file( $file ) ) {
            $zip->addFile( $file, $relative_path );
        }
    }

    $close_result = $zip->close();

    if ( $close_result === true && file_exists( $zip_file_path ) && filesize( $zip_file_path ) > 1024 ) { // Ensure file size > 1KB
        return true;
    }

    return new WP_Error( 'zip_creation_failed', 'ZIP archive creation failed or resulted in an empty file.' );
}

// --- Admin Page Content ---

/**
 * Enqueues the admin JavaScript and localizes data.
 */
function wsgb_enqueue_admin_scripts() {
    // We will inject the script inline in the page content function
    // but we can still pass data to it.
    wp_localize_script( 'jquery', 'wsgb_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wsgb_ajax_nonce' ),
    ]);
}

/**
 * Displays the content for the admin page.
 */
function wsgb_backup_page_content() {
    global $wp_filesystem; // Add this
    // Initialize the WP_Filesystem
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    ?>
    <div class="wrap" id="wsgb-backup-page">
        <h1>WP Simple Guardian Backup</h1>
        <hr>
        <p>This utility performs a full backup of your entire WordPress directory (excluding the backups folder) and the database defined in your `wp-config.php` file.</p>
        <p>The backup will be saved as a single ZIP file in the <code>wp-content/backups/</code> directory.</p>

        <div id="wsgb-backup-status-container">
            <?php echo wsgb_get_backup_status_html(); ?>
        </div>

        <h2>Create New Backup</h2>
        
        <?php if ( ! class_exists( 'ZipArchive' ) ): ?>
            <div class="notice notice-error">
                <p><strong>CRITICAL ERROR:</strong> The PHP <code>ZipArchive</code> module is not installed on your server. This plugin cannot create the file backup archives. Please contact your host to enable it.</p>
            </div>
        <?php endif; ?>

        <?php if ( ! $wp_filesystem->is_writable( WP_SIMPLE_BACKUP_DIR ) && ! wp_mkdir_p( WP_SIMPLE_BACKUP_DIR ) ): // Use $wp_filesystem->is_writable() ?>
            <div class="notice notice-error">
                <p><strong>ERROR:</strong> The backup directory <code><?php echo esc_html( WP_SIMPLE_BACKUP_DIR ); ?></code> is not writable. Please check file permissions (recommended 755 or 777) to ensure backups can be saved.</p>
            </div>
        <?php endif; ?>

        <div id="wsgb-backup-controls-container">
            <?php echo wsgb_get_backup_controls_html(); ?>
        </div>

        <h2 style="margin-top: 40px;">Manage Existing Backups</h2>
        <div id="wsgb-backup-list-container">
            <?php echo wsgb_get_list_backups_html(); ?>
        </div>

    </div>
    
    <!-- Add inline JavaScript for AJAX handling -->
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            
            var wsgb_polling_interval = null;

            // --- Main function to refresh UI ---
            function wsgb_refresh_ui() {
                // Show a small spinner
                $('#wsgb-main-spinner').css('visibility', 'visible');
                
                $.post(wsgb_ajax.ajax_url, {
                    action: 'wsgb_backup_ajax',
                    _ajax_nonce: wsgb_ajax.nonce,
                    sub_action: 'get_status'
                }, function(response) {
                    if (response.success) {
                        // Update all 3 sections
                        $('#wsgb-backup-status-container').html(response.data.status_html);
                        $('#wsgb-backup-controls-container').html(response.data.controls_html);
                        $('#wsgb-backup-list-container').html(response.data.list_html);
                        
                        // Check if we need to start or stop polling
                        if (response.data.backup_status === 'running' && wsgb_polling_interval === null) {
                            // Start polling
                            wsgb_polling_interval = setInterval(wsgb_refresh_ui, 5000); // Poll every 5 seconds
                        } else if (response.data.backup_status !== 'running' && wsgb_polling_interval !== null) {
                            // Stop polling
                            clearInterval(wsgb_polling_interval);
                            wsgb_polling_interval = null;
                        }
                    } else {
                        // Show error
                        $('#wsgb-backup-controls-container').prepend('<div class="notice notice-error is-dismissible"><p>Error updating status: ' + response.data.message + '</p></div>');
                    }
                    
                    $('#wsgb-main-spinner').css('visibility', 'hidden');
                });
            }
            
            // --- Event Handlers ---
            
            // Use event delegation for buttons that might be replaced
            $('#wsgb-backup-page').on('click', '#wsgb-start-backup-btn', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Scheduling...');
                $('#wsgb-main-spinner').css('visibility', 'visible');
                
                $.post(wsgb_ajax.ajax_url, {
                    action: 'wsgb_backup_ajax',
                    _ajax_nonce: wsgb_ajax.nonce,
                    sub_action: 'start_backup'
                }, function(response) {
                    if(response.success) {
                        // The UI refresh will be handled by the poller, 
                        // but we trigger one immediately to show the "running" state
                        wsgb_refresh_ui();
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false).text('Start Full Backup Now');
                    }
                });
            });

            $('#wsgb-backup-page').on('click', '#wsgb-cancel-backup-btn', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Cancelling...');
                $('#wsgb-main-spinner').css('visibility', 'visible');
                
                $.post(wsgb_ajax.ajax_url, {
                    action: 'wsgb_backup_ajax',
                    _ajax_nonce: wsgb_ajax.nonce,
                    sub_action: 'cancel_backup'
                }, function(response) {
                    if(response.success) {
                        // Trigger immediate UI refresh to show the "idle" state
                        wsgb_refresh_ui();
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false).text('Force Cancel Stuck Backup');
                    }
                });
            });

            // --- Initial Load ---
            // Start polling if the page loads and a backup is already running
            if ( $('#wsgb-backup-controls-container').find('#wsgb-cancel-backup-btn').length > 0 ) {
                 wsgb_polling_interval = setInterval(wsgb_refresh_ui, 5000); // Poll every 5 seconds
            }

        });
    </script>
    <?php
}

/**
 * Returns the HTML for the backup status section.
 * @return string HTML
 */
function wsgb_get_backup_status_html() {
    ob_start(); // Start output buffering
    
    $last_backup = get_option( 'wsgb_last_backup_result' );
    if ( empty( $last_backup ) ) {
        echo '<h2>Backup Status</h2>';
        echo '<div class="notice notice-warning inline"><p>No backups have been run yet.</p></div>';
        return ob_get_clean();
    }

    $date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_backup['time'] );

    if ( $last_backup['status'] === 'success' ) {
        $size = size_format( $last_backup['size'], 2 );
        $url  = content_url( 'backups/' . $last_backup['file'] );
        
        echo '<h2>Last Backup Status</h2>';
        echo '<div class="notice notice-success inline">';
        echo '<p><strong>Last backup successful:</strong> ' . esc_html( $date ) . '</p>';
        echo '<p>File: <code>' . esc_html( $last_backup['file'] ) . '</code> (' . esc_html( $size ) . ')';
        echo ' <a href="' . esc_url( $url ) . '" class="button-secondary">Download</a></p>';
        echo '</div>';

    } else {
        echo '<h2>Last Backup Status</h2>';
        echo '<div class="notice notice-error inline">';
        echo '<p><strong>Last backup failed:</strong> ' . esc_html( $date ) . '</p>';
        echo '<p>Error: ' . esc_html( $last_backup['message'] ) . '</p>';
        echo '</div>';
    }
    
    return ob_get_clean(); // Return buffered output
}

/**
 * Returns the HTML for the backup controls (buttons).
 * @return string HTML
 */
function wsgb_get_backup_controls_html() {
    global $wp_filesystem; // Add this
    // Initialize the WP_Filesystem
    if ( empty( $wp_filesystem ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    ob_start();
    ?>
    <span class="spinner" id="wsgb-main-spinner" style="visibility: hidden; float: none; margin: -2px 10px 0 0;"></span>
    
    <?php if ( get_transient( 'wsgb_backup_running' ) ) : ?>
        
        <div class="notice notice-warning inline" style="margin-bottom: 15px;">
            <p><strong>A backup is currently in progress...</strong></p>
        </div>
        <p>If the backup has been running for a long time, you can force cancel it.</p>
        
        <button type="button" id="wsgb-cancel-backup-btn" class="button button-secondary">
            <span class="dashicons dashicons-no-alt" style="line-height: 1.6;"></span>
            Force Cancel Stuck Backup
        </button>

    <?php else : ?>

        <p class="submit">
            <button type="button" id="wsgb-start-backup-btn" class="button button-primary button-hero" 
                <?php 
                // Use $wp_filesystem->is_writable()
                $is_disabled = ! class_exists( 'ZipArchive' ) || 
                            ( ! $wp_filesystem->is_writable( WP_SIMPLE_BACKUP_DIR ) && ! wp_mkdir_p( WP_SIMPLE_BACKUP_DIR ) );
                echo $is_disabled ? 'disabled' : ''; 
                ?>
            >
                <span class="dashicons dashicons-download" style="line-height: 1.6;"></span> 
                Start Full Backup Now
            </button>
        </p>

    <?php endif;
    
    return ob_get_clean();
}

/**
 * Returns the HTML for the backup file list.
 * @return string HTML
 */
function wsgb_get_list_backups_html() {
    ob_start();
    
    $backup_files = glob( WP_SIMPLE_BACKUP_DIR . '*.zip' );
    if ( empty( $backup_files ) ) {
        echo '<p>No backups found yet. Click the button above to create your first backup.</p>';
        return ob_get_clean();
    }
    
    // Sort files by modified time, newest first
    usort( $backup_files, function ( $a, $b ) {
        return filemtime( $b ) - filemtime( $a );
    });

    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>File Name</th><th>Size</th><th>Date</th><th>Action</th></tr></thead>';
    echo '<tbody>';

    foreach ( $backup_files as $file_path ) {
        $filename = basename( $file_path );
        $size     = size_format( filesize( $file_path ), 2 );
        $timestamp = filemtime( $file_path );
        $date     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
        $url      = content_url( 'backups/' . $filename );
        
        // Setup delete action link
        $delete_url = wp_nonce_url( admin_url( 'tools.php?page=wsgb_backup_page&wsgb_delete=' . urlencode( $filename ) ), 'wsgb_delete_file_' . $filename, 'wsgb_delete_nonce' );

        echo '<tr>';
        echo '<td>' . esc_html( $filename ) . '</td>';
        echo '<td>' . esc_html( $size ) . '</td>';
        echo '<td>' . esc_html( $date ) . '</td>';
        echo '<td>';
        echo '<a href="' . esc_url( $url ) . '" class="button">Download</a> | ';
        echo '<a href="' . esc_url( $delete_url ) . '" class="button-secondary delete-button" onclick="return confirm(\'Are you sure you want to delete this backup file?\');">Delete</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    
    return ob_get_clean();
}

// --- AJAX Handler ---

/**
 * Handles all AJAX requests for the backup page.
 */
function wsgb_backup_ajax_handler() {
    // Check nonce
    check_ajax_referer( 'wsgb_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied.' ] );
    }

    $sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( $_POST['sub_action'] ) : '';

    switch ( $sub_action ) {
        case 'start_backup':
            if ( get_transient( 'wsgb_backup_running' ) ) {
                wp_send_json_error( [ 'message' => 'A backup is already in progress.' ] );
            } else {
                wp_schedule_single_event( time(), 'wsgb_run_backup_cron' );
                set_transient( 'wsgb_backup_running', 'true', HOUR_IN_SECONDS );
                wp_send_json_success( [ 'message' => 'Backup scheduled.' ] );
            }
            break;

        case 'cancel_backup':
            wp_clear_scheduled_hook( 'wsgb_run_backup_cron' );
            delete_transient( 'wsgb_backup_running' );
            wp_send_json_success( [ 'message' => 'Backup cancelled.' ] );
            break;

        case 'get_status':
            $status = get_transient( 'wsgb_backup_running' ) ? 'running' : 'idle';
            wp_send_json_success( [
                'backup_status' => $status,
                'status_html'   => wsgb_get_backup_status_html(),
                'controls_html' => wsgb_get_backup_controls_html(),
                'list_html'     => wsgb_get_list_backups_html(),
            ]);
            break;

        default:
            wp_send_json_error( [ 'message' => 'Invalid action.' ] );
            break;
    }

    wp_die();
}

// --- File Deletion Handler ---

/**
 * Handles the deletion of a specific backup file.
 */
function wsgb_handle_delete_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_GET['wsgb_delete'] ) && isset( $_GET['wsgb_delete_nonce'] ) ) {
        // Unslash before sanitizing
        $filename = wp_unslash( $_GET['wsgb_delete'] );
        $filename = sanitize_file_name( $filename );
        
        // Unslash nonce as well
        $nonce = wp_unslash( $_GET['wsgb_delete_nonce'] );

        if ( wp_verify_nonce( $nonce, 'wsgb_delete_file_' . $filename ) ) {
            $file_path = WP_SIMPLE_BACKUP_DIR . $filename;

            if ( file_exists( $file_path ) && strpos( realpath( $file_path ), realpath( WP_SIMPLE_BACKUP_DIR ) ) === 0 ) {
                // Ensure file is inside the designated backup directory
                if ( wp_delete_file( $file_path ) ) {
                    add_settings_error( 'wsgb_backup_messages', 'wsgb_success', 'Backup file deleted successfully.', 'success' );
                } else {
                    add_settings_error( 'wsgb_backup_messages', 'wsgb_error', 'Failed to delete backup file.', 'error' );
                }
            } else {
                add_settings_error( 'wsgb_backup_messages', 'wsgb_error', 'Invalid file path or file not found.', 'error' );
            }
        } else {
            add_settings_error( 'wsgb_backup_messages', 'wsgb_error', 'Security check failed.', 'error' );
        }

        set_transient( 'wsgb_backup_messages', get_settings_errors( 'wsgb_backup_messages' ), 30 );
        wp_safe_redirect( remove_query_arg( array( 'wsgb_delete', 'wsgb_delete_nonce' ), admin_url( 'tools.php?page=wsgb_backup_page' ) ) );
        exit;
    }
}

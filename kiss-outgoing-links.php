<?php
/*
Plugin Name: KISS Outgoing Links Scanner
Description: Scans all posts (including custom post types) for outgoing HTTP/HTTPS links and lists them in an admin‑side table you can copy to the clipboard.
Version: 1.0.0
Author: KISS Plugins | Neochrome, Inc.
License: GPL‑2.0‑or‑later
Text Domain: ols
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Load plugin translations.
 */
function ols_load_textdomain() {
    load_plugin_textdomain( 'ols', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'ols_load_textdomain' );

/**
 * Register the admin menu entry.
 */
function ols_register_admin_menu() {
    add_submenu_page(
        'options-general.php',                 // Parent slug (Settings → …)
        __( 'Outgoing Links Scanner', 'ols' ), // Page title
        __( 'Outgoing Links', 'ols' ),         // Menu title
        'manage_options',                      // Capability
        'ols-outgoing-links',                  // Menu slug
        'ols_render_admin_page'                // Callback
    );
}
add_action( 'admin_menu', 'ols_register_admin_menu' );

/**
 * Enqueue assets only on our settings page.
 */
function ols_enqueue_admin_assets( $hook ) {
    if ( $hook !== 'settings_page_ols-outgoing-links' ) {
        return;
    }

    // Our tiny JS lives inline – no extra files needed for now.
    wp_add_inline_script( 'jquery-core', "\n(function($){\n    $('#ols-copy-btn').on('click', function(){\n        var table = document.getElementById('ols-results');\n        if (!table) { return; }\n        var txt = '';\n        for (var i = 0; i < table.rows.length; i++) {\n            var cells = table.rows[i].cells;\n            var row = [];\n            for (var j = 0; j < cells.length; j++) {\n                row.push(cells[j].innerText.replace(/\n|\r|\t/g, ' '));\n            }\n            txt += row.join('\t') + '\n';\n        }\n        navigator.clipboard.writeText(txt).then(function(){\n            alert('Table copied to clipboard!');\n        });\n    });\n})(jQuery);\n" );
}
add_action( 'admin_enqueue_scripts', 'ols_enqueue_admin_assets' );

/**
 * Render the plugin's settings page.
 */
function ols_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle the scan request.
    if ( isset( $_POST['ols_scan'] ) && check_admin_referer( 'ols_scan_action', 'ols_scan_nonce' ) ) {
        $results = ols_perform_scan();
        update_option( 'ols_scan_results', $results );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan completed successfully.', 'ols' ) . '</p></div>';
    }

    $results = get_option( 'ols_scan_results', array() );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Outgoing Links Scanner', 'ols' ); ?></h1>
        <p><?php esc_html_e( 'Click the button below to scan all public post types for outgoing links. Depending on the size of your site, this can take a while.', 'ols' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'ols_scan_action', 'ols_scan_nonce' ); ?>
            <input type="submit" name="ols_scan" class="button button-primary" value="<?php esc_attr_e( 'Scan and Generate Table (This may take a while)', 'ols' ); ?>" />
        </form>

        <?php if ( ! empty( $results ) ) : ?>
            <h2 style="margin-top:2rem;"><?php esc_html_e( 'Scan Results', 'ols' ); ?></h2>
            <button id="ols-copy-btn" class="button"><?php esc_html_e( 'Copy to Clipboard', 'ols' ); ?></button>
            <table class="widefat fixed striped" id="ols-results" style="margin-top:1rem;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Outgoing URL', 'ols' ); ?></th>
                        <th><?php esc_html_e( 'Text', 'ols' ); ?></th>
                        <th><?php esc_html_e( 'Approx. location in post %', 'ols' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $results as $row ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['url'] ); ?></a></td>
                        <td><?php echo esc_html( $row['text'] ); ?></td>
                        <td><?php echo esc_html( $row['percent'] ); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Perform the heavy‑lifting: scan posts and extract links.
 *
 * @return array[] {\n *     @type string $url     The outbound URL.\n *     @type string $text    Anchor text.\n *     @type int    $percent Position of the link within the post, rounded.\n * }
 */
function ols_perform_scan() {
    global $wpdb;

    // Fetch IDs of all public post types.
    $post_types = get_post_types( array( 'public' => true ), 'names' );

    // Pull post_content only to keep memory modest.
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $query        = $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)", $post_types );
    $posts        = $wpdb->get_results( $query );

    $site_url = home_url();
    $results  = array();

    foreach ( $posts as $post ) {
        // Short‑circuit if content is empty.
        if ( empty( $post->post_content ) ) {
            continue;
        }

        // Use regex rather than DOMDocument for speed inside WP admin.
        if ( preg_match_all( '/<a [^>]*href=[\"\']([^\"\']+)[\"\'][^>]*>(.*?)<\/a>/is', $post->post_content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $href = trim( $match[1] );

                // Only http/https links, ignore anchors/mailto/etc.
                if ( ! preg_match( '#^https?://#i', $href ) ) {
                    continue;
                }

                // Strip same‑site links (optional – comment this line if you also want internal links).
                if ( strpos( $href, $site_url ) === 0 ) {
                    continue;
                }

                $anchor_text = wp_strip_all_tags( $match[2] );

                // Calculate approx. position.
                $pos      = strpos( $post->post_content, $match[0] );
                $len      = strlen( $post->post_content );
                $percent  = $len ? round( ( $pos / $len ) * 100 ) : 0;

                $results[] = array(
                    'url'     => $href,
                    'text'    => $anchor_text,
                    'percent' => $percent,
                );
            }
        }
    }

    return $results;
}
?>

<?php
/*
Plugin Name: Top Pages Tracker
Description: A plugin to track page views independently and display the top X most visited pages using a shortcode.
Version: 2.1
Author: Leif Sanden
*/

// Sicherstellen, dass das Plugin nur in WordPress geladen wird
if (!defined('ABSPATH')) {
    exit;
}

// Datenbanktabelle bei Aktivierung erstellen
register_activation_hook(__FILE__, 'top_pages_tracker_create_table');
function top_pages_tracker_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'top_pages_tracker';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT(20) UNSIGNED NOT NULL,
        page_url VARCHAR(255) NOT NULL,
        visit_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
        last_visited DATETIME NOT NULL,
        PRIMARY KEY (id),
        INDEX (page_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $result = dbDelta($sql);

    // Debugging: Prüfen, ob die Tabelle erfolgreich erstellt wurde
    if (!empty($wpdb->last_error)) {
        error_log("Fehler beim Erstellen der Tabelle $table_name: " . $wpdb->last_error);
    } else {
        error_log("Tabelle $table_name erfolgreich erstellt oder bereits vorhanden.");
    }
}

// Seitenaufrufe tracken
add_action('wp', 'top_pages_tracker_track_visit');
function top_pages_tracker_track_visit() {
    // Nur auf Single-Seiten tracken (keine Admin-Seiten, Feeds, etc.)
    if (is_admin() || is_feed() || is_404() || is_user_logged_in()) {
        return;
    }

    global $wpdb, $post;
    $table_name = $wpdb->prefix . 'top_pages_tracker';

    // Aktuelle Seite
    $page_id = get_queried_object_id();
    if (!$page_id) {
        return; // Keine gültige Seite
    }

    $page_url = get_permalink($page_id);
    $current_time = current_time('mysql');

    // Prüfen, ob die Tabelle existiert
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        error_log("Tabelle $table_name existiert nicht. Daten können nicht gespeichert werden.");
        return;
    }

    // Prüfen, ob die Seite bereits in der Datenbank existiert
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table_name WHERE page_id = %d",
            $page_id
        )
    );

    if ($exists) {
        // Eintrag aktualisieren: Zähler erhöhen und Zeit aktualisieren
        $wpdb->update(
            $table_name,
            array(
                'visit_count' => $wpdb->get_var("SELECT visit_count FROM $table_name WHERE page_id = $page_id") + 1,
                'last_visited' => $current_time,
            ),
            array('page_id' => $page_id),
            array('%d', '%s'),
            array('%d')
        );
    } else {
        // Neuen Eintrag hinzufügen
        $wpdb->insert(
            $table_name,
            array(
                'page_id' => $page_id,
                'page_url' => $page_url,
                'visit_count' => 1,
                'last_visited' => $current_time,
            ),
            array('%d', '%s', '%d', '%s')
        );
    }
}

// Shortcode für die Top-X-Seiten [top_pages_tracker]
function top_pages_tracker_shortcode($atts) {
    global $wpdb;

    // Shortcode-Attribute
    $atts = shortcode_atts(
        array(
            'limit' => 10, // Standard: Top 10
        ),
        $atts,
        'top_pages_tracker'
    );

    $limit = intval($atts['limit']);
    $table_name = $wpdb->prefix . 'top_pages_tracker';

    // Prüfen, ob die Tabelle existiert
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return '<p>Die Datenbanktabelle wurde nicht erstellt. Bitte aktiviere das Plugin erneut oder überprüfe die Server-Berechtigungen.</p>';
    }

    // Abfrage der Top-Seiten
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT page_id, page_url, visit_count 
            FROM $table_name 
            ORDER BY visit_count DESC 
            LIMIT %d",
            $limit
        )
    );

    if (!$results) {
        return '<p>Keine Daten verfügbar. Bitte warte, bis Seitenaufrufe erfasst wurden.</p>';
    }

    // HTML-Ausgabe
    $output = '<div class="top-pages-tracker"><h3>Top ' . $limit . ' meist aufgerufene Seiten:</h3><ul>';
    foreach ($results as $row) {
        $page_id = $row->page_id;
        $page_url = $row->page_url;
        $page_title = get_the_title($page_id) ?: $page_url;
        $page_link = get_permalink($page_id) ?: $page_url;
        $output .= '<li><a href="' . esc_url($page_link) . '">' . esc_html($page_title) . '</a> - ' . esc_html($row->visit_count) . ' Aufrufe</li>';
    }
    $output .= '</ul></div>';

    // Styling (orientiert am Screenshot: blaue Überschrift und Links)
    $output .= '<style>
        .top-pages-tracker {
            max-width: 600px;
            margin: 20px 0;
            font-family: Arial, sans-serif;
        }
        .top-pages-tracker h3 {
            color: #00A1E0;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .top-pages-tracker ul {
            list-style-type: none;
            padding: 0;
        }
        .top-pages-tracker li {
            margin-bottom: 5px;
            color: #000;
            font-size: 16px;
        }
        .top-pages-tracker a {
            color: #00A1E0;
            text-decoration: none;
        }
        .top-pages-tracker a:hover {
            text-decoration: underline;
        }
    </style>';

    return $output;
}
add_shortcode('top_pages_tracker', 'top_pages_tracker_shortcode');

// Datenbanktabelle bei Deaktivierung optional löschen (falls gewünscht)
register_deactivation_hook(__FILE__, 'top_pages_tracker_drop_table');
function top_pages_tracker_drop_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'top_pages_tracker';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

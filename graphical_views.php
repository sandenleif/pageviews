<?php
/*
Plugin Name: Page Views Chart
Description: A plugin to display a chart of page views for a selected page and time range using data from Top Pages Tracker.
Version: 1.0
Author: Leif Sanden
*/

// Sicherstellen, dass das Plugin nur in WordPress geladen wird
if (!defined('ABSPATH')) {
    exit;
}

// Shortcode für das Diagramm [page_views_chart]
function page_views_chart_shortcode() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'top_pages_tracker';

    // Prüfen, ob die Tabelle existiert
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return '<p>Die Datenbanktabelle wurde nicht gefunden. Bitte stelle sicher, dass das Plugin "Top Pages Tracker" aktiviert ist.</p>';
    }

    // Alle Seiten aus der Tabelle abrufen (für das Dropdown)
    $pages = $wpdb->get_results("SELECT page_id, page_url FROM $table_name GROUP BY page_id");

    // Standardwerte für Formular
    $selected_page_id = isset($_GET['page_id']) ? intval($_GET['page_id']) : (isset($pages[0]) ? $pages[0]->page_id : 0);
    $selected_range = isset($_GET['range']) ? sanitize_text_field($_GET['range']) : '7days';
    $chart_data = array();
    $labels = array();
    $data = array();

    if ($selected_page_id && $selected_range) {
        // Zeitraum berechnen
        $end_date = current_time('mysql');
        switch ($selected_range) {
            case '7days':
                $start_date = date('Y-m-d H:i:s', strtotime('-7 days'));
                $interval = 'DAY';
                $range_days = 7;
                break;
            case '30days':
                $start_date = date('Y-m-d H:i:s', strtotime('-30 days'));
                $interval = 'DAY';
                $range_days = 30;
                break;
            case '90days':
                $start_date = date('Y-m-d H:i:s', strtotime('-90 days'));
                $interval = 'WEEK';
                $range_days = 90;
                break;
            default:
                $start_date = date('Y-m-d H:i:s', strtotime('-7 days'));
                $interval = 'DAY';
                $range_days = 7;
        }

        // Daten für den ausgewählten Zeitraum abrufen
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(last_visited) as visit_date, visit_count 
                FROM $table_name 
                WHERE page_id = %d AND last_visited BETWEEN %s AND %s",
                $selected_page_id,
                $start_date,
                $end_date
            )
        );

        // Daten für das Diagramm vorbereiten
        $date_counts = array();
        foreach ($results as $row) {
            $date = date('Y-m-d', strtotime($row->visit_date));
            $date_counts[$date] = (int)$row->visit_count;
        }

        // Labels und Daten für den Zeitraum generieren
        for ($i = 0; $i < $range_days; $i++) {
            $current_date = date('Y-m-d', strtotime("$start_date + $i days"));
            $labels[] = date('d.m.Y', strtotime($current_date));
            $data[] = isset($date_counts[$current_date]) ? $date_counts[$current_date] : 0;
        }

        $chart_data = [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    // Formular und Diagramm HTML
    $output = '<div class="page-views-chart">';
    $output .= '<h3>Seitenaufrufe anzeigen</h3>';

    // Formular
    $output .= '<form method="get" action="">';
    $output .= '<label for="page_id">Seite auswählen:</label>';
    $output .= '<select name="page_id" id="page_id">';
    foreach ($pages as $page) {
        $page_title = get_the_title($page->page_id) ?: $page->page_url;
        $selected = ($page->page_id == $selected_page_id) ? 'selected' : '';
        $output .= '<option value="' . esc_attr($page->page_id) . '" ' . $selected . '>' . esc_html($page_title) . '</option>';
    }
    $output .= '</select>';

    $output .= '<label for="range">Zeitraum:</label>';
    $output .= '<select name="range" id="range">';
    $output .= '<option value="7days" ' . ($selected_range == '7days' ? 'selected' : '') . '>Letzte 7 Tage</option>';
    $output .= '<option value="30days" ' . ($selected_range == '30days' ? 'selected' : '') . '>Letzte 30 Tage</option>';
    $output .= '<option value="90days" ' . ($selected_range == '90days' ? 'selected' : '') . '>Letzte 90 Tage</option>';
    $output .= '</select>';

    $output .= '<button type="submit">Anzeigen</button>';
    $output .= '</form>';

    // Diagramm
    if (!empty($chart_data)) {
        $output .= '<canvas id="pageViewsChart" width="400" height="200"></canvas>';
        $output .= '<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>';
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var ctx = document.getElementById("pageViewsChart").getContext("2d");
                new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: ' . json_encode($chart_data['labels']) . ',
                        datasets: [{
                            label: "Seitenaufrufe",
                            data: ' . json_encode($chart_data['data']) . ',
                            borderColor: "#00A1E0",
                            backgroundColor: "rgba(0, 161, 224, 0.2)",
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: "Aufrufe"
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: "Datum"
                                }
                            }
                        }
                    }
                });
            });
        </script>';
    }

    $output .= '</div>';

    // Styling (orientiert am Screenshot: blaue Akzente)
    $output .= '<style>
        .page-views-chart {
            max-width: 600px;
            margin: 20px 0;
            font-family: Arial, sans-serif;
        }
        .page-views-chart h3 {
            color: #00A1E0;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .page-views-chart label {
            display: block;
            margin: 10px 0 5px;
            color: #000;
            font-weight: bold;
        }
        .page-views-chart select {
            width: 100%;
            padding: 5px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .page-views-chart button {
            background-color: #00A1E0;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .page-views-chart button:hover {
            background-color: #0081B0;
        }
        .page-views-chart canvas {
            margin-top: 20px;
        }
    </style>';

    return $output;
}
add_shortcode('page_views_chart', 'page_views_chart_shortcode');

<?php
function twentytwentyfive_child_enqueue_styles()
{
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
}
add_action('wp_enqueue_scripts', 'twentytwentyfive_child_enqueue_styles');



// =====================================
// Group Leader Report Download
// =====================================

/**
 * Shortcode: [ld_group_report]
 * 
 * This shortcode generates a form for Group Leaders to download group progress reports in CSV format.
 */
add_shortcode('ld_group_report', 'learndash_generate_group_report');

function learndash_generate_group_report()
{
    if (!is_user_logged_in()) {
        return '<p>Please log in to access reports.</p>';
    }

    $user_id = get_current_user_id();

    // Check if the logged-in user is a Group Leader
    if (!user_can($user_id, 'group_leader')) {
        return '<p>You do not have permission to view this report.</p>';
    }

    // Get groups assigned to the current group leader
    $groups = learndash_get_administrators_group_ids($user_id);

    if (empty($groups)) {
        return '<p>You are not assigned to any groups.</p>';
    }

    ob_start();

    // If CSV download is requested, call the function and exit to prevent HTML output
    if (isset($_POST['download_csv']) && isset($_POST['group_id'])) {
        learndash_download_csv($_POST['group_id']);
        exit(); // Stop further execution to prevent HTML from being included in CSV
    }

?>
    <form method="post">
        <label for="group_id">Select Group:</label>
        <select name="group_id" id="group_id">
            <?php foreach ($groups as $group_id) : ?>
                <option value="<?php echo esc_attr($group_id); ?>"><?php echo esc_html(get_the_title($group_id)); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="submit" name="download_csv" value="Download Report">
    </form>
<?php

    return ob_get_clean();
}


/**
 * Generate and Download CSV Report for Selected Group
 * 
 * @param int $group_id The ID of the group for which the report is generated.
 */
function learndash_download_csv($group_id)
{
    global $wpdb;

    $group_users = learndash_get_groups_users($group_id);

    if (empty($group_users)) {
        die('No users found in this group.');
    }

    $csv_data = [];
    $csv_data[] = ['User Name', 'Email', 'Course Name', 'Completion %', 'Quiz Scores'];

    foreach ($group_users as $user) {
        $user_id = $user->ID;
        $user_name = $user->display_name;
        $user_email = $user->user_email;
        $courses = learndash_user_get_enrolled_courses($user_id);

        foreach ($courses as $course_id) {
            $course_title = get_the_title($course_id);
            $completion = learndash_course_progress(array('user_id' => $user_id, 'course_id' => $course_id));
            $completion_percentage = isset($completion['percentage']) ? $completion['percentage'] : '0';

            // Fetch Quiz Scores
            $quiz_attempts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT quiz_title, percentage FROM {$wpdb->prefix}learndash_user_activity 
                    WHERE user_id = %d AND course_id = %d AND activity_type = %s",
                    $user_id,
                    $course_id,
                    'quiz'
                )
            );

            $quiz_score_text = [];
            if (!empty($quiz_attempts)) {
                foreach ($quiz_attempts as $quiz) {
                    $quiz_score_text[] = $quiz->quiz_title . ' (' . $quiz->percentage . '%)';
                }
            }
            $quiz_score_text = implode("; ", $quiz_score_text);

            // Add to CSV data
            $csv_data[] = [$user_name, $user_email, $course_title, $completion_percentage . '%', $quiz_score_text];
        }
    }

    // Set headers for CSV file download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="group_report.csv"');
    $output = fopen('php://output', 'w');
    foreach ($csv_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Enable Shortcodes in Page Content
add_filter('the_content', 'do_shortcode');

// =====================================
// Restrict Dashboard Access
// =====================================

/**
 * Restrict Access to Dashboard Page
 * 
 * Redirects non-logged-in users from "My Dashboard" to the login page.
 */
add_action('template_redirect', 'restrict_dashboard_page');
function restrict_dashboard_page()
{
    if (is_page('my-dashboard') && !is_user_logged_in()) {
        wp_redirect(wp_login_url());
        exit;
    }
}



// =====================================
// Group Leader Report Shortcode
// =====================================

/**
 * Shortcode: [group_leader_report]
 * 
 * Displays a table containing users in the group along with their course progress.
 * Allows exporting the data to CSV.
 */
add_shortcode('group_leader_report', 'group_leader_report_shortcode');

function group_leader_report_shortcode()
{
    if (!current_user_can('group_leader')) {
        return '<p>You do not have permission to view this report.</p>';
    }

    global $wpdb, $current_user;
    get_currentuserinfo();

    $group_ids = learndash_get_administrators_group_ids($current_user->ID);
    if (empty($group_ids)) {
        return '<p>No groups found.</p>';
    }

    $group_ids_str = implode(',', $group_ids);
    $results = $wpdb->get_results("
        SELECT u.ID as user_id, u.display_name, u.user_email, p.post_title as course_name, m.meta_value as completion
        FROM {$wpdb->users} u
        JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key LIKE 'learndash_group_users_%'
        JOIN {$wpdb->posts} p ON p.post_type = 'sfwd-courses'
        LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key LIKE 'course_%_access'
        WHERE um.meta_value IN ($group_ids_str)
        ORDER BY u.display_name ASC
    ");

    // Export to CSV
    if (isset($_POST['export_csv'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="group_leader_report.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['User Name', 'Email', 'Course', 'Completion (%)']);

        foreach ($results as $row) {
            fputcsv($output, [$row->display_name, $row->user_email, $row->course_name, round($row->completion * 100, 2) . '%']);
        }

        fclose($output);
        exit;
    }

    // Display Data
    $output = "<form method='post'><button type='submit' name='export_csv'>Download CSV</button></form>";
    $output .= "<table border='1' cellpadding='5' cellspacing='0'>";
    $output .= "<tr><th>User Name</th><th>Email</th><th>Course</th><th>Completion (%)</th></tr>";

    foreach ($results as $row) {
        $completion = $row->completion ? round($row->completion * 100, 2) : '0%';
        $output .= "<tr>
            <td>{$row->display_name}</td>
            <td>{$row->user_email}</td>
            <td>{$row->course_name}</td>
            <td>{$completion}</td>
        </tr>";
    }

    $output .= "</table>";

    return $output;
}


// =====================================
// Restrict Group Leader Dashboard Page
// =====================================
/**
 * Restrict Access to Group Leader Dashboard
 * 
 * Redirects non-group-leaders trying to access "Group Leader Dashboard".
 */
function restrict_group_leader_page()
{
    if (is_page('group-leader-dashboard') && !current_user_can('group_leader')) {
        wp_redirect(home_url());
        exit;
    }
}
add_action('template_redirect', 'restrict_group_leader_page');

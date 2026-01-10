<?php
/**
 * Calendar Helper Functions
 * This file contains functions to generate and display the complaint calendar
 * for various dashboards in the TSU ICT Complaint system.
 */

/**
 * Generates the HTML for the complaint calendar
 * 
 * @param mysqli $conn Database connection
 * @param int $role_id User role ID
 * @param string $where_clause Additional WHERE conditions for filtering complaints
 * @return string HTML for the calendar
 */
function generateComplaintCalendar($conn, $role_id, $where_clause = '') {
    // Start building HTML
    $html = <<<HTML
    <div id="complaint-calendar" style="height: 400px;"></div>
    
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.css" rel="stylesheet">
    <!-- FullCalendar JS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    
    <style>
    .complaint-count {
        display: inline-block;
        margin-left: 6px;
        font-size: 13px;
        color: #fff;
        font-weight: bold;
        background-color: #dc3545;
        border-radius: 12px;
        min-width: 22px;
        height: 22px;
        line-height: 22px;
        padding: 0 7px;
        vertical-align: middle;
        box-shadow: 0 0 5px rgba(220,53,69,0.15);
        transition: background 0.2s;
    }
    .past-date .complaint-count {
        background-color: #6c757d;
    }
    .fc-daygrid-day-top {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        position: relative;
        padding-left: 6px;
    }
    .fc-daygrid-day-number {
        font-weight: 500;
        color: #333;
        background: #f8f9fa;
        border-radius: 4px;
        padding: 2px 6px;
        transition: background 0.2s;
    }
    .fc-daygrid-day:hover .fc-daygrid-day-number {
        background: #e9ecef;
    }
    .fc-daygrid-day {
        cursor: pointer;
        border-radius: 6px;
        transition: box-shadow 0.2s;
    }
    .fc-daygrid-day:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        background: #f6f8fa;
    }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Get complaint counts by date
        var complaintCounts = {
HTML;
    
    // Base conditions for role-specific filtering
    $base_conditions = "";
    
    // Add role-specific conditions
    if ($role_id == 1) { // Admin - all complaints
        $base_conditions .= $where_clause;
    } elseif ($role_id == 2) { // Staff - regular complaints
        $base_conditions .= " AND is_i4cus = 0 AND is_payment_related = 0" . $where_clause;
    } elseif ($role_id == 3) { // Director - all complaints
        $base_conditions .= $where_clause;
    } elseif ($role_id == 4) { // DVC - all complaints
        $base_conditions .= $where_clause;
    } elseif ($role_id == 5) { // i4Cus Staff - i4cus complaints
        $base_conditions .= " AND is_i4cus = 1" . $where_clause;
    } elseif ($role_id == 6) { // Payment Admin - payment complaints
        $base_conditions .= " AND is_payment_related = 1" . $where_clause;
    } elseif ($role_id == 7) { // Department - their own complaints
        // Extract user_id from where_clause if it contains c.lodged_by
        if (strpos($where_clause, 'c.lodged_by') !== false) {
            // Replace c.lodged_by with lodged_by for the main query
            $department_where = str_replace('c.lodged_by', 'lodged_by', $where_clause);
            $base_conditions .= $department_where;
        } else {
            $base_conditions .= $where_clause;
        }
    }
    
    // SQL to get total and untreated complaint counts by date
    $sql_counts = "SELECT 
                    DATE(created_at) as complaint_date, 
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status != 'Treated' THEN 1 ELSE 0 END) as untreated_count
                   FROM complaints 
                   WHERE status != 'Treated' $base_conditions
                   GROUP BY DATE(created_at)";
    
    $result_counts = mysqli_query($conn, $sql_counts);
    $complaint_counts = [];
    $past_dates = [];
    $today = date('Y-m-d');
    
    if ($result_counts) {
        while ($row = mysqli_fetch_assoc($result_counts)) {
            // Only show untreated complaints count on the calendar
            $untreated_count = intval($row['untreated_count']);
            $complaint_counts[$row['complaint_date']] = $untreated_count;
            
            // Check if this is a past date
            if ($row['complaint_date'] < $today) {
                $past_dates[] = $row['complaint_date'];
            }
            
            // Only add to JavaScript if there are untreated complaints
            if ($untreated_count > 0) {
                $html .= "            '{$row['complaint_date']}': {$untreated_count},\n";
            }
        }
    }
    
    // Add past dates to JavaScript
    $html .= "        };\n";
    $html .= "        var pastDates = [";
    foreach ($past_dates as $date) {
        $html .= "'$date',";
    }
    $html .= "];\n";
    $html .= "        var today = '$today';\n";
    
    $html .= <<<HTML
        
        // Initialize calendar
        var calendarEl = document.getElementById('complaint-calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 'auto',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth'
            },
            dayCellDidMount: function(info) {
                // Use local time instead of UTC to match server date
                var dateObj = info.date;
                var dateStr = dateObj.getFullYear() + '-' + 
                    String(dateObj.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(dateObj.getDate()).padStart(2, '0');
                var dayNumber = info.el.querySelector('.fc-daygrid-day-number');
                // Remove any previous count
                var oldCount = info.el.querySelector('.complaint-count');
                if (oldCount) oldCount.remove();
                if (complaintCounts[dateStr] && complaintCounts[dateStr] > 0) {
                    var isPastDate = pastDates.includes(dateStr);
                    // Create the count badge
                    var countElement = document.createElement('span');
                    countElement.className = 'complaint-count';
                    countElement.textContent = complaintCounts[dateStr];
                    if (isPastDate) info.el.classList.add('past-date');
                    // Place the badge just after the day number
                    if (dayNumber) dayNumber.after(countElement);
                    // Tooltip
                    var tooltipText = complaintCounts[dateStr] + ' Untreated Complaint' + (complaintCounts[dateStr] > 1 ? 's' : '');
                    if (isPastDate) tooltipText += ' (Past Date)';
                    new bootstrap.Tooltip(countElement, {
                        title: tooltipText,
                        placement: 'top',
                        trigger: 'hover',
                        container: 'body'
                    });
                }
                // Make the day clickable (even if no complaints)
                info.el.style.cursor = 'pointer';
                info.el.addEventListener('click', function() {
                    window.location.href = 'view_complaints_by_date.php?date=' + dateStr;
                });
            }
        });
        calendar.render();
    });
    </script>
HTML;
    
    return $html;
}

/**
 * Adds date filtering to an existing WHERE clause
 * 
 * @param string $where_clause Existing WHERE clause
 * @param string $filter_date Date to filter by (YYYY-MM-DD)
 * @param bool $include_past Whether to include past dates in the main view (default: true)
 * @return string Updated WHERE clause
 */
function addDateFilter($where_clause, $filter_date, $include_past = true) {
    if (!empty($filter_date)) {
        $escaped_date = mysqli_real_escape_string($GLOBALS['conn'], $filter_date);
        
        // Check if this is a past date
        $today = date('Y-m-d');
        $is_past_date = $filter_date < $today;
        
        if (empty($where_clause)) {
            $where_clause = " WHERE DATE(created_at) = '$escaped_date'";
        } else {
            $where_clause .= " AND DATE(created_at) = '$escaped_date'";
        }
        
        // If it's a past date and we don't want to include past dates in the main view,
        // add a condition to exclude them (this will be overridden by the specific date filter)
        if ($is_past_date && !$include_past) {
            // This is a special flag to indicate we're viewing a past date
            // We'll use this in the UI to show a message
            $_GET['viewing_past_date'] = true;
        }
    } else if (!$include_past) {
        // If we're not including past dates and no specific date filter is applied,
        // add a condition to exclude past dates
        $today = date('Y-m-d');
        if (empty($where_clause)) {
            $where_clause = " WHERE DATE(created_at) >= '$today'";
        } else {
            $where_clause .= " AND DATE(created_at) >= '$today'";
        }
    }
    
    return $where_clause;
}

// Set server timezone for consistency
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Africa/Lagos'); // Set to your local timezone
}
if (isset($conn)) {
    @mysqli_query($conn, "SET time_zone = '+01:00'"); // Set to your timezone offset
}
?>
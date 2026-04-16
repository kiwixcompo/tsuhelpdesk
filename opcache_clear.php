<?php
// opcache_clear.php

echo "<h2>Cache Clearing Utility</h2>";

if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "<p style='color: green;'>✅ OPcache has been successfully reset.</p>";
    } else {
        echo "<p style='color: red;'>❌ OPcache reset failed.</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ OPcache is not enabled or not installed on this server.</p>";
}

if (function_exists('apcu_clear_cache')) {
    if (apcu_clear_cache()) {
        echo "<p style='color: green;'>✅ APCu cache has been successfully cleared.</p>";
    } else {
        echo "<p style='color: red;'>❌ APCu cache clear failed.</p>";
    }
}

echo "<p><a href='enhanced_student_complaints_report.php'>Go back to complaints report</a></p>";
?>

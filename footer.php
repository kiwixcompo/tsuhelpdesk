<?php
require_once "config.php";

// Fetch footer text from settings
$footer_text = "Copyright © " . date('Y') . " TSU ICT Complaint Desk. All rights reserved.";
$sql = "SELECT setting_value FROM settings WHERE setting_key = 'footer_text'";
$result = mysqli_query($conn, $sql);
if($result && $row = mysqli_fetch_assoc($result)){
    $footer_text = str_replace("{year}", date('Y'), $row['setting_value']);
}
?>

<footer class="footer mt-auto py-3 bg-light">
    <div class="container text-center">
        <span class="text-muted"><?php echo $footer_text; ?></span>
    </div>
</footer>

<style>
.footer {
    width: 100%;
    background-color: #f8f9fa;
    border-top: 1px solid #dee2e6;
    margin-top: 2rem;
}

/* Ensure footer stays at bottom for short content */
html, body {
    height: 100%;
}

.main-content {
    min-height: calc(100vh - 120px);
}
</style> 
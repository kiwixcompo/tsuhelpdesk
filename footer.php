<?php
require_once "config.php";

// Fetch footer text from settings session cache (preloaded in config.php)
$footer_text = "Copyright © " . date('Y') . " TSU ICT Help Desk. All rights reserved.";
if (isset($_SESSION['app_settings']['footer_text'])) {
    $footer_text = str_replace("{year}", date('Y'), $_SESSION['app_settings']['footer_text']);
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
<?php
if(!isset($_SESSION)) {
    session_start();
}
// Check if user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: index.php");
    exit;
}

// Include database connection and fetch app settings
require_once "config.php";

// Fetch app settings for header use
$app_name = 'TSU ICT Help Desk'; // Default value
$app_logo = '';
$app_favicon = '';

$sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('app_name', 'app_logo', 'app_favicon')";
$result = mysqli_query($conn, $sql);
if($result){
    while($row = mysqli_fetch_assoc($result)){
        switch($row['setting_key']) {
            case 'app_name':
                $app_name = $row['setting_value'] ?: 'TSU ICT Help Desk';
                break;
            case 'app_logo':
                $app_logo = $row['setting_value'];
                break;
            case 'app_favicon':
                $app_favicon = $row['setting_value'];
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_name); ?></title>
    
    <!-- Dynamic Favicon -->
    <?php if($app_favicon && file_exists($app_favicon)): ?>
        <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($app_favicon); ?>">
    <?php else: ?>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/auto-logout.js"></script>
    
    <style>
        .app-branding {
            display: flex;
            align-items: center;
        }
        .app-logo {
            height: 40px;
            margin-right: 10px;
            object-fit: contain;
        }
        .app-name {
            font-size: 1.25rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- App Branding Section (Logo + Name) - Add this wherever you want the logo to appear -->
    <div class="app-branding d-none" id="app-branding">
        <?php if($app_logo && file_exists($app_logo)): ?>
            <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="app-logo">
        <?php endif; ?>
        <span class="app-name"><?php echo htmlspecialchars($app_name); ?></span>
    </div>
    
    <script>
        // JavaScript function to show app branding anywhere on the page
        function showAppBranding(targetElementId) {
            var brandingElement = document.getElementById('app-branding');
            var targetElement = document.getElementById(targetElementId);
            
            if (brandingElement && targetElement) {
                brandingElement.classList.remove('d-none');
                var clonedBranding = brandingElement.cloneNode(true);
                clonedBranding.id = 'app-branding-' + targetElementId;
                clonedBranding.classList.remove('d-none');
                targetElement.appendChild(clonedBranding);
            }
        }
        
        // Alternative: Function to get app branding HTML
        function getAppBrandingHTML() {
            return `
                <div class="app-branding">
                    <?php if($app_logo && file_exists($app_logo)): ?>
                        <img src="<?php echo htmlspecialchars($app_logo); ?>" alt="Logo" class="app-logo">
                    <?php endif; ?>
                    <span class="app-name"><?php echo htmlspecialchars($app_name); ?></span>
                </div>
            `;
        }
    </script>
    
    <!-- Rest of the file remains unchanged -->
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="assets/js/session_timeout.js"></script>
</body>
</html>
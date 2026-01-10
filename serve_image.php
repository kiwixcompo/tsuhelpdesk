<?php
// Debug mode - set to false in production
$debug_mode = true;

// Error handling for debugging
if ($debug_mode) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Security check
session_start();
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(403);
    exit('Access denied');
}

// Get the image filename from the URL parameter
$image = isset($_GET['img']) ? $_GET['img'] : '';

// Validate the image filename
if (empty($image)) {
    http_response_code(400);
    exit('Invalid image');
}

// Sanitize the filename to prevent directory traversal
$image = basename($image);

// Ensure it's an image file
if (!preg_match('/\.(jpg|jpeg|png|gif)$/i', $image)) {
    http_response_code(400);
    exit('Invalid image type');
}

// Construct the full path
$image_path = __DIR__ . '/uploads/' . $image;

// Check if file exists and is readable
if (!file_exists($image_path) || !is_readable($image_path)) {
    http_response_code(404);
    
    // Serve a placeholder image
    $placeholder_svg = '<?xml version="1.0" encoding="UTF-8"?>
    <svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200">
        <rect width="300" height="200" fill="#f8f9fa" stroke="#dee2e6" stroke-width="2"/>
        <text x="150" y="100" text-anchor="middle" dy=".3em" fill="#6c757d" font-family="Arial" font-size="16">Image not found</text>
        <text x="150" y="120" text-anchor="middle" dy=".3em" fill="#6c757d" font-family="Arial" font-size="12">' . htmlspecialchars($image) . '</text>
    </svg>';
    
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-cache');
    echo $placeholder_svg;
    exit;
}

// Get image info
$image_info = getimagesize($image_path);
if (!$image_info) {
    http_response_code(500);
    exit('Invalid image file');
}

// Set the content type
$mime_type = $image_info['mime'];
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($image_path));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

// Serve the image
try {
    readfile($image_path);
} catch (Exception $e) {
    // Log the error
    error_log('Error serving image: ' . $e->getMessage());
    http_response_code(500);
    exit('Error serving image');
}
exit;
?>
<?php
session_start();
require_once 'config/db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Initialize is_super_admin
$is_super_admin = isset($_SESSION['is_super_admin']) ? $_SESSION['is_super_admin'] : 0;

// ... existing code ... 
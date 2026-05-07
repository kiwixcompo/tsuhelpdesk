<?php
// Local development overrides — loaded by config.php via env.php
// This file is gitignored and never deployed.
// It overrides .env values for local WAMP/XAMPP development.

$_ENV['DB_HOST']     = 'localhost';
$_ENV['DB_USERNAME'] = 'root';
$_ENV['DB_PASSWORD'] = '';
$_ENV['DB_DATABASE'] = 'tsu_ict_complaints';

// Local mail — disable SMTP, just log
$_ENV['MAIL_HOST']         = 'localhost';
$_ENV['MAIL_PORT']         = '1025'; // MailHog or similar local catcher
$_ENV['MAIL_USERNAME']     = '';
$_ENV['MAIL_PASSWORD']     = '';
$_ENV['MAIL_ENCRYPTION']   = 'tls';
$_ENV['MAIL_FROM_ADDRESS'] = 'dev@localhost';
$_ENV['MAIL_FROM_NAME']    = 'TSU ICT Help Desk (Dev)';

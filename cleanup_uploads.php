<?php
$dirs = [
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/profiles',
    __DIR__ . '/uploads/settings'
];
$now = time();
$days = 30;
foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file) && $now - filemtime($file) >= 60*60*24*$days) {
                unlink($file);
            }
        }
    }
}
?>
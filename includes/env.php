<?php
/**
 * Minimal .env loader — no external dependencies.
 * Reads KEY=VALUE pairs from the .env file in the project root.
 * Values wrapped in quotes have the quotes stripped.
 * Lines starting with # are comments and are ignored.
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // Split on first = only
        $eqPos = strpos($line, '=');
        if ($eqPos === false) continue;

        $key   = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        // Strip surrounding quotes (single or double)
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Set in $_ENV, $_SERVER, and putenv — same as popular loaders
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
}

// Load the .env from the project root (one level up from includes/)
loadEnv(__DIR__ . '/../.env');

/**
 * Helper: get an env value with an optional default.
 */
function env(string $key, $default = null)
{
    $val = $_ENV[$key] ?? getenv($key);
    return ($val !== false && $val !== null) ? $val : $default;
}

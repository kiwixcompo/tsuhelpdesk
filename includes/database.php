<?php
/**
 * includes/database.php
 *
 * Secure Database Abstraction Layer utilizing PDO with prepared statements.
 * Prevents SQL Injection (A03:2021) by forcing parameters to be separated from SQL logic.
 *
 * OWASP Reference:
 * A03:2021-Injection
 */

// Load dependency environment loader if env function is not loaded
if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
}

class Database {
    private static ?PDO $instance = null;

    /**
     * Get secure single PDO instance using credentials configured via .env variables.
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $host = env('DB_HOST', 'localhost');
            $db   = env('DB_DATABASE', 'tsuniver_tsu_ict_complaints');
            $user = env('DB_USERNAME', 'tsuniver_tsu_ict_complaints');
            $pass = env('DB_PASSWORD', '');
            $port = env('DB_PORT', '3306');
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=$charset";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Force exception throwing for database failures (avoids silent failures)
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retrieve standard associative arrays by default
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real native prepared statements instead of emulations to enforce parameter separation
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // Log the real connection failure details safely via global logger if present
                if (function_exists('app_log')) {
                    app_log('critical', 'Secure connection database failure', ['message' => $e->getMessage()]);
                }
                
                // Do not leak internal environment variables, hosts or schema names to client output
                http_response_code(500);
                die("A database error occurred. Please contact the administrator.");
            }
        }

        return self::$instance;
    }

    /**
     * Helper to quickly run a secure parameterized query.
     * Prevents string concatenation vulnerabilities.
     *
     * @param string $sql parameterized statement (e.g. "SELECT * FROM users WHERE email = :email")
     * @param array $params key-value bindings (e.g. [':email' => $emailInput])
     */
    public static function query(string $sql, array $params = []): PDOStatement {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

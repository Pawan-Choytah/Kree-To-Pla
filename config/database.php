<?php
/**
 * Kree To Pla - Database Configuration & Connection (PDO)
 * Security Control: Disables emulated prepares to guarantee real parameterized queries (SQLi Mitigation).
 */

declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'kree_to_pla_db');
define('DB_USER', 'root');
define('DB_PASS', ''); // Default XAMPP/Laragon password is empty

// Business Logic Constants
define('CUSTOM_DISH_BASE_PRICE', 45.00); 
define('DELIVERY_FEE', 50.00);           
define('ORDER_CUTOFF_MINUTES', 30);      // Customers can edit/cancel pending orders within 30 mins

function getDBConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // CRITICAL: Enforces native MySQL prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Security Control: Do not leak database credentials or stack traces to end users
            error_log('Database Connection Error: ' . $e->getMessage());
            http_response_code(500);
            die('<h2>Service Temporarily Unavailable</h2><p>Please ensure MySQL is running and <code>sql/kree_to_pla_db.sql</code> has been imported.</p>');
        }
    }

    return $pdo;
}

$pdo = getDBConnection();
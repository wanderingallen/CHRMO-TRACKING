<?php
/**
 * Database connection and helper functions
 */

require_once 'config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Ensure required tables exist for MySQL deployments
            $this->createMySQLTables();
        } catch (PDOException $e) {
            // No SQLite fallback: fail fast so issues are visible during testing
            error_log("MySQL connection failed: " . $e->getMessage());
            die("Database connection failed. Please ensure MySQL is running and chrmo_db exists.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private function createMySQLTables() {
        // Users table (matches chrmo_db.sql intent)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(50) DEFAULT 'user',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Password resets (MySQL syntax)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                reset_token_hash VARCHAR(64) NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (token_hash),
                INDEX (reset_token_hash),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Backfill column/index for existing installations (ignore if already exists)
        try {
            $this->pdo->exec("ALTER TABLE password_resets ADD COLUMN reset_token_hash VARCHAR(64) NULL AFTER token_hash");
        } catch (Throwable $e) {
            // Duplicate column errors are safe to ignore
        }
        try {
            $this->pdo->exec("CREATE INDEX idx_reset_token_hash ON password_resets(reset_token_hash)");
        } catch (Throwable $e) {
            // Duplicate index errors are safe to ignore
        }

        // Rate limits
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                action VARCHAR(50) NOT NULL,
                attempts INT DEFAULT 1,
                window_start INT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (identifier, action, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Security logs
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS security_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                user_id INT NULL,
                email VARCHAR(255) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                details TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function createSQLiteTables() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                created_at INTEGER DEFAULT (strftime('%s','now'))
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                action TEXT NOT NULL,
                attempts INTEGER DEFAULT 1,
                window_start INTEGER NOT NULL,
                created_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS security_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                user_id TEXT,
                email TEXT,
                ip_address TEXT,
                user_agent TEXT,
                details TEXT,
                created_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_token_hash ON password_resets(token_hash)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_rate_limits ON rate_limits(identifier, action, window_start)");
    }

    // SQLite helpers retained but unused since fallback is disabled
}

<?php
/**
 * Database — PDO singleton (PostgreSQL).
 *
 * Usage:
 *   $pdo = Database::getInstance()->getConnection();
 */
require_once __DIR__ . '/../../classes/SqlTranslator.php';
require_once __DIR__ . '/../../classes/PgCompat.php';

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            DB_HOST,
            defined('DB_PORT') ? DB_PORT : 5432,
            DB_NAME
        );
        $this->connection = new PgPDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}

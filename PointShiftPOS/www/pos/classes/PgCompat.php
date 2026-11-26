<?php
/**
 * PostgreSQL compatibility layer.
 *
 * - PgPDO        : PDO subclass that translates MySQL SQL before preparing/executing.
 * - MysqliCompat : drop-in replacement for `new mysqli(...)` backed by the same PDO
 *                  connection, so legacy `$conn->query()/prepare()/bind_param()` code
 *                  keeps working unchanged.
 */

// PHP builds without the mysqli extension still need these constants.
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);
if (!defined('MYSQLI_NUM'))   define('MYSQLI_NUM', 2);
if (!defined('MYSQLI_BOTH'))  define('MYSQLI_BOTH', 3);

final class DbError
{
    public static function log(string $sql, Throwable $e): void
    {
        $file = dirname(__DIR__) . '/db_postgres_errors.log';
        $msg  = '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . "\nSQL: " . $sql . "\n\n";
        @file_put_contents($file, $msg, FILE_APPEND | LOCK_EX);
    }
}

class PgPDO extends PDO
{
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->exec("SET client_encoding TO 'UTF8'");
        $this->exec("SET TIME ZONE 'Africa/Nairobi'");
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        try {
            return parent::prepare(SqlTranslator::translate($query), $options);
        } catch (Throwable $e) {
            DbError::log($query, $e);
            throw $e;
        }
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = SqlTranslator::translate($query);
        try {
            return $fetchMode === null
                ? parent::query($sql)
                : parent::query($sql, $fetchMode, ...$fetchModeArgs);
        } catch (Throwable $e) {
            DbError::log($query, $e);
            throw $e;
        }
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        $sql = SqlTranslator::translate($statement);
        try {
            return parent::exec($sql);
        } catch (Throwable $e) {
            DbError::log($statement, $e);
            throw $e;
        }
    }
}

class MysqliCompat
{
    public string  $error = '';
    public int     $errno = 0;
    public ?string $connect_error = null;
    public int     $insert_id = 0;
    public int     $affected_rows = 0;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function set_charset(string $charset): bool
    {
        return true;
    }

    public function real_escape_string(string $string): string
    {
        return str_replace("'", "''", $string);
    }

    public function escape_string(string $string): string
    {
        return $this->real_escape_string($string);
    }

    public function ping(): bool
    {
        return true;
    }

    /** @return MysqliResult|bool */
    public function query(string $sql)
    {
        $this->error = '';
        $this->errno = 0;
        try {
            $stmt = $this->pdo->query(SqlTranslator::translate($sql));
        } catch (Throwable $e) {
            DbError::log($sql, $e);
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            return false;
        }
        if ($stmt === false) {
            $this->error = 'Query failed';
            return false;
        }
        if (preg_match('/^\s*INSERT/i', $sql)) {
            try {
                $this->insert_id = (int) $this->pdo->lastInsertId();
            } catch (Throwable $e) {
                $this->insert_id = 0;
            }
        }
        $this->affected_rows = $stmt->rowCount();
        return new MysqliResult($stmt);
    }

    /** @return MysqliStmt|bool */
    public function prepare(string $sql)
    {
        $this->error = '';
        $this->errno = 0;
        try {
            return new MysqliStmt($this->pdo->prepare(SqlTranslator::translate($sql)), $this);
        } catch (Throwable $e) {
            DbError::log($sql, $e);
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            return false;
        }
    }

    public function begin_transaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }
}

class MysqliStmt
{
    public string $error = '';
    public int    $errno = 0;
    public int    $insert_id = 0;
    public int    $affected_rows = 0;
    public int    $num_rows = 0;

    private PDOStatement $stmt;
    private MysqliCompat $conn;
    private array $bound = [];
    private string $types = '';

    public function __construct(PDOStatement $stmt, MysqliCompat $conn)
    {
        $this->stmt = $stmt;
        $this->conn = $conn;
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->types = $types;
        $this->bound = [];
        $i = 0;
        foreach ($vars as &$v) {
            $this->bound[$i++] = &$v;
        }
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->error = '';
        try {
            if ($params !== null) {
                $this->stmt->execute($params);
            } else {
                foreach ($this->bound as $i => &$v) {
                    $type = $this->types[$i] ?? 's';
                    if ($v === null) {
                        $this->stmt->bindValue($i + 1, null, PDO::PARAM_NULL);
                    } elseif ($type === 'i') {
                        $this->stmt->bindValue($i + 1, (int) $v, PDO::PARAM_INT);
                    } elseif ($type === 'b') {
                        $this->stmt->bindValue($i + 1, $v, PDO::PARAM_LOB);
                    } else {
                        $this->stmt->bindValue($i + 1, (string) $v, PDO::PARAM_STR);
                    }
                }
                $this->stmt->execute();
            }
            $this->affected_rows = $this->stmt->rowCount();
            $this->num_rows      = $this->affected_rows;
            $this->conn->affected_rows = $this->affected_rows;

            if (preg_match('/^\s*INSERT/i', $this->stmt->queryString)) {
                try {
                    $this->insert_id = (int) $this->conn->getPdo()->lastInsertId();
                    $this->conn->insert_id = $this->insert_id;
                } catch (Throwable $e) {
                    $this->insert_id = 0;
                }
            }
            return true;
        } catch (Throwable $e) {
            DbError::log($this->stmt->queryString, $e);
            $this->error = $e->getMessage();
            $this->errno = (int) $e->getCode();
            return false;
        }
    }

    public function get_result(): MysqliResult
    {
        return new MysqliResult($this->stmt);
    }

    public function store_result(): bool
    {
        return true;
    }

    public function free_result(): void
    {
    }

    public function close(): bool
    {
        return true;
    }
}

class MysqliResult
{
    public int $num_rows = 0;
    public int $field_count = 0;

    private ?PDOStatement $stmt;
    private array $buffer = [];
    private int $pos = 0;
    private bool $buffered = false;

    public function __construct(?PDOStatement $stmt)
    {
        $this->stmt = $stmt;
        if ($stmt === null) {
            return;
        }
        try {
            $this->num_rows = $stmt->rowCount();
            $this->field_count = $stmt->columnCount();
        } catch (Throwable $e) {
            $this->num_rows = 0;
        }
    }

    private function pdoMode(int $mode): int
    {
        $assoc = defined('MYSQLI_ASSOC') ? MYSQLI_ASSOC : 1;
        $num   = defined('MYSQLI_NUM')   ? MYSQLI_NUM   : 2;
        return match ($mode) {
            $assoc => PDO::FETCH_ASSOC,
            $num   => PDO::FETCH_NUM,
            default => PDO::FETCH_BOTH,
        };
    }

    private function next(): array|false
    {
        if ($this->stmt === null) {
            return false;
        }
        if ($this->buffered) {
            return $this->buffer[$this->pos++] ?? false;
        }
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_assoc(): ?array
    {
        $row = $this->next();
        return $row === false ? null : $row;
    }

    public function fetch_row(): ?array
    {
        $row = $this->next();
        return $row === false ? null : array_values($row);
    }

    public function fetch_array(int $mode = 3): ?array
    {
        if ($mode === (defined('MYSQLI_ASSOC') ? MYSQLI_ASSOC : 1)) {
            return $this->fetch_assoc();
        }
        $row = $this->next();
        if ($row === false) {
            return null;
        }
        return $mode === (defined('MYSQLI_NUM') ? MYSQLI_NUM : 2)
            ? array_values($row)
            : array_merge($row, array_values($row));
    }

    public function fetch_object(): ?object
    {
        $row = $this->next();
        return $row === false ? null : (object) $row;
    }

    public function fetch_all(int $mode = 1): array
    {
        if ($this->stmt === null) {
            return [];
        }
        $rows = $this->stmt->fetchAll($this->pdoMode($mode));
        $this->num_rows = count($rows);
        return $rows;
    }

    public function fetchAll(int $mode = 2): array
    {
        if ($this->stmt === null) {
            return [];
        }
        return $this->stmt->fetchAll($mode);
    }

    public function data_seek(int $offset): bool
    {
        if (!$this->buffered && $this->stmt !== null) {
            $this->buffer = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->buffered = true;
        }
        $this->pos = $offset;
        return true;
    }

    public function free(): void
    {
        $this->buffered = true;
        $this->buffer = [];
        $this->pos = 0;
    }

    public function close(): void
    {
        $this->free();
    }
}

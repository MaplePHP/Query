<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers\PostgreSQL;

use MaplePHP\Query\Interfaces\ResultInterface;
use PgSql\Connection;
use PgSql\Result;

class PostgreSQLResult implements ResultInterface
{
    public int|string $num_rows = 0;
    private Connection $connection;
    private Result|false $query;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
       $this->connection = $connection;
    }

    /**
     * Get query
     * @param $sql
     * @return false|$this|self
     */
    public function query($sql): self|false
    {
        if($this->query = pg_query($this->connection, $sql)) {
            $this->num_rows = pg_affected_rows($this->query);
            return $this;
        }
        return false;
    }

    /**
     * Fetch the next row of a result set as an object
     * @param string $class
     * @param array $constructor_args
     * @return object|false|null
     */
    public function fetch_object(string $class = "stdClass", array $constructor_args = []): object|false|null
    {
        return pg_fetch_object($this->query, null, $class, $constructor_args);
    }

    /**
     * Fetch the next row of a result set as an associative, a numeric array, or both
     * @param int $mode Should be the database default const mode value e.g. MYSQLI_BOTH|PGSQL_BOTH|SQLITE3_BOTH
     * @return array|false|null
     */
    public function fetch_array(int $mode = PGSQL_BOTH): array|false|null
    {
        return pg_fetch_array($this->query, null, $mode);
    }

    /**
     * Fetch the next row of a result set as an associative array
     * @return array|false|null
     */
    public function fetch_assoc(): array|false|null
    {
        return pg_fetch_assoc($this->query);
    }

    /**
     * Fetch the next row of a result set as an enumerated array
     * @return array|false|null
     */
    public function fetch_row(): array|false|null
    {
        return pg_fetch_row($this->query);
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free(): void
    {
        $this->free_result();
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function close(): void
    {
        $this->free_result();
    }

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free_result(): void
    {
        pg_free_result($this->query);
    }
}

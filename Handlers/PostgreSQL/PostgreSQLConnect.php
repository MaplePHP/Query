<?php
declare(strict_types=1);

namespace MaplePHP\Query\Handlers\PostgreSQL;

use MaplePHP\Query\Exceptions\ConnectException;
use PgSql\Connection;
use PgSql\Result;

class PostgreSQLConnect
{

    public $error;

    private Connection $connection;
    private PostgreSQLResult|Result $query;

    public function __construct(string $server, string $user, string $pass, string $dbname, int $port = 5432)
    {
        if(!function_exists('pg_connect')) {
            throw new ConnectException('PostgreSQL php functions is missing and needs to be installed.', 1);
        }
        $this->connection = pg_connect("host=$server port=$port dbname=$dbname user=$user password=$pass");
        if (!$this->connection) {
            $this->error = pg_last_error();
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Returns Connection of PgSql\Connection
     * @param string $name
     * @param array $arguments
     * @return Connection|false
     */
    public function __call(string $name, array $arguments): Connection|false
    {
        return call_user_func_array([$this->connection, $name], $arguments);
    }

    /**
     * Query sql
     * @param $sql
     * @return PostgreSQLResult|bool
     */
    function query($sql): PostgreSQLResult|bool
    {
        if($this->connection instanceof Connection) {
            $this->query = new PostgreSQLResult($this->connection);
            if($query = $this->query->query($sql)) {
                return $query;
            }
            $this->error = pg_result_error($this->connection);
        }
        return false;
    }

    /**
     * Begin transaction
     * @return bool
     */
    function begin_transaction(): bool
    {
        return (bool)$this->query("BEGIN");
    }

    /**
     * Commit transaction
     * @return bool
     */
    function commit(): bool
    {
        return (bool)$this->query("COMMIT");
    }

    /**
     * Rollback transaction
     * @return bool
     */
    function rollback(): bool
    {
        return (bool)$this->query("ROLLBACK");
    }

    /**
     * Get insert ID
     * @return mixed
     */
    function insert_id(?string $column = null): int
    {
        return (int)pg_fetch_result($this->query, 0, $column);
    }

    /**
     * Close the connection
     * @return void
     */
    function close(): void
    {
        pg_close($this->connection);
    }
}

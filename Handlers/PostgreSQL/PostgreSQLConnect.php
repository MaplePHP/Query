<?php

declare(strict_types=1);

namespace MaplePHP\Query\Handlers\PostgreSQL;

use Exception;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Exceptions\ResultException;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\StmtInterface;
use PgSql\Connection;
use PgSql\Result;

class PostgreSQLConnect implements ConnectInterface
{
    public string $error = "";
    private Connection $connection;
    private PostgreSQLResult|Result $query;
    private string $key;
    private static int $index = 0;

    /**
     * @param string $server
     * @param string $user
     * @param string $pass
     * @param string $dbname
     * @param int $port
     * @throws ConnectException
     */
    public function __construct(string $server, string $user, string $pass, string $dbname, int $port = 5432)
    {
        if(!function_exists('pg_connect')) {
            throw new ConnectException('PostgreSQL php functions is missing and needs to be installed.', 1);
        }

        try {
            $this->connection = pg_connect("host=$server port=$port dbname=$dbname user=$user password=$pass");
            if (!is_null($this->connection)) {
                $this->error = pg_last_error($this->connection);
            }
        } catch (Exception $e) {
            throw new ConnectException('Failed to connect to PostgreSQL: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $this->key = "postgre_query_" . self::$index;
        self::$index++;

    }

    /**
     * Get connection
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Make a prepare statement
     * @param string $query
     * @return StmtInterface|false
     */
    public function prepare(string $query): StmtInterface|false
    {
        $index = 1;
        $query = preg_replace_callback('/\?/', function() use(&$index) {
            return '$' . $index++;
        }, $query);


        if (pg_prepare($this->connection, $this->key, $query)) {
            return new PostgreSQLStmt($this->connection, $this->key);
        }
        return false;
    }

    /**
     * Returns Connection of PgSql\Connection
     * @param string $method
     * @param array $arguments
     * @return Connection|false
     */
    public function __call(string $method, array $arguments): Connection|false
    {
        return call_user_func_array([$this->connection, $method], $arguments);
    }

    /**
     * Query sql
     * @param $query
     * @param int $result_mode
     * @return PostgreSQLResult|bool
     */
    public function query($query, int $result_mode = 0): PostgreSQLResult|bool
    {
        if($this->connection instanceof Connection) {
            $this->query = new PostgreSQLResult($this->connection);
            if($query = $this->query->query($query)) {
                return $query;
            }
            $this->error = pg_result_error($this->query);
        }
        return false;
    }

    /**
     * Begin transaction
     * @return bool
     */
    public function begin_transaction(): bool
    {
        return (bool)$this->query("BEGIN");
    }

    /**
     * Commit transaction
     * @return bool
     */
    public function commit(): bool
    {
        return (bool)$this->query("COMMIT");
    }

    /**
     * Rollback transaction
     * @return bool
     */
    public function rollback(): bool
    {
        return (bool)$this->query("ROLLBACK");
    }

    /**
     * Get insert ID
     * @return mixed
     * @throws ResultException
     */
    public function insert_id(?string $column = null): int
    {
        if(is_null($column)) {
            throw new ResultException("PostgreSQL expects a column name for a return result.");
        }
        return (int)pg_fetch_result($this->query, 0, $column);
    }

    /**
     * Close the connection
     * @return true
     */
    public function close(): true
    {
        pg_close($this->connection);
        return true;
    }

    /**
     * Prep value / SQL escape string
     * @param string $value
     * @return string
     */
    public function prep(string $value): string
    {
        return pg_escape_string($this->connection, $value);
    }
}

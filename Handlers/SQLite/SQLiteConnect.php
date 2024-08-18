<?php

namespace MaplePHP\Query\Handlers\SQLite;

use Exception;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\ConnectInterface;
use SQLite3;
use SQLite3Result;

class SQLiteConnect implements ConnectInterface
{

    public string|int $insert_id;
    public string $error;

    private SQLiteResult $query;
    private SQLite3 $connection;

    public function __construct(string $database)
    {
        try {
            $this->connection = new SQLite3($database);

        } catch (Exception $e) {
            throw new ConnectException('Failed to connect to SQLite: ' . $e->getMessage(), 1);
        }
        return $this->connection;
    }

    /**
     * Access the database main class
     * @param string $method
     * @param array $arguments
     * @return object|false
     */
    public function __call(string $method, array $arguments): SQLite3|false
    {
        return call_user_func_array([$this->connection, $method], $arguments);
    }

    /**
     * Performs a query on the database
     * @param string $query
     * @return object|false
     */
    public function query(string $query): SQLiteResult|false
    {
        $result = new SQLiteResult($this->connection);
        if($this->query = $result->query($query)) {
            return $this->query;
        }
        $this->error = $this->connection->lastErrorMsg();

        //$this->query = parent::query($query);
        return false;
    }

    /**
     * Begin transaction
     * @return bool
     */
    function begin_transaction(): bool
    {
        return (bool)$this->query("BEGIN TRANSACTION");
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
     * Returns the value generated for an AI column by the last query
     * @param string|null $column Is only used with PostgreSQL!
     * @return int
     */
    function insert_id(?string $column = null): int
    {
        return $this->connection->lastInsertRowID();
    }

}
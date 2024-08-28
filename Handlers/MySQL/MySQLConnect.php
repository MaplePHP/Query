<?php

namespace MaplePHP\Query\Handlers\MySQL;

use Exception;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\ConnectInterface;
use mysqli;
use mysqli_result;

class MySQLConnect extends mysqli implements ConnectInterface
{
    public string $error = "";

    /**
     * @param ...$args
     * @throws ConnectException
     */
    public function __construct(...$args)
    {
        try {
            parent::__construct(...$args);
        } catch (Exception $e) {
            // Make errors consistent through all the handlers
            throw new ConnectException('Failed to connect to MySQL: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Access the database main class
     * @param string $method
     * @param array $arguments
     * @return object|false
     */
    public function __call(string $method, array $arguments): object|false
    {
        return call_user_func_array([$this, $method], $arguments);
    }

    /**
     * Performs a query on the database
     * https://www.php.net/manual/en/mysqli.query.php
     * @param string $query
     * @param int $result_mode
     * @return mysqli_result|bool
     */
    function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        return parent::query($query, $result_mode);
    }

    /**
     * Returns the value generated for an AI column by the last query
     * @param string|null $column Is only used with PostgreSQL!
     * @return int
     */
    function insert_id(?string $column = null):int
    {
        return $this->insert_id;
    }

    /**
     * Close connection
     * @return bool
     */
    function close(): true
    {
        return $this->close();
    }
}
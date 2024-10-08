<?php

namespace MaplePHP\Query\Interfaces;

interface ConnectInterface
{

    /**
     * Access the database main class
     * @param string $method
     * @param array $arguments
     * @return object|false
     */
    public function __call(string $method, array $arguments): object|false;

    /**
     * Performs a query on the database
     * @param string $query
     * @return object|false
     */
    public function query(string $query): object|bool;


    /**
     * Begin transaction
     * @return bool
     */
    function begin_transaction(): bool;


    /**
     * Commit transaction
     * @return bool
     */
    function commit(): bool;

    /**
     * Rollback transaction
     * @return bool
     */
    function rollback(): bool;


    /**
     * Returns the value generated for an AI column by the last query
     * @param string|null $column Is only used with PostgreSQL!
     * @return int
     */
    function insert_id(?string $column = null): int;
}
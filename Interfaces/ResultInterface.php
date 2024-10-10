<?php

namespace MaplePHP\Query\Interfaces;

interface ResultInterface
{
    /**
     * This should pass on query to a query object
     * that can be used to get more data such as num rows
     * @param $sql
     * @return self|bool
     */
    public function query($sql): self|bool;

    /**
     * Fetch the next row of a result set as an object
     * @param string $class
     * @param array $constructor_args
     * @return object|false|null
     */
    public function fetch_object(string $class = "stdClass", array $constructor_args = []): object|null|false;

    /**
     * Fetch the next row of a result set as an associative, a numeric array, or both
     * @param int $mode Should be the database default const mode value e.g. MYSQLI_BOTH|PGSQL_BOTH|SQLITE3_BOTH
     * @return array|false|null
     */
    public function fetch_array(int $mode = 0): array|null|false;

    /**
     * Fetch the next row of a result set as an associative array
     * @return array|false|null
     */
    public function fetch_assoc(): array|null|false;

    /**
     * Fetch the next row of a result set as an enumerated array
     * @return array|false|null
     */
    public function fetch_row(): array|null|false;

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free(): void;

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function close(): void;

    /**
     * Frees the memory associated with a result
     * free() and close() are aliases for free_result()
     * @return void
     */
    public function free_result(): void;

}

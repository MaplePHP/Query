<?php

/**
 * The HandlerInterface is used for creating handlers for the Connect class
 */

namespace MaplePHP\Query\Interfaces;

use MaplePHP\Query\Exceptions\ConnectException;

interface HandlerInterface
{
    /**
     * Get database type as lover case and no spaces
     * @return string
     */
    public function getType(): string;

    /**
     * Set charset
     * @param string $charset
     */
    public function setCharset(string $charset): void;

    /**
     * Set table prefix
     * @param string $prefix
     */
    public function setPrefix(string $prefix): void;

    /**
     * Check if a connections is open
     * @return bool
     */
    public function hasConnection(): bool;

    /**
     * Connect to database
     * @return ConnectInterface
     * @throws ConnectException
     */
    public function execute(): ConnectInterface;


    /**
     * Get selected database name
     * @return string
     */
    public function getDBName(): string;

    /**
     * Get current Character set
     * @return string|null
     */
    public function getCharSetName(): ?string;

    /**
     * Get current table prefix
     * @return string
     */
    public function getPrefix(): string;

    /**
     * Query sql string
     * @param  string $sql
     * @return object|array|bool
     */
    public function query(string $sql): object|array|bool;

    /**
     * Close MySQL Connection
     * @return void
     */
    public function close(): void;

    /**
     * Protect/prep database values from injections
     * @param  string $value
     * @return string
     */
    public function prep(string $value): string;

    /**
     * Execute multiple queries at once (e.g. from a sql file)
     * @param  string $sql
     * @param  object|null &$db
     * @return array
     */
    public function multiQuery(string $sql, object &$db = null): array;

}

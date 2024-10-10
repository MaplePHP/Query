<?php

declare(strict_types=1);

namespace MaplePHP\Query\Handlers\PostgreSQL;

use MaplePHP\Query\Interfaces\ResultInterface;
use MaplePHP\Query\Interfaces\StmtInterface;
use PgSql\Connection;
use PgSql\Result;

class PostgreSQLStmt implements StmtInterface
{
    private Connection $connection;
    private Result|false $result;
    private string $key;
    private bool $success = false;

    /**
     * Do mysqli Stmt with PostgreSQL
     * @param Connection $connection
     * @param string $key
     */
    public function __construct(Connection $connection, string $key)
    {
        $this->connection = $connection;
        $this->key = $key;
    }

    /**
     * Binds variables to a prepared statement as parameters
     * https://www.php.net/manual/en/mysqli-stmt.bind-param.php
     * @param string $types
     * @param mixed $var
     * @param mixed ...$vars
     * @return bool
     */
    public function bind_param(string $types, mixed &$var, mixed &...$vars): bool
    {
        $params = array_merge([$var], $vars);
        $this->result = pg_execute($this->connection, $this->key, $params);
        $this->success = ($this->result !== false);
        return $this->success;
    }

    /**
     * Executes a prepared statement
     * Not really needed in PostgreSQL but added as a placeholder
     * https://www.php.net/manual/en/mysqli-stmt.execute.php
     * @return bool
     */
    public function execute(): bool
    {
        return $this->success;
    }

    /**
     * Gets a result set from a prepared statement as a ResultInterface object
     * https://www.php.net/manual/en/mysqli-stmt.get-result.php
     * @return ResultInterface
     */
    public function get_result(): ResultInterface
    {
        return new PostgreSQLResult($this->connection, $this->result);
    }

    /**
     * Closes a prepared statement
     * https://www.php.net/manual/en/mysqli-stmt.close.php
     * @return true
     */
    public function close(): true
    {
        pg_query($this->connection, "DEALLOCATE $this->key");
        return true;
    }

}

<?php

declare(strict_types=1);

namespace MaplePHP\Query\Handlers\SQLite;

use MaplePHP\Query\Handlers\PostgreSQL\PostgreSQLResult;
use MaplePHP\Query\Interfaces\ResultInterface;
use MaplePHP\Query\Interfaces\StmtInterface;
use SQLite3;
use SQLite3Stmt;
use SQLite3Result;

class SQLiteStmt implements StmtInterface
{
    private SQLite3 $connection;
    private SQLite3Result|false $result;
    private SQLite3Stmt $stmt;

    /**
     * Do mysqli Stmt with PostgreSQL
     * @param SQLite3 $connection
     * @param SQLite3Stmt $stmt
     */
    public function __construct(SQLite3 $connection, SQLite3Stmt $stmt)
    {
        $this->connection = $connection;
        $this->stmt = $stmt;
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
        foreach($params as $key => $value) {
            if(!$this->stmt->bindValue(($key + 1), $params[0], SQLITE3_TEXT)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Executes a prepared statement
     * Not really needed in PostgreSQL but added as a placeholder
     * https://www.php.net/manual/en/mysqli-stmt.execute.php
     * @return bool
     */
    public function execute(): bool
    {
        $this->result = $this->stmt->execute();
        return ($this->result !== false);
    }

    /**
     * Gets a result set from a prepared statement as a ResultInterface object
     * https://www.php.net/manual/en/mysqli-stmt.get-result.php
     * @return ResultInterface
     */
    public function get_result(): ResultInterface
    {
        return new SQLiteResult($this->connection, $this->result);
    }

    /**
     * Closes a prepared statement
     * https://www.php.net/manual/en/mysqli-stmt.close.php
     * @return true
     */
    public function close(): true
    {
        $this->stmt->close();
        return true;
    }

}

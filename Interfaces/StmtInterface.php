<?php

namespace MaplePHP\Query\Interfaces;

interface StmtInterface
{
    /**
     * Binds variables to a prepared statement as parameters
     * https://www.php.net/manual/en/mysqli-stmt.bind-param.php
     * @param string $types
     * @param mixed $var
     * @param mixed ...$vars
     * @return bool
     */
    public function bind_param(string $types, mixed &$var, mixed &...$vars): bool;

    /**
     * Executes a prepared statement
     * https://www.php.net/manual/en/mysqli-stmt.execute.php
     * @return bool
     */
    public function execute(): bool;

    /**
     * Gets a result set from a prepared statement as a ResultInterface object
     * https://www.php.net/manual/en/mysqli-stmt.get-result.php
     * @return ResultInterface
     */
    public function get_result(): ResultInterface;

    /**
     * Closes a prepared statement
     * https://www.php.net/manual/en/mysqli-stmt.close.php
     * @return true
     */
    public function close(): true;

}

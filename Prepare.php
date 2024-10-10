<?php

declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\DBInterface;

class Prepare
{
    private DBInterface $query;
    private array $statements = [];
    private $stmt;
    private string $sql;
    private ?string $keys = null;

    /**
     * Initiate prepare class
     * @param DBInterface $db
     */
    public function __construct(DBInterface $db)
    {
        $this->query = $db;
        $this->sql = $db->prepare()->sql();
        $this->stmt = $db->getConnection()->prepare($this->sql);
    }

    /**
     * Access DB -> and Query
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        $query = $this->prepExecute();
        if(!method_exists($query, $name)) {
            throw new \BadMethodCallException("The method '$name' does not exist in " . get_class($query) . ".");
        }
        return $query->$name(...$arguments);
    }

    /**
     * @param DBInterface $db
     * @return void
     */
    public function bind(DBInterface $db): void
    {
        $this->statements[0] = $db->prepare();
    }

    /**
     * Combine multiple
     * This is a test and might be removed in future
     * @param DBInterface $db
     * @return void
     */
    public function combine(DBInterface $db): void
    {
        $this->statements[] = $db->prepare();
    }

    /**
     * Get SQL code
     * @return string
     */
    public function sql(): string
    {
        return $this->sql;
    }

    /**
     * Get STMT
     * @return mixed
     */
    public function getStmt()
    {
        return $this->stmt;
    }

    /**
     * Get bound keys
     * @param int $length
     * @return string
     */
    public function getKeys(int $length): string
    {
        if(is_null($this->keys)) {
            $this->keys = str_pad("", $length, "s");
        }
        return $this->keys;
    }

    /**
     * This will the rest of the library that it expects a prepended call
     * @return Query
     */
    private function prepExecute(): Query
    {
        return $this->query->bind($this, $this->statements);
    }

    /**
     * Execute
     * @return array|bool|object
     * @throws ConnectException
     */
    function execute(): object|bool|array
    {
        $query = $this->prepExecute();
        return $query->execute();
    }

}

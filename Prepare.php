<?php
declare(strict_types=1);

namespace MaplePHP\Query;

use MaplePHP\Query\Interfaces\DBInterface;

class Prepare
{

    private DBInterface $query;
    private array $statements = [];

    public function __call(string $name, array $arguments): mixed
    {
        $query = $this->prepExecute();
        if(!method_exists($query, $name)) {
            throw new \BadMethodCallException("The method '$name' does not exist in " . get_class($query) . ".");
        }
        return $query->$name(...$arguments);
    }

    public function query(DBInterface $db): void
    {
        $this->query = $db;
        $this->statements[] = $db->prepare();
    }

    private function prepExecute(): ?Query
    {
        $this->query->sql(); // Will build the query
        return $this->query->bind($this->statements);
    }

}
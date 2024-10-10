<?php

declare(strict_types=1);

namespace MaplePHP\Query;

use BadMethodCallException;
use InvalidArgumentException;
use MaplePHP\Query\Exceptions\ConnectException;
use MaplePHP\Query\Interfaces\ConnectInterface;
use MaplePHP\Query\Interfaces\DBInterface;

class Query
{
    private string|DBInterface $sql;
    private mixed $stmt; // Will become ConnectInterface
    private ?array $bind = null;
    private ?string $pluck = null;
    private ConnectInterface $connection;

    public function __construct(ConnectInterface $connection, string|DBInterface $sql)
    {
        $this->sql = $sql;
        if ($sql instanceof DBInterface) {
            $this->sql = $sql->sql();
        }
        $this->connection = $connection;
    }

    public function setPluck(?string $pluck): void
    {
        $this->pluck = $pluck;
    }

    public function bind($stmt, array $set): self
    {
        $this->stmt = $stmt;
        $this->bind = $set;
        return $this;
    }

    /**
     * Execute query result
     * @return object|array|bool
     * @throws ConnectException
     */
    public function execute(): object|array|bool
    {
        if(!is_null($this->bind)) {
            return $this->executePrepare();
        }

        if ($result = $this->connection->query($this->sql)) {
            return $result;
        } else {
            throw new ConnectException($this->connection->DB()->error, 1);
        }
    }

    /**
     * Execute prepared query
     * @return object|array|bool
     */
    public function executePrepare(): object|array|bool
    {
        if(is_null($this->bind)) {
            throw new BadMethodCallException("You need to bind parameters first to execute a prepare statement!");
        }
        foreach ($this->bind as $bind) {
            $ref = $bind->getQueryBuilder()->getSet();
            $length = count($ref);
            if($length > 0) {
                $this->stmt->getStmt()->bind_param($this->stmt->getKeys($length), ...$ref);
            }
            $this->stmt->getStmt()->execute();
        }
        //$this->stmt->getStmt()->close();
        return $this->stmt->getStmt()->get_result();
    }

    /**
     * Execute query result And fetch as object
     * @return bool|object|string
     * @throws ConnectException
     */
    public function get(): bool|object|string
    {
        return $this->obj();
    }

    /**
     * SAME AS @get(): Execute query result And fetch as object
     * @return bool|object|string (Mysql result)
     * @throws ConnectException
     */
    final public function obj(string $class = "stdClass", array $constructor_args = []): bool|object|string
    {
        $result = $this->execute();
        if (is_object($result) && $result->num_rows > 0) {
            $obj = $result->fetch_object($class, $constructor_args);
            if(!is_null($this->pluck)) {
                $obj = $obj->{$this->pluck};
            }
            return $obj;
        }
        return false;
    }

    /**
     * Execute SELECT and fetch as array with nested objects
     * @param callable|null $callback callback, make changes in query and if return then change key
     * @param string $class
     * @param array $constructor_args
     * @return array
     * @throws ConnectException
     */
    final public function fetch(?callable $callback = null, string $class = "stdClass", array $constructor_args = []): array
    {
        $arr = [];
        $result = $this->execute();
        if (is_array($result)) {
            foreach($result as $resultItem) {
                $arr = array_merge($arr, $this->fetchItem($resultItem, $callback, $class, $constructor_args));
            }
        } else {
            $arr = $this->fetchItem($result, $callback, $class, $constructor_args);
        }
        return $arr;
    }

    /**
     * fetch an item to be used in the main fetch method
     * @param $result
     * @param callable|null $callback
     * @param string $class
     * @param array $constructor_args
     * @return array
     */
    protected function fetchItem($result, ?callable $callback = null, string $class = "stdClass", array $constructor_args = []): array
    {
        $key = 0;
        $select = null;
        $arr = [];
        if (is_object($result) && $result->num_rows > 0) {
            while ($row = $result->fetch_object($class, $constructor_args)) {

                if(!is_null($this->pluck)) {
                    $row = $row->{$this->pluck};
                }

                if ($callback) {
                    $select = $callback($row, $key);
                }
                $data = ((!is_null($select)) ? $select : $key);
                if (is_array($data)) {
                    if (!is_array($select)) {
                        throw new InvalidArgumentException("The return value of the callable needs to be an array!", 1);
                    }
                    $arr = array_replace_recursive($arr, $select);
                } else {
                    $arr[$data] = $row;
                }
                $key++;
            }
        }
        return $arr;
    }

    /**
     * Get insert AI ID from prev inserted result
     * @return string|int
     * @throws ConnectException
     */
    public function insertID(): string|int
    {
        return $this->connection->DB()->insert_id;
    }
}
